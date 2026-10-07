<?php

namespace App\Services;

use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Models\Chapter;
use App\Models\GenerationRun;

/**
 * Provider 调用前校验请求预算没有越过 Plan Admission 冻结的任务预算与模型容量。
 */
final class GenerationOutputCapacityGuard
{
    public function __construct(private readonly TokenBudget $tokenBudget) {}

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
        $route = $this->frozenRoute($chapter, $stage);
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
}
