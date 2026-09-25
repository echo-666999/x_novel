<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Data\GenerationFailure;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GenerationFailurePolicy
{
    private const LEGACY_RETRYABLE_CODES = [
        'provider_timeout',
        'provider_connection_failed',
        'provider_rate_limited',
        'scene_stage_deferred',
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
        $run->update([
            'status' => RunStatus::Failed,
            'error_code' => $failure->code,
            'error_message' => $failure->message,
            'error_retryable' => $failure->retryable,
            'error_metadata' => $failure->metadata,
            'finished_at' => now(),
        ]);

        return $failure;
    }

    public function legacyRetryable(string $code, ?int $status = null): bool
    {
        if ($code === StalledRunRecoveryService::ERROR_CODE || $code === 'worker_interrupted') {
            return false;
        }

        return in_array($code, self::LEGACY_RETRYABLE_CODES, true)
            || ($code === 'provider_request_failed' && $status !== null && $status >= 500);
    }

    private function category(string $code, ?int $status = null, ?Throwable $exception = null): string
    {
        if ($code === StalledRunRecoveryService::ERROR_CODE || $code === 'worker_interrupted') {
            return 'worker_lost';
        }
        if ($code === 'scene_stage_deferred') {
            return 'workflow_deferred';
        }
        if ($status === 401 || $status === 403 || in_array($code, ['provider_not_configured', 'provider_disabled', 'provider_unsupported', 'provider_authentication_failed', 'provider_run_mismatch', 'provider_run_route_missing', 'model_run_mismatch'], true)) {
            return 'provider_configuration';
        }
        if ($this->legacyRetryable($code, $status) || $exception instanceof QueryException) {
            return $exception instanceof QueryException ? 'infrastructure_temporary' : 'external_temporary';
        }
        if ($status === 400 || str_contains($code, 'schema') || str_contains($code, 'truncated') || str_contains($code, 'invalid_json') || str_contains($code, 'output_budget_exhausted')) {
            return 'structured_output';
        }
        if (str_contains($code, 'state_version') || str_starts_with($code, 'stale_') || str_contains($code, 'artifact_conflict')) {
            return 'state_conflict';
        }
        if ($exception instanceof ValidationException || str_contains($code, 'validation') || str_contains($code, 'plan_violation')) {
            return 'domain_validation';
        }

        return 'manual_attention';
    }

    private function recommendedAction(string $code, bool $retryable, ?int $status): string
    {
        if ($code === StalledRunRecoveryService::ERROR_CODE) {
            return '恢复';
        }
        if ($code === 'worker_interrupted' || $code === 'scene_stage_deferred') {
            return '继续执行';
        }
        if ($retryable) {
            return '重试';
        }
        if ($status === 401 || $status === 403 || in_array($code, ['provider_not_configured', 'provider_disabled', 'provider_unsupported', 'provider_authentication_failed', 'provider_run_mismatch', 'provider_run_route_missing', 'model_run_mismatch'], true)) {
            return '修复 AI 配置';
        }
        if (str_contains($code, 'output_budget_exhausted')) {
            return '调整模型路由或输出预算';
        }
        if ($status === 400 || str_contains($code, 'schema') || str_contains($code, 'truncated')) {
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
