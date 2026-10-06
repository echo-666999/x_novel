<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Data\GenerationFailure;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GenerationFailurePolicy
{
    /** 冻结配置本身导致的失败，原批次 Resume 不可能读取修正后的新配置。 */
    private const FROZEN_RESUME_BLOCKED_CATEGORIES = [
        'provider_configuration',
        'reasoning_budget_exhausted',
        'visible_output_truncated',
        'completion_budget_exhausted',
    ];

    private const LEGACY_RETRYABLE_CODES = [
        'provider_timeout',
        'provider_connection_failed',
        'provider_rate_limited',
        'scene_stage_deferred',
        'generation_stage_deferred',
    ];

    public function fromException(Throwable $exception, string $fallbackCode): GenerationFailure
    {
        $code = $exception instanceof AiProviderException ? $exception->errorCode : $fallbackCode;
        $retryable = $exception instanceof AiProviderException
            ? $exception->retryable
            : $exception instanceof QueryException;
        $status = $exception instanceof AiProviderException ? $exception->statusCode : null;
        $requestId = $exception instanceof AiProviderException ? $exception->providerRequestId : null;

        return new GenerationFailure(
            code: $code,
            message: $exception->getMessage(),
            retryable: $retryable,
            metadata: array_filter([
                'category' => $this->category($code, $status, $exception),
                'http_status' => $status,
                'provider_request_id' => $requestId,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            recommendedAction: $this->recommendedAction($code, $retryable, $status),
        );
    }

    /** @return array{max_attempts: int, backoff: array<int, int>, repairs: array<int, string>, non_terminal_codes: array<int, string>} */
    public function stagePolicy(GenerationStage $stage): array
    {
        $policy = (array) config("generation.stage_policies.{$stage->value}", []);

        return [
            'max_attempts' => max(1, (int) ($policy['max_attempts'] ?? 1)),
            'backoff' => array_values(array_map('intval', (array) ($policy['backoff'] ?? []))),
            'repairs' => array_values(array_filter((array) ($policy['repairs'] ?? []), 'is_string')),
            'non_terminal_codes' => array_values(array_filter((array) ($policy['non_terminal_codes'] ?? []), 'is_string')),
        ];
    }

    public function maxAttempts(GenerationStage $stage): int
    {
        return $this->stagePolicy($stage)['max_attempts'];
    }

    /** @return array<int, int> */
    public function backoff(GenerationStage $stage): array
    {
        return $this->stagePolicy($stage)['backoff'];
    }

    public function allowsRepair(GenerationStage $stage, string $repair): bool
    {
        return in_array($repair, $this->stagePolicy($stage)['repairs'], true);
    }

    /** Queue retries only temporary infrastructure/provider faults for the same stage. */
    public function shouldQueueRetry(Throwable $exception, GenerationStage $stage): bool
    {
        $failure = $this->fromException($exception, "{$stage->value}_failed");

        return $failure->retryable
            && in_array($failure->metadata['category'] ?? null, ['external_temporary', 'infrastructure_temporary', 'visible_output_truncated'], true);
    }

    public function shouldMarkTerminal(GenerationStage $stage, ?Throwable $exception): bool
    {
        if (! $exception instanceof AiProviderException) {
            return true;
        }

        return ! in_array($exception->errorCode, $this->stagePolicy($stage)['non_terminal_codes'], true);
    }

    public function forRun(GenerationRun $run): GenerationFailure
    {
        $code = (string) ($run->error_code ?: 'generation_failed');
        $metadata = is_array($run->error_metadata) ? $run->error_metadata : [];
        $status = is_numeric($metadata['http_status'] ?? null) ? (int) $metadata['http_status'] : null;
        $retryable = $run->error_retryable ?? $this->legacyRetryable($code, $status);

        return new GenerationFailure(
            code: $code,
            message: (string) ($run->error_message ?? ''),
            retryable: $retryable,
            metadata: $metadata + ['category' => $this->category($code, $status)],
            recommendedAction: $this->recommendedAction($code, $retryable, $status),
        );
    }

    public function record(GenerationRun $run, Throwable $exception, string $fallbackCode): GenerationFailure
    {
        $failure = $this->fromException($exception, $fallbackCode);
        $sourceArtifactId = data_get($run->context_snapshot, 'source_artifact_id')
            ?? data_get($run->context_snapshot, 'draft.artifact_id')
            ?? data_get($run->context_snapshot, 'chapter_draft.artifact_id');
        $metadata = $failure->metadata + array_filter([
            'stage' => $run->stage->value,
            'input_hash' => $run->input_hash,
            'source_artifact_id' => is_numeric($sourceArtifactId) ? (int) $sourceArtifactId : null,
            'next_action' => $failure->recommendedAction,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $run->update([
            'status' => RunStatus::Failed,
            'error_code' => $failure->code,
            'error_message' => $failure->message,
            'error_retryable' => $failure->retryable,
            'error_metadata' => $metadata,
            'finished_at' => now(),
        ]);

        return new GenerationFailure(
            code: $failure->code,
            message: $failure->message,
            retryable: $failure->retryable,
            metadata: $metadata,
            recommendedAction: $failure->recommendedAction,
        );
    }

    public function legacyRetryable(string $code, ?int $status = null): bool
    {
        if ($code === StalledRunRecoveryService::ERROR_CODE || $code === 'worker_interrupted') {
            return false;
        }

        return in_array($code, self::LEGACY_RETRYABLE_CODES, true)
            || ($code === 'provider_request_failed' && $status !== null && $status >= 500);
    }

    public function allowsFrozenResume(GenerationRun $run): bool
    {
        $metadata = is_array($run->error_metadata) ? $run->error_metadata : [];
        $status = is_numeric($metadata['http_status'] ?? null) ? (int) $metadata['http_status'] : null;
        $category = $this->category((string) ($run->error_code ?: 'generation_failed'), $status);

        return ! in_array($category, self::FROZEN_RESUME_BLOCKED_CATEGORIES, true);
    }

    private function category(string $code, ?int $status = null, ?Throwable $exception = null): string
    {
        if ($code === 'outline_worker_contract_mismatch') {
            return 'worker_version_mismatch';
        }
        if ($code === StalledRunRecoveryService::ERROR_CODE || $code === 'worker_interrupted') {
            return 'worker_lost';
        }
        if (in_array($code, ['scene_stage_deferred', 'generation_stage_deferred'], true)) {
            return 'workflow_deferred';
        }
        if (str_contains($code, 'reasoning_budget_exhausted')) {
            return 'reasoning_budget_exhausted';
        }
        if (str_contains($code, 'output_truncated')) {
            return 'visible_output_truncated';
        }
        if (str_contains($code, 'completion_budget_exhausted')) {
            return 'completion_budget_exhausted';
        }
        if ($status === 401 || $status === 403 || in_array($code, ['provider_not_configured', 'provider_disabled', 'provider_unsupported', 'provider_authentication_failed', 'provider_run_mismatch', 'provider_run_missing', 'provider_run_route_missing', 'outline_route_not_configured', 'model_run_mismatch'], true)) {
            return 'provider_configuration';
        }
        if ($this->legacyRetryable($code, $status) || $exception instanceof QueryException) {
            return $exception instanceof QueryException ? 'infrastructure_temporary' : 'external_temporary';
        }
        if ($status === 400 || str_contains($code, 'schema') || str_contains($code, 'structured_output') || str_contains($code, 'truncated') || str_contains($code, 'invalid_json') || str_contains($code, 'output_budget_exhausted') || str_contains($code, 'evidence')) {
            return 'structured_output';
        }
        if (str_contains($code, 'state_version') || str_starts_with($code, 'stale_') || str_contains($code, 'artifact_conflict')) {
            return 'state_conflict';
        }
        if ($exception instanceof ValidationException || str_contains($code, 'validation') || str_contains($code, 'plan_violation') || str_contains($code, 'planning')) {
            return 'domain_validation';
        }

        return 'manual_attention';
    }

    private function recommendedAction(string $code, bool $retryable, ?int $status): string
    {
        if ($code === 'outline_worker_contract_mismatch') {
            return '重启 Horizon 后继续';
        }
        if ($code === StalledRunRecoveryService::ERROR_CODE) {
            return '恢复';
        }
        if ($code === 'worker_interrupted' || in_array($code, ['scene_stage_deferred', 'generation_stage_deferred'], true)) {
            return '继续执行';
        }
        if (str_contains($code, 'reasoning_budget_exhausted')) {
            return '增加推理预留或降低推理程度';
        }
        if (str_contains($code, 'output_truncated')) {
            return '调整模型路由或输出预算';
        }
        if (str_contains($code, 'completion_budget_exhausted')) {
            return '检查 Usage 并调整请求预算';
        }
        if ($retryable) {
            return '重试';
        }
        if ($status === 401 || $status === 403 || in_array($code, ['provider_not_configured', 'provider_disabled', 'provider_unsupported', 'provider_authentication_failed', 'provider_run_mismatch', 'provider_run_missing', 'provider_run_route_missing', 'outline_route_not_configured', 'model_run_mismatch'], true)) {
            return '修复 AI 配置';
        }
        if (str_contains($code, 'output_budget_exhausted')) {
            return '调整模型路由或输出预算';
        }
        if ($status === 400 || str_contains($code, 'schema') || str_contains($code, 'structured_output') || str_contains($code, 'truncated')) {
            return '检查请求结构或输出预算';
        }
        if (str_contains($code, 'state_version') || str_starts_with($code, 'stale_') || str_contains($code, 'artifact_conflict')) {
            return '重建 Context 后重试';
        }
        if ($code === 'novel_paused') {
            return '恢复小说后继续';
        }
        if (str_starts_with($code, 'budget_')) {
            return '调整预算设置';
        }

        return '检查输入并人工处理';
    }
}
