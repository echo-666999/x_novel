<?php

namespace App\Services;

use App\AI\AiSettingsResolver;
use App\AI\Data\ResolvedAiSettings;
use App\AI\PromptVersionResolver;
use App\Enums\AiStage;
use App\Enums\PlanStatus;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Novel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 在 Scene 1 前冻结 Plan 的权威来源、Provider Route 与容量边界。
 */
class PlanAdmissionService
{
    /** @var array<int, AiStage> */
    private const PIPELINE_STAGES = [
        AiStage::Writer,
        AiStage::Extractor,
        AiStage::Reviewer,
        AiStage::Rewrite,
        AiStage::Summary,
    ];

    public function __construct(
        private readonly PlanValidator $validator,
        private readonly OutlineProgressResolver $outlineProgressResolver,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly PromptVersionResolver $promptVersionResolver,
        private readonly ContextBuilder $contextBuilder,
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly PreviousChapterEnding $previousChapterEnding,
        private readonly GenerationRequestBudget $requestBudget,
    ) {}

    /**
     * @return array{checksum: string, input_hash: string, admission_snapshot: array<string, mixed>, admitted_at: Carbon}
     */
    public function prepare(ChapterPlan $plan, ?int $bibleVersion = null): array
    {
        $plan->loadMissing('chapter.novel.currentOutline', 'chapter.novel.canonicalStateVersion');
        $chapter = $plan->chapter;
        $novel = $chapter->novel;
        $outline = $novel->currentOutline;
        $stateVersion = $novel->canonicalStateVersion?->version;
        $target = $this->outlineProgressResolver->resolve($novel);

        if ($outline === null || $stateVersion === null || $target === null) {
            throw ValidationException::withMessages([
                'plan' => '[ADMISSION_SOURCE_NOT_FROZEN] 缺少 Current Outline、Canonical State 或 Current Milestone；请先修复小说生成准备度。',
            ]);
        }

        // 先拒绝 Plan 本身的确定性错误，避免无效计划进入 Route/容量冻结阶段。
        $this->validator->validate($plan)->assertCanGenerate();

        $routes = [];
        foreach (self::PIPELINE_STAGES as $stage) {
            $routes[$stage->value] = $this->currentStageContract($novel, $stage)['route'];
        }

        $scenePlans = array_values($plan->scene_plans ?? []);
        $sceneCount = count($scenePlans);
        $sceneTarget = $sceneCount === 0 ? 0 : (int) ceil($plan->target_words / $sceneCount);
        $sceneAllocations = collect($scenePlans)->map(
            fn (mixed $scene, int $index): array => [
                'sequence' => $index + 1,
                'target_words' => $sceneTarget,
                'writer_max_completion_tokens' => $this->requestBudget->maximum((array) data_get($routes, 'writer.request_budgets', [])),
            ],
        )->all();
        $planChecksum = $plan->semanticChecksum();
        $handoff = $target->beat['handoff'];
        $previousEnding = $this->previousChapterEnding->for($chapter);
        $snapshot = [
            // v2 首次区分模型静态容量、分档请求预算与 Provider 实际发送参数，旧快照不得静默补写。
            'schema_version' => 2,
            'plan_checksum' => $planChecksum,
            'bible_version' => $bibleVersion ?? $this->contextBuilder->bibleVersionForChapter($chapter),
            'state_version' => $stateVersion,
            'novel_outline_id' => $outline->getKey(),
            'outline_version' => $outline->version,
            'outline_checksum' => $outline->checksum,
            'primary_outline_arc_id' => $target->outlineArcId,
            'primary_outline_beat_id' => $target->outlineBeatId,
            'primary_outline_milestone_id' => $target->outlineMilestoneId,
            'handoff_next_beat_id' => $handoff['next_beat_id'],
            'handoff_checksum' => hash('sha256', json_encode($handoff, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'inbound_handoff' => $target->inboundHandoff,
            'inbound_handoff_checksum' => $target->inboundHandoff === null
                ? null
                : hash('sha256', json_encode($target->inboundHandoff, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'previous_chapter_ending' => $previousEnding === null ? null : [
                'chapter_id' => $previousEnding['chapter_id'],
                'source' => $previousEnding['source'],
                'checksum' => hash('sha256', (string) $previousEnding['text']),
            ],
            'routes' => $routes,
            'capacity' => [
                'chapter_target_words' => $plan->target_words,
                'chapter_minimum_words' => $this->lengthPolicy->chapterMinimum($plan->target_words),
                'chapter_maximum_words' => $this->lengthPolicy->chapterMaximum($plan->target_words),
                'scene_allocations' => $sceneAllocations,
                'review' => [
                    'estimated_input_words' => $this->lengthPolicy->chapterMaximum($plan->target_words),
                    'context_token_budget' => (int) config('generation.review_context_token_budget', 32_000),
                    'max_completion_tokens' => $this->requestBudget->maximum((array) data_get($routes, 'reviewer.request_budgets', [])),
                ],
                'event_extraction' => [
                    'max_completion_tokens' => $this->requestBudget->maximum((array) data_get($routes, 'extractor.request_budgets', [])),
                ],
                'rewrite' => [
                    'max_completion_tokens' => $this->requestBudget->maximum((array) data_get($routes, 'rewrite.request_budgets', [])),
                ],
            ],
        ];
        $inputHash = hash('sha256', json_encode([
            'plan_checksum' => $planChecksum,
            'frozen_sources' => collect($snapshot)->except(['capacity', 'routes'])->all(),
            'routes' => $routes,
            'capacity' => $snapshot['capacity'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $snapshot['input_hash'] = $inputHash;

        $this->validator->validate($plan, $snapshot, $bibleVersion)->assertCanGenerate();

        return [
            'checksum' => $planChecksum,
            'input_hash' => $inputHash,
            'admission_snapshot' => $snapshot,
            'admitted_at' => now(),
        ];
    }

    /**
     * 为新 Admission 或显式恢复动作解析当前 Stage 合同；调用方必须把返回结果持久化后才能派发 Provider Job。
     *
     * @return array{route: array<string, mixed>, source: string}
     */
    public function currentStageContract(Novel $novel, AiStage $stage): array
    {
        try {
            $settings = $this->settingsResolver->resolve($stage, $novel);
            $promptVersion = $this->promptVersionResolver->resolve($stage);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[PROVIDER_ROUTE_NOT_FROZEN] {$stage->getLabel()} Route 无法冻结：{$exception->getMessage()}；请在 AI 设置中修复 Provider、Model 和 Prompt Version。",
            ]);
        }

        $profile = AIModelPrice::findEnabledForRoute($settings->provider, $settings->model);
        if ($profile === null) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[MODEL_CAPACITY_NOT_VERIFIED] {$stage->getLabel()} Route {$settings->provider}/{$settings->model} 缺少已启用的模型价格记录，无法冻结容量与能力。",
            ]);
        }
        $capabilityErrors = $profile->generationSuitabilityErrors($stage, $settings->reasoningEffort);
        if ($capabilityErrors !== []) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[MODEL_CAPABILITY_NOT_VERIFIED] {$stage->getLabel()} Route {$settings->provider}/{$settings->model} 不适用：".implode(' ', $capabilityErrors),
            ]);
        }

        $requestBudgets = $this->requestBudget->configured($stage);
        // 修复子阶段预算与主阶段预算一起冻结进 Admission，后续 Run 不得重新读取已变化的环境配置。
        $repairRequestBudgets = $this->requestBudget->configuredRepairs($stage);
        $modelCapacity = $profile->capacitySnapshot();
        $allBudgetMaximums = [
            $this->requestBudget->maximum($requestBudgets),
            ...collect($repairRequestBudgets)
                ->map(fn (array $budgets): int => $this->requestBudget->maximum($budgets))
                ->values()
                ->all(),
        ];
        $maximumBudget = max($allBudgetMaximums);
        $staticMaximum = min(
            (int) $modelCapacity['context_window_tokens'],
            (int) $modelCapacity['max_output_tokens'],
        );
        if ($maximumBudget > $staticMaximum) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[REQUEST_BUDGET_EXCEEDS_MODEL_CAPACITY] {$stage->getLabel()} 最大完成预算 {$maximumBudget} Token 超过模型静态容量 {$staticMaximum} Token。",
            ]);
        }

        return [
            'source' => $settings->source,
            'route' => [
                'provider' => $settings->provider,
                'model' => $settings->model,
                'reasoning_effort' => $settings->reasoningEffort,
                'prompt_version' => $promptVersion,
                // 模型容量与请求预算必须分别冻结，避免把工作流预算误当成模型硬上限。
                'model_capacity' => $modelCapacity,
                'request_budgets' => $requestBudgets,
                'repair_request_budgets' => $repairRequestBudgets,
            ],
        ];
    }

    public function admit(ChapterPlan $plan): ChapterPlan
    {
        return DB::transaction(function () use ($plan): ChapterPlan {
            $locked = ChapterPlan::query()->lockForUpdate()->with([
                'chapter.novel.currentOutline',
                'chapter.novel.canonicalStateVersion',
            ])->findOrFail($plan->getKey());

            if (is_array($locked->admission_snapshot)) {
                $this->validator->validate($locked, $locked->admission_snapshot)->assertCanGenerate();

                return $locked;
            }

            $locked->update($this->prepare($locked));

            return $locked->fresh();
        });
    }

    public function routeFor(ChapterPlan $plan, AiStage $stage): ResolvedAiSettings
    {
        $plan = $this->admit($plan);

        return $this->resolvedRoute($plan, $stage);
    }

    /**
     * Canonical Commit 后的派生任务读取历史 Plan 已冻结 Route，不再用已前进的 Outline Target 重验旧 Plan。
     */
    public function historicalRouteFor(ChapterPlan $plan, AiStage $stage): ResolvedAiSettings
    {
        if (! is_array($plan->admission_snapshot)) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[PROVIDER_ROUTE_NOT_FROZEN] {$stage->getLabel()} Route 未冻结。",
            ]);
        }

        return $this->resolvedRoute($plan, $stage);
    }

    private function resolvedRoute(ChapterPlan $plan, AiStage $stage): ResolvedAiSettings
    {
        $route = data_get($plan->admission_snapshot, "routes.{$stage->value}");
        if (! is_array($route)) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}" => "[PROVIDER_ROUTE_NOT_FROZEN] {$stage->getLabel()} Route 未冻结；请重新执行 Plan Admission。",
            ]);
        }

        return new ResolvedAiSettings(
            stage: $stage,
            provider: (string) $route['provider'],
            model: (string) $route['model'],
            reasoningEffort: $route['reasoning_effort'] ?? null,
            source: 'plan_admission',
        );
    }

    public function promptVersionFor(ChapterPlan $plan, AiStage $stage): string
    {
        $plan = $this->admit($plan);

        return $this->resolvedPromptVersion($plan, $stage);
    }

    public function historicalPromptVersionFor(ChapterPlan $plan, AiStage $stage): string
    {
        if (! is_array($plan->admission_snapshot)) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}.prompt_version" => "[PROMPT_VERSION_NOT_FROZEN] {$stage->getLabel()} Prompt Version 未冻结。",
            ]);
        }

        return $this->resolvedPromptVersion($plan, $stage);
    }

    private function resolvedPromptVersion(ChapterPlan $plan, AiStage $stage): string
    {
        $version = data_get($plan->admission_snapshot, "routes.{$stage->value}.prompt_version");
        if (! is_string($version) || blank($version)) {
            throw ValidationException::withMessages([
                "routes.{$stage->value}.prompt_version" => "[PROMPT_VERSION_NOT_FROZEN] {$stage->getLabel()} Prompt Version 未冻结；请重新执行 Plan Admission。",
            ]);
        }

        return $version;
    }

    public function reusableReadyPlan(Chapter $chapter, string $inputHash): ?ChapterPlan
    {
        return $chapter->plans()
            ->where('status', PlanStatus::Ready->value)
            ->where('input_hash', $inputHash)
            ->latest('version')
            ->first();
    }
}
