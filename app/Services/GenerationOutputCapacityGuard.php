<?php

namespace App\Services;

use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Enums\GenerationStage;
use App\Models\Chapter;
use App\Models\GenerationRun;

/**
 * Provider 调用前校验请求预算没有越过 Plan Admission 冻结的任务预算与模型容量。
 */
final class GenerationOutputCapacityGuard
{
    public const LEGACY_EVENT_RECOVERY_CONTRACT = 'legacy-event-extraction-recovery-v1';

    public function __construct(
        private readonly TokenBudget $tokenBudget,
        private readonly GenerationRequestBudget $requestBudget,
    ) {}

    /**
     * 在页面派发 Event Extraction Job 前同步验证冻结合同，避免确定性配置错误进入队列。
     *
     * @return array<string, mixed>
     */
    public function assertEventExtractionDispatchable(Chapter $chapter): array
    {
        $plan = $chapter->latestPlan;
        if (! is_array($plan?->admission_snapshot)) {
            $plan = $chapter->latestPlan()->first();
        }
        $snapshot = $plan?->admission_snapshot;
        if (! is_array($snapshot)) {
            throw new AiProviderException(
                'event_admission_contract_missing',
                '[ADMISSION_CONTRACT_MISSING] 当前 Chapter Plan 没有 Admission 快照，不能派发事件提取。请重新规划并完成 Admission。',
                false,
            );
        }

        $schemaVersion = (int) ($snapshot['schema_version'] ?? 0);
        if ($schemaVersion !== 2) {
            throw new AiProviderException(
                'event_admission_contract_unsupported',
                "[ADMISSION_CONTRACT_VERSION_UNSUPPORTED] 当前 Chapter Plan 使用 Admission v{$schemaVersion}，常规重新提取不能读取旧合同。请使用“恢复事件提取（旧合同）”。",
                false,
            );
        }

        $route = data_get($snapshot, 'routes.extractor');
        if (! is_array($route)
            || blank($route['provider'] ?? null)
            || blank($route['model'] ?? null)
            || ! array_key_exists('reasoning_effort', $route)
            || blank($route['prompt_version'] ?? null)) {
            throw new AiProviderException(
                'event_route_contract_missing',
                '[PROVIDER_ROUTE_NOT_FROZEN] Extractor 的 Provider、Model、Reasoning Effort 或 Prompt Version 未完整冻结。请创建新的 Plan Version 并重新 Admission。',
                false,
            );
        }

        $capacity = $route['model_capacity'] ?? null;
        if (! is_array($capacity)
            || (int) ($capacity['model_price_id'] ?? 0) < 1
            || strtolower((string) ($capacity['provider'] ?? '')) !== strtolower((string) $route['provider'])
            || (string) ($capacity['model'] ?? '') !== (string) $route['model']
            || (int) ($capacity['context_window_tokens'] ?? 0) < 1
            || (int) ($capacity['max_output_tokens'] ?? 0) < 1
            || ($capacity['supports_structured_output'] ?? false) !== true
            || ! array_key_exists('supports_reasoning_effort', $capacity)
            || (($route['reasoning_effort'] ?? null) !== null
                && ($capacity['supports_reasoning_effort'] ?? false) !== true)) {
            throw new AiProviderException(
                'event_capacity_contract_missing',
                '[MODEL_CAPACITY_NOT_FROZEN] Extractor 缺少与冻结 Route 匹配的模型容量或必要能力。请创建新的 Plan Version 并重新 Admission。',
                false,
            );
        }

        $budgets = $route['request_budgets'] ?? null;
        if (! is_array($budgets)) {
            throw new AiProviderException(
                'event_request_budget_invalid',
                '[REQUEST_BUDGET_INVALID] Extractor 缺少 initial / retry / final 三档冻结请求预算。请创建新的 Plan Version 并重新 Admission。',
                false,
            );
        }

        $validatedBudgets = $this->requestBudget->validateFrozen(
            $budgets,
            AiStage::Extractor,
            ['initial', 'retry', 'final'],
        );
        $maximumBudget = $this->requestBudget->maximum($validatedBudgets);
        $staticMaximum = min(
            (int) $capacity['context_window_tokens'],
            (int) $capacity['max_output_tokens'],
        );
        if ($maximumBudget < 1 || $maximumBudget > $staticMaximum) {
            throw new AiProviderException(
                'event_request_budget_invalid',
                "[REQUEST_BUDGET_INVALID] Extractor 最大完成预算 {$maximumBudget} Token 超过冻结模型容量 {$staticMaximum} Token。请调整容量或预算后创建新的 Plan Version。",
                false,
            );
        }

        return [
            ...$route,
            'model_capacity' => $capacity,
            'request_budgets' => $validatedBudgets,
        ];
    }

    public function assertWithinFrozenRoute(
        Chapter $chapter,
        AiStage $stage,
        int $requestedTokens,
        ?int $estimatedInputTokens = null,
    ): void {
        $route = $this->frozenRoute($chapter, $stage);
        $modelCapacity = $route['model_capacity'];
        $requestBudgets = $route['request_budgets'];

        $requestMaximum = (int) collect($requestBudgets)->max(
            fn (mixed $budget): int => is_array($budget) ? (int) ($budget['max_completion_tokens'] ?? 0) : 0,
        );
        $contextWindow = (int) ($modelCapacity['context_window_tokens'] ?? 0);
        $modelMaximum = (int) ($modelCapacity['max_output_tokens'] ?? 0);
        $remainingContext = $estimatedInputTokens === null
            ? $contextWindow
            : max(0, $contextWindow - max(0, $estimatedInputTokens));
        // 请求必须同时满足工作流冻结预算、模型最大输出和当前输入剩余上下文三条边界。
        $legalMaximum = min($requestMaximum, $modelMaximum, $remainingContext);

        if ($requestedTokens < 1
            || $requestMaximum < 1
            || $contextWindow < 1
            || $modelMaximum < 1
            || $requestedTokens > $legalMaximum) {
            throw new AiProviderException(
                $stage->value.'_capacity_mismatch',
                "{$stage->getLabel()} 请求完成预算 {$requestedTokens} Token 超过 Plan Admission 冻结容量 {$legalMaximum} Token；任务预算上限 {$requestMaximum}，模型输出上限 {$modelMaximum}，当前剩余上下文 {$remainingContext}。请缩小任务或创建新 Plan 冻结合法 Route。",
                false,
            );
        }
    }

    /**
     * @param  array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}|null  $selectedBudget
     * @return array<string, mixed>
     */
    public function assertRequestWithinFrozenRoute(
        Chapter $chapter,
        GenerationRun $run,
        AiStage $stage,
        AiRequest $request,
        string $substage,
        ?array $selectedBudget = null,
    ): array {
        $route = $this->frozenRouteForRun($chapter, $run, $stage);
        $provider = strtolower(trim((string) $request->provider));
        $model = trim($request->model);
        if ($provider !== strtolower(trim((string) ($route['provider'] ?? '')))
            || $model !== trim((string) ($route['model'] ?? ''))) {
            throw new AiProviderException(
                $stage->value.'_frozen_route_mismatch',
                "{$stage->getLabel()} 实际请求 {$provider}/{$model} 与 Plan Admission 冻结 Route 不一致。",
                false,
            );
        }

        $snapshot = $this->assertRequestAgainstContract(
            request: $request,
            stage: $stage,
            modelCapacity: $route['model_capacity'],
            requestMaximum: (int) collect($route['request_budgets'])->max(
                fn (mixed $budget): int => is_array($budget) ? (int) ($budget['max_completion_tokens'] ?? 0) : 0,
            ),
            selectedBudget: $selectedBudget,
        );

        // 每次真正发送 Provider 前记录容量判定与实际参数，失败请求也能从 Run 还原。
        $this->recordRequestSnapshot($run, $substage, $snapshot);

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function recordRequestSnapshot(GenerationRun $run, string $substage, array $snapshot): void
    {
        $fresh = $run->fresh();
        $context = $fresh->context_snapshot ?? [];
        $requests = (array) data_get($context, 'generation_preferences.provider_requests', []);
        $requests[] = ['substage' => $substage, ...$snapshot];
        data_set($context, 'generation_preferences.provider_requests', $requests);
        $fresh->update(['context_snapshot' => $context]);
        $run->setRawAttributes($fresh->getAttributes(), true);
    }

    /**
     * 修复器只持有请求元数据时，也必须回到所属 Chapter/Run 校验冻结合同，不能绕过主阶段门禁。
     *
     * @return array<string, mixed>
     */
    public function assertRequestFromMetadata(AiRequest $request, AiStage $stage, string $substage): array
    {
        $chapterId = $request->metadata['chapter_id'] ?? null;
        $runId = $request->metadata['generation_run_id'] ?? null;
        if (! is_numeric($chapterId) || ! is_numeric($runId)) {
            throw new AiProviderException(
                $stage->value.'_capacity_contract_missing',
                "{$stage->getLabel()} 子请求缺少 Chapter 或 Generation Run 身份，无法验证冻结容量。",
                false,
            );
        }

        $chapter = Chapter::query()->with('latestPlan')->findOrFail((int) $chapterId);
        $run = GenerationRun::query()->findOrFail((int) $runId);

        return $this->assertRequestWithinFrozenRoute($chapter, $run, $stage, $request, $substage);
    }

    public function frozenSubstageMaxTokens(GenerationRun $run, string $key): int
    {
        $maxTokens = data_get($run->context_snapshot, "generation_preferences.repair_budgets.{$key}");
        if (! is_numeric($maxTokens) || (int) $maxTokens < 1) {
            throw new AiProviderException(
                'repair_request_budget_missing',
                "修复子请求 {$key} 缺少 Run 冻结预算，不能重新读取运行时配置。",
                false,
            );
        }

        return (int) $maxTokens;
    }

    /** @param array<string, mixed> $metadata */
    public function frozenSubstageMaxTokensFromMetadata(array $metadata, string $key): int
    {
        $runId = $metadata['generation_run_id'] ?? null;
        if (! is_numeric($runId)) {
            throw new AiProviderException('repair_request_budget_missing', '修复子请求缺少 Generation Run 身份。', false);
        }

        return $this->frozenSubstageMaxTokens(GenerationRun::query()->findOrFail((int) $runId), $key);
    }

    /**
     * Planner 在 Plan Admission 之前执行，因此必须把本次解析出的容量合同冻结进自身 Run 后再调用此方法。
     *
     * @param  array<string, mixed>  $modelCapacity
     * @param  array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}|null  $selectedBudget
     * @return array<string, mixed>
     */
    public function assertRequestAgainstContract(
        AiRequest $request,
        AiStage $stage,
        array $modelCapacity,
        int $requestMaximum,
        ?array $selectedBudget = null,
    ): array {
        $estimatedInputTokens = $this->tokenBudget->estimate([
            'messages' => $request->resolvedMessages(),
            'response_schema' => $request->responseSchema,
        ]);
        $contextWindow = (int) ($modelCapacity['context_window_tokens'] ?? 0);
        $modelMaximum = (int) ($modelCapacity['max_output_tokens'] ?? 0);
        $remainingContext = max(0, $contextWindow - $estimatedInputTokens);
        $legalMaximum = min($requestMaximum, $modelMaximum, $remainingContext);

        if ($selectedBudget !== null
            && (int) ($selectedBudget['max_completion_tokens'] ?? 0) !== $request->maxTokens) {
            throw new AiProviderException(
                $stage->value.'_request_budget_mismatch',
                "{$stage->getLabel()} 实际发送完成预算 {$request->maxTokens} Token 与当前冻结分档不一致。",
                false,
            );
        }

        if ($request->maxTokens < 1
            || $requestMaximum < 1
            || $contextWindow < 1
            || $modelMaximum < 1
            || $request->maxTokens > $legalMaximum) {
            throw new AiProviderException(
                $stage->value.'_capacity_mismatch',
                "{$stage->getLabel()} 请求完成预算 {$request->maxTokens} Token 超过冻结容量 {$legalMaximum} Token；任务预算上限 {$requestMaximum}，模型输出上限 {$modelMaximum}，估算输入 {$estimatedInputTokens}，剩余上下文 {$remainingContext}。",
                false,
            );
        }

        return [
            'provider' => $request->provider,
            'model' => $request->model,
            'reasoning_effort' => $request->reasoningEffort,
            'estimated_input_tokens' => $estimatedInputTokens,
            'output_tokens' => (int) ($selectedBudget['output_tokens'] ?? $request->maxTokens),
            'reasoning_reserve_tokens' => (int) ($selectedBudget['reasoning_reserve_tokens'] ?? 0),
            'max_completion_tokens' => $request->maxTokens,
            'request_budget_maximum' => $requestMaximum,
            'context_window_tokens' => $contextWindow,
            'model_max_output_tokens' => $modelMaximum,
            'remaining_context_tokens' => $remainingContext,
        ];
    }

    /** @return array<string, mixed> */
    public function frozenRoute(Chapter $chapter, AiStage $stage): array
    {
        $plan = $chapter->latestPlan;
        if (! is_array($plan?->admission_snapshot)) {
            // Admission 可能刚在同一请求内写入数据库；不能继续读取 Chapter 上已缓存的旧关系对象。
            $plan = $chapter->latestPlan()->first();
        }
        $snapshot = $plan?->admission_snapshot;
        $route = is_array($snapshot) ? data_get($snapshot, "routes.{$stage->value}") : null;
        $modelCapacity = is_array($route) ? ($route['model_capacity'] ?? null) : null;
        $requestBudgets = is_array($route) ? ($route['request_budgets'] ?? null) : null;
        if (! is_array($route) || ! is_array($modelCapacity) || ! is_array($requestBudgets) || $requestBudgets === []) {
            throw new AiProviderException(
                $stage->value.'_capacity_contract_missing',
                "{$stage->getLabel()} 缺少 Plan Admission 冻结的模型容量或请求预算；请创建新的 Plan Version 并重新 Admission。",
                false,
            );
        }

        return $route;
    }

    /** @return array<string, mixed> */
    public function frozenRouteForRun(Chapter $chapter, GenerationRun $run, AiStage $stage): array
    {
        $contractSource = data_get($run->context_snapshot, 'generation_preferences.route_contract_source');
        if ($contractSource !== self::LEGACY_EVENT_RECOVERY_CONTRACT) {
            return $this->frozenRoute($chapter, $stage);
        }

        // 只有显式创建的旧 Admission 事件恢复 Run 可以绕过 Plan v1；其他阶段仍必须服从原 Plan Admission。
        if ($stage !== AiStage::Extractor
            || $run->stage !== GenerationStage::EventExtraction
            || $run->chapter_id !== $chapter->getKey()) {
            throw new AiProviderException(
                'extractor_recovery_contract_mismatch',
                '事件提取恢复 Run 的 Stage 或 Chapter 身份不匹配，已在 Provider 请求前阻断。',
                false,
            );
        }

        $frozenRoute = data_get($run->context_snapshot, 'generation_preferences.frozen_route');
        $modelCapacity = data_get($run->context_snapshot, 'generation_preferences.model_capacity');
        $requestBudgets = data_get($run->context_snapshot, 'generation_preferences.request_budgets');
        $route = is_array($frozenRoute) ? [
            'provider' => $frozenRoute['provider'] ?? null,
            'model' => $frozenRoute['model'] ?? null,
            'reasoning_effort' => $frozenRoute['reasoning_effort'] ?? null,
            'prompt_version' => $frozenRoute['prompt_version'] ?? null,
            'model_capacity' => $modelCapacity,
            'request_budgets' => $requestBudgets,
        ] : null;

        if (! is_array($route)
            || blank($route['provider'] ?? null)
            || blank($route['model'] ?? null)
            || blank($route['prompt_version'] ?? null)
            || ! is_array($modelCapacity)
            || ! is_array($requestBudgets)
            || $requestBudgets === []
            || strtolower((string) ($modelCapacity['provider'] ?? '')) !== strtolower((string) $route['provider'])
            || (string) ($modelCapacity['model'] ?? '') !== (string) $route['model']
            || $run->provider !== $route['provider']
            || $run->model_policy !== $route['model']
            || $run->prompt_version !== $route['prompt_version']) {
            throw new AiProviderException(
                'extractor_recovery_contract_missing',
                '事件提取恢复 Run 缺少完整冻结路由、模型容量或三档请求预算。',
                false,
            );
        }

        return $route;
    }
}
