<?php

namespace App\Services;

use App\AI\CompletionLimitClassifier;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Models\GenerationRun;
use Illuminate\Validation\ValidationException;

/**
 * 统一解析并校验生成请求预算，避免 Admission 与实际请求各自解释同一份配置。
 */
final class GenerationRequestBudget
{
    /**
     * 解析指定 AI 路由下各修复子阶段的独立预算，避免子请求借用父阶段的大预算上限。
     *
     * @return array<string, array<string, array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}>>
     */
    public function configuredRepairs(AiStage $stage): array
    {
        $configured = config("generation.chapter_repair_request_budgets.{$stage->value}", []);
        if (! is_array($configured)) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[REPAIR_REQUEST_BUDGET_INVALID] {$stage->getLabel()} 的修复子阶段预算必须是数组。",
            ]);
        }

        $budgets = [];
        foreach ($configured as $substage => $tiers) {
            if (! is_string($substage) || $substage === '' || ! is_array($tiers)) {
                throw ValidationException::withMessages([
                    "routes.{$stage->value}" => "[REPAIR_REQUEST_BUDGET_INVALID] {$stage->getLabel()} 包含无效的修复子阶段预算键。",
                ]);
            }

            $budgets[$substage] = $this->normalizeConfiguredTiers($tiers, $stage, "修复子阶段 {$substage}");
        }

        return $budgets;
    }

    /**
     * @return array<string, array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}>
     */
    public function configured(AiStage $stage): array
    {
        $configured = config("generation.chapter_request_budgets.{$stage->value}");
        if (! is_array($configured) || $configured === []) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[REQUEST_BUDGET_NOT_CONFIGURED] {$stage->getLabel()} 缺少请求预算配置。",
            ]);
        }

        return $this->normalizeConfiguredTiers($configured, $stage, $stage->getLabel());
    }

    /**
     * 将配置中的可见输出和推理预留规范化为 Provider 实际接收的完成预算。
     *
     * @param  array<string, mixed>  $configured
     * @return array<string, array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}>
     */
    private function normalizeConfiguredTiers(array $configured, AiStage $stage, string $label): array
    {
        $budgets = [];
        $previousOutput = 0;
        $previousReasoningReserve = 0;
        foreach ($configured as $tier => $budget) {
            $outputTokens = is_array($budget) ? (int) ($budget['output_tokens'] ?? 0) : 0;
            $reasoningReserve = is_array($budget) ? (int) ($budget['reasoning_reserve_tokens'] ?? -1) : -1;
            if ($outputTokens < 1 || $reasoningReserve < 0) {
                throw ValidationException::withMessages([
                    "routes.{$stage->value}" => "[REQUEST_BUDGET_INVALID] {$label} 的 {$tier} 请求预算必须包含正数可见输出额度和非负推理预留。",
                ]);
            }

            if ($outputTokens < $previousOutput || $reasoningReserve < $previousReasoningReserve) {
                throw ValidationException::withMessages([
                    "routes.{$stage->value}" => "[REQUEST_BUDGET_INVALID] {$label} 的 {$tier} 可见输出额度和推理预留都不能低于前一档预算。",
                ]);
            }

            $maximum = $outputTokens + $reasoningReserve;
            // Provider 的完成上限必须同时容纳隐藏推理和可见输出，运行阶段只能读取这里冻结后的统一结构。
            $budgets[(string) $tier] = [
                'output_tokens' => $outputTokens,
                'reasoning_reserve_tokens' => $reasoningReserve,
                'max_completion_tokens' => $maximum,
            ];
            $previousOutput = $outputTokens;
            $previousReasoningReserve = $reasoningReserve;
        }

        return $budgets;
    }

    /**
     * 从 Run 冻结快照读取一个修复子阶段的全部分档，旧整数预算不得继续冒充完整合同。
     *
     * @param  array<string, mixed>  $repairBudgets
     * @return array<string, array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}>
     */
    public function repairTiers(array $repairBudgets, string $substage, AiStage $stage): array
    {
        $tiers = $repairBudgets[$substage] ?? null;
        if (! is_array($tiers)) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[REPAIR_REQUEST_BUDGET_MISSING] {$stage->getLabel()} 缺少 {$substage} 的冻结修复预算。",
            ]);
        }

        return $this->validateFrozen($tiers, $stage);
    }

    /**
     * 读取修复子阶段的精确档位，调用方必须把该三元组传给容量门禁。
     *
     * @param  array<string, mixed>  $repairBudgets
     * @return array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}
     */
    public function repairTier(array $repairBudgets, string $substage, string $tier, AiStage $stage): array
    {
        return $this->tier($this->repairTiers($repairBudgets, $substage, $stage), $tier, $stage);
    }

    /**
     * @param  array<string, mixed>  $budgets
     * @return array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}
     */
    public function tier(array $budgets, string $tier, AiStage $stage): array
    {
        $budget = $budgets[$tier] ?? null;
        $output = is_array($budget) ? (int) ($budget['output_tokens'] ?? 0) : 0;
        $reserve = is_array($budget) ? (int) ($budget['reasoning_reserve_tokens'] ?? -1) : -1;
        $maximum = is_array($budget) ? (int) ($budget['max_completion_tokens'] ?? 0) : 0;

        if ($output < 1 || $reserve < 0 || $maximum !== $output + $reserve) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[REQUEST_BUDGET_INVALID] {$stage->getLabel()} 缺少合法的 {$tier} 冻结请求预算。",
            ]);
        }

        return [
            'output_tokens' => $output,
            'reasoning_reserve_tokens' => $reserve,
            'max_completion_tokens' => $maximum,
        ];
    }

    /** @param array<string, mixed> $budgets */
    public function maximum(array $budgets): int
    {
        return (int) collect($budgets)->max(
            fn (mixed $budget): int => is_array($budget) ? (int) ($budget['max_completion_tokens'] ?? 0) : 0,
        );
    }

    /**
     * 根据同一幂等链中最后一次完成预算故障选择下一档；普通超时等临时错误必须继续使用原档预算。
     *
     * @param  iterable<int, GenerationRun>  $priorRuns
     * @return array{tier: string, ordinal: int, trigger: string|null, budget: array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}}
     */
    public function resolveForAttempt(array $budgets, AiStage $stage, iterable $priorRuns, string $errorPrefix, string $label): array
    {
        $tiers = $this->validateFrozen($budgets, $stage);
        $failures = collect($priorRuns)
            ->filter(fn (GenerationRun $run): bool => $this->matchesStageError((string) $run->error_code, $errorPrefix)
                && $this->completionLimitCategory((string) $run->error_code) !== null)
            ->values();

        if ($failures->isEmpty()) {
            $tier = (string) array_key_first($tiers);

            return [
                'tier' => $tier,
                'ordinal' => 1,
                'trigger' => null,
                'budget' => $tiers[$tier],
            ];
        }

        /** @var GenerationRun $lastFailure */
        $lastFailure = $failures->last();
        $category = $this->completionLimitCategory((string) $lastFailure->error_code);
        $current = $this->budgetFromRun($lastFailure, $tiers);
        $currentTier = data_get($lastFailure->context_snapshot, 'generation_preferences.request_budget_tier');
        $candidate = $this->nextHigherTier($tiers, $current, $category, is_string($currentTier) ? $currentTier : null);

        if ($candidate === null) {
            throw $this->terminalCompletionLimitException(
                (string) $lastFailure->error_code,
                $category,
                $label,
                $current,
            );
        }

        return [
            'tier' => $candidate['tier'],
            'ordinal' => $failures->count() + 1,
            'trigger' => $category,
            'budget' => $candidate['budget'],
        ];
    }

    /**
     * 只有冻结预算中存在针对当前耗尽类型的更高一档，当前异常才允许进入 Queue Retry。
     *
     * @param  array<string, mixed>  $budgets
     * @param  array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}  $current
     */
    public function classifyRetry(
        AiProviderException $exception,
        array $budgets,
        AiStage $stage,
        array $current,
        string $errorPrefix,
        string $label,
        ?string $currentTier = null,
        ?AiResponse $response = null,
    ): AiProviderException {
        if (! $this->matchesStageError($exception->errorCode, $errorPrefix)) {
            return $exception;
        }

        $category = $this->completionLimitCategory($exception->errorCode);
        if ($category === null) {
            return $exception;
        }

        [$category, $exception] = $this->classifyAgainstFrozenBudget(
            $category,
            $exception,
            $current,
            $errorPrefix,
            $label,
            $response,
        );

        $tiers = $this->validateFrozen($budgets, $stage);
        if ($this->nextHigherTier($tiers, $current, $category, $currentTier) !== null) {
            return new AiProviderException(
                $exception->errorCode,
                $exception->getMessage(),
                true,
                $exception->statusCode,
                $exception,
                $exception->providerRequestId,
            );
        }

        return $this->terminalCompletionLimitException(
            $exception->errorCode,
            $category,
            $label,
            $current,
            $exception,
        );
    }

    /**
     * Provider 只知道总完成预算；阶段合同同时知道推理预留，二者结合后才能判断少量可见内容是否被推理挤占。
     *
     * @param  array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}  $current
     * @return array{0: string, 1: AiProviderException}
     */
    private function classifyAgainstFrozenBudget(
        string $category,
        AiProviderException $exception,
        array $current,
        string $errorPrefix,
        string $label,
        ?AiResponse $response,
    ): array {
        if ($response === null || data_get($response->metadata, 'finish_reason') !== 'length') {
            return [$category, $exception];
        }

        $reasoningTokens = max(0, $response->reasoningTokens);
        $reasoningReserve = max(0, (int) $current['reasoning_reserve_tokens']);
        $visibleTokens = max(0, $response->outputTokens - $reasoningTokens);
        if ($reasoningTokens > $reasoningReserve) {
            $message = "AI 的 {$label} 请求在完成前耗尽总预算；实际推理消耗 {$reasoningTokens} Token，超过本档冻结推理预留 {$reasoningReserve} Token，仅留下约 {$visibleTokens} Token 可见输出。未保存不完整结果。";

            return [
                CompletionLimitClassifier::REASONING_BUDGET_EXHAUSTED,
                new AiProviderException(
                    $errorPrefix.'_reasoning_budget_exhausted',
                    $message,
                    false,
                    $exception->statusCode,
                    $exception,
                    $response->providerRequestId ?? $exception->providerRequestId,
                ),
            ];
        }

        if (trim($response->content) !== '') {
            return [
                CompletionLimitClassifier::VISIBLE_OUTPUT_TRUNCATED,
                new AiProviderException(
                    $errorPrefix.'_output_truncated',
                    "AI 已开始返回 {$label}，实际推理消耗 {$reasoningTokens} Token 未超过冻结预留 {$reasoningReserve} Token，但可见输出仍在完成前耗尽请求预算；未保存不完整结果。",
                    false,
                    $exception->statusCode,
                    $exception,
                    $response->providerRequestId ?? $exception->providerRequestId,
                ),
            ];
        }

        return [$category, $exception];
    }

    public function completionLimitCategory(string $errorCode): ?string
    {
        if (str_contains($errorCode, 'reasoning_budget_exhausted')) {
            return CompletionLimitClassifier::REASONING_BUDGET_EXHAUSTED;
        }

        if (str_contains($errorCode, 'output_truncated') || str_contains($errorCode, 'output_budget_exhausted')) {
            return CompletionLimitClassifier::VISIBLE_OUTPUT_TRUNCATED;
        }

        if (str_contains($errorCode, 'completion_budget_exhausted')) {
            return CompletionLimitClassifier::COMPLETION_BUDGET_EXHAUSTED;
        }

        return null;
    }

    private function matchesStageError(string $errorCode, string $errorPrefix): bool
    {
        // Planner 旧版本曾使用 provider_output_truncated，保留只读识别以便旧失败链安全升级。
        return in_array($errorCode, [
            $errorPrefix.'_output_truncated',
            $errorPrefix.'_reasoning_budget_exhausted',
            $errorPrefix.'_completion_budget_exhausted',
            $errorPrefix.'_output_budget_exhausted',
        ], true) || ($errorPrefix === 'plan' && $errorCode === 'provider_output_truncated');
    }

    /**
     * @param  array<string, mixed>  $budgets
     * @return array<string, array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}>
     */
    public function validateFrozen(array $budgets, AiStage $stage, array $requiredTiers = []): array
    {
        if ($requiredTiers !== [] && array_keys($budgets) !== $requiredTiers) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => '[REQUEST_BUDGET_INVALID] '.$stage->getLabel().' 必须按顺序冻结 '.implode(' / ', $requiredTiers).' 请求预算。',
            ]);
        }

        $validated = [];
        $previousOutput = 0;
        $previousReasoningReserve = 0;
        foreach (array_keys($budgets) as $tier) {
            $budget = $this->tier($budgets, (string) $tier, $stage);
            if ($budget['output_tokens'] < $previousOutput
                || $budget['reasoning_reserve_tokens'] < $previousReasoningReserve) {
                throw ValidationException::withMessages([
                    "routes.{$stage->value}" => "[REQUEST_BUDGET_INVALID] {$stage->getLabel()} 的冻结预算档位不能降低可见输出额度或推理预留。",
                ]);
            }

            // 冻结快照也要复验单调性，不能默认历史 JSON 一定来自当前配置解析器。
            $validated[(string) $tier] = $budget;
            $previousOutput = $budget['output_tokens'];
            $previousReasoningReserve = $budget['reasoning_reserve_tokens'];
        }

        if ($validated === []) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[REQUEST_BUDGET_INVALID] {$stage->getLabel()} 缺少冻结请求预算。",
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}>  $tiers
     * @return array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}
     */
    private function budgetFromRun(GenerationRun $run, array $tiers): array
    {
        $snapshot = data_get($run->context_snapshot, 'generation_preferences.selected_request_budget');
        if (is_array($snapshot)) {
            $output = (int) ($snapshot['output_tokens'] ?? 0);
            $reserve = (int) ($snapshot['reasoning_reserve_tokens'] ?? -1);
            $maximum = (int) ($snapshot['max_completion_tokens'] ?? 0);
            if ($output > 0 && $reserve >= 0 && $maximum === $output + $reserve) {
                return [
                    'output_tokens' => $output,
                    'reasoning_reserve_tokens' => $reserve,
                    'max_completion_tokens' => $maximum,
                ];
            }
        }

        $maximum = (int) data_get($run->context_snapshot, 'generation_preferences.max_completion_tokens', 0);
        foreach ($tiers as $budget) {
            if ($budget['max_completion_tokens'] === $maximum) {
                return $budget;
            }
        }

        // 旧 Run 只保存总上限时无法还原预算拆分；使用不超过该上限的最高冻结档，避免降档重试。
        return collect($tiers)
            ->filter(fn (array $budget): bool => $maximum <= 0 || $budget['max_completion_tokens'] <= $maximum)
            ->last() ?? array_values($tiers)[0];
    }

    /**
     * @param  array<string, array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}>  $tiers
     * @param  array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}  $current
     * @return array{tier: string, budget: array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}}|null
     */
    private function nextHigherTier(array $tiers, array $current, ?string $category, ?string $currentTier): ?array
    {
        $pastCurrentTier = $currentTier === null || ! array_key_exists($currentTier, $tiers);

        foreach ($tiers as $tier => $budget) {
            if (! $pastCurrentTier) {
                $pastCurrentTier = $tier === $currentTier;

                continue;
            }

            $higher = match ($category) {
                CompletionLimitClassifier::REASONING_BUDGET_EXHAUSTED => $budget['reasoning_reserve_tokens'] > $current['reasoning_reserve_tokens'],
                CompletionLimitClassifier::VISIBLE_OUTPUT_TRUNCATED => $budget['output_tokens'] > $current['output_tokens'],
                CompletionLimitClassifier::COMPLETION_BUDGET_EXHAUSTED => $budget['max_completion_tokens'] > $current['max_completion_tokens'],
                default => false,
            };

            if ($higher
                && $budget['output_tokens'] >= $current['output_tokens']
                && $budget['reasoning_reserve_tokens'] >= $current['reasoning_reserve_tokens']) {
                return ['tier' => $tier, 'budget' => $budget];
            }
        }

        return null;
    }

    /**
     * @param  array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}  $budget
     */
    private function terminalCompletionLimitException(
        string $errorCode,
        ?string $category,
        string $label,
        array $budget,
        ?AiProviderException $previous = null,
    ): AiProviderException {
        $message = match ($category) {
            CompletionLimitClassifier::REASONING_BUDGET_EXHAUSTED => "{$label} 已耗尽冻结的最高推理预留 {$budget['reasoning_reserve_tokens']} Token；请提高推理预留或降低推理程度后重新开始。",
            CompletionLimitClassifier::VISIBLE_OUTPUT_TRUNCATED => "{$label} 已在冻结的最高可见输出额度 {$budget['output_tokens']} Token 下被截断；请提高输出预算或调整模型后重新开始。",
            default => "{$label} 已耗尽冻结的最高完成预算 {$budget['max_completion_tokens']} Token，且 Provider 证据不足以判断消耗位置；请检查 Usage 后调整预算。",
        };

        return new AiProviderException(
            $errorCode,
            $message,
            false,
            $previous?->statusCode,
            $previous,
            $previous?->providerRequestId,
        );
    }
}
