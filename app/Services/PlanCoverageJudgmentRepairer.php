<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\StructuredOutput;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use App\Models\Scene;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * 将 Scene Coverage 的语义复核保存为独立 Run/Artifact，隔离技术失败与正文 Rewrite 决策。
 */
final class PlanCoverageJudgmentRepairer
{
    public const PROMPT_VERSION = 'coverage-judgment-v2';

    private const FINDING_CODES = [
        'SCENE_PLAN_COVERAGE_MISSING',
        'SCENE_PLAN_COVERAGE_CONTRADICTED',
        'SCENE_PLAN_COVERAGE_EVIDENCE_UNVERIFIED',
    ];

    /** 注入 Provider、冻结路由门禁、Run 协调器和失败记录器。 */
    public function __construct(
        private readonly AiProvider $provider,
        private readonly GenerationOutputCapacityGuard $outputCapacity,
        private readonly GenerationRunCoordinator $runCoordinator,
        private readonly GenerationFailurePolicy $failurePolicy,
        private readonly GenerationRequestBudget $requestBudget,
        private readonly ChapterStageRouteResolver $routeResolver,
    ) {}

    /** 返回当前 Draft 中最早尚未完成语义复核的 Scene。 */
    public function pendingSceneId(Chapter $chapter, GenerationArtifact $draft): ?int
    {
        $sceneIds = collect(data_get($draft->data, 'plan_findings', []))
            ->filter(fn (mixed $finding): bool => is_array($finding)
                && in_array($finding['code'] ?? null, self::FINDING_CODES, true)
                && is_numeric($finding['scene_id'] ?? null))
            ->pluck('scene_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        if ($sceneIds->isEmpty()) {
            return null;
        }

        $judgments = $this->judgmentsForDraft($chapter, $draft);

        return $sceneIds->first(function (int $sceneId) use ($chapter, $judgments): bool {
            $sceneChecksum = $chapter->scenes->firstWhere('id', $sceneId)?->currentArtifact?->checksum;
            $artifact = $judgments->get($sceneId);

            return $artifact === null
                || data_get($artifact->data, 'source_scene_checksum') !== $sceneChecksum;
        });
    }

    /**
     * 只有在全部 Judgment 之后创建的 Review 才能继续驱动 Rewrite，旧误判 Review 必须失效。
     */
    public function reviewCoversCurrentJudgments(Review $review, Chapter $chapter, GenerationArtifact $draft): bool
    {
        $sceneIds = collect(data_get($draft->data, 'plan_findings', []))
            ->filter(fn (mixed $finding): bool => is_array($finding)
                && in_array($finding['code'] ?? null, self::FINDING_CODES, true)
                && is_numeric($finding['scene_id'] ?? null))
            ->pluck('scene_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        if ($sceneIds->isEmpty()) {
            return true;
        }

        $judgments = $this->judgmentsForDraft($chapter, $draft);
        if ($sceneIds->contains(fn (int $sceneId): bool => ! $judgments->has($sceneId))) {
            return false;
        }

        $latestJudgmentRunId = $judgments->max('generation_run_id');

        return is_numeric($latestJudgmentRunId)
            && (int) $review->generation_run_id > (int) $latestJudgmentRunId;
    }

    /**
     * 用已持久化 Judgment 替换 Coverage 自报 Finding；只有复核确认的缺失或反转才能进入 Rewrite。
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    public function resolveFindings(Chapter $chapter, GenerationArtifact $draft, array $findings): array
    {
        $judgments = $this->judgmentsForDraft($chapter, $draft);
        $resolved = [];

        foreach ($findings as $finding) {
            if (! in_array($finding['code'] ?? null, self::FINDING_CODES, true)) {
                $resolved[] = $finding;

                continue;
            }

            $sceneId = is_numeric($finding['scene_id'] ?? null) ? (int) $finding['scene_id'] : 0;
            $element = (string) ($finding['plan_element'] ?? '');
            $judgment = $judgments->get($sceneId);
            $status = data_get($judgment?->data, "result.coverage.{$element}.status");
            if (! is_string($status)) {
                throw new AiProviderException(
                    'coverage_judgment_pending',
                    "Scene {$sceneId} 的 {$element} Coverage 尚未完成独立语义复核，不能创建 Rewrite 决策。",
                    false,
                );
            }

            if ($status === 'fulfilled') {
                continue;
            }

            $resolved[] = [
                ...$finding,
                'code' => $status === 'missing'
                    ? 'SCENE_PLAN_COVERAGE_MISSING'
                    : 'SCENE_PLAN_COVERAGE_CONTRADICTED',
                'severity' => 'error',
                'auto_fixable' => true,
                'requires_human_decision' => false,
                'coverage_status' => $status,
                'evidence' => data_get($judgment?->data, "result.coverage.{$element}.evidence"),
                'message' => $status === 'missing'
                    ? '独立 Coverage 语义复核确认该场景计划元素未在正文中落实。'
                    : '独立 Coverage 语义复核确认该场景计划元素被正文反转。',
                'source' => 'coverage_judgment',
                'coverage_judgment_artifact_id' => $judgment?->getKey(),
            ];
        }

        return $resolved;
    }

    /** 对一个 Scene 执行一次 Provider 复核，并冻结来源、路由、容量和实际预算。 */
    public function judge(int $chapterId, int $draftArtifactId, int $sceneId): ?GenerationArtifact
    {
        $chapter = Chapter::query()->with([
            'novel.canonicalStateVersion',
            'latestPlan',
            'scenes.currentArtifact',
        ])->findOrFail($chapterId);
        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Coverage 语义复核。', false);
        }

        $draft = GenerationArtifact::query()->with('generationRun')->findOrFail($draftArtifactId);
        $scene = $chapter->scenes->firstWhere('id', $sceneId);
        $this->assertSource($chapter, $draft, $scene);

        $coverageRow = collect(data_get($draft->data, 'scene_coverage', []))->firstWhere('scene_id', $sceneId);
        $coverage = collect(is_array($coverageRow) ? $coverageRow : [])
            ->only(PlanCoverage::ELEMENTS)
            ->all();
        $content = (string) $scene->currentArtifact?->content;
        $coverage = PlanCoverage::validate($coverage, $content, 'coverage_judgment.source_coverage');
        $scenePlan = data_get($chapter->latestPlan?->scene_plans, max(0, (int) $scene->sequence - 1), []);
        $task = PlanCoverage::expectations(
            $scene->only(PlanCoverage::ELEMENTS),
            is_array($scenePlan) ? $scenePlan : [],
        );
        // Coverage Judgment 与正式 Review 共用 Reviewer 路由，但必须读取同一份 Admission/恢复冻结合同。
        $resolvedRoute = $this->routeResolver->resolve($chapter, AiStage::Reviewer, $draft);
        $settings = $resolvedRoute['settings'];
        $route = $resolvedRoute['route'];
        // Coverage Judgment 必须从 Admission 读取独立分档，固定整数和零推理预留不再是合法合同。
        $repairBudgets = $this->requestBudget->repairTiers(
            (array) ($route['repair_request_budgets'] ?? []),
            'coverage_judgment',
            AiStage::Reviewer,
        );
        $input = [
            'source_artifact_id' => $draft->getKey(),
            'source_artifact_checksum' => $draft->checksum,
            'source_scene_artifact_id' => $scene->current_artifact_id,
            'source_scene_checksum' => $scene->currentArtifact?->checksum,
            'scene_id' => $sceneId,
            'coverage' => $coverage,
            'task' => $task,
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'prompt_version' => self::PROMPT_VERSION,
            'repair_request_budgets' => $repairBudgets,
            'generation_preferences' => $this->routeResolver->generationPreferences($resolvedRoute),
        ];
        $inputHash = app(GenerationStageFingerprint::class)->make(
            GenerationStage::CoverageJudgment,
            $input,
            upstreamChecksums: [$draft->checksum, (string) $scene->currentArtifact?->checksum],
            frozen: $route,
            contractVersion: self::PROMPT_VERSION,
        );
        [$run, $reused] = $this->startRun($chapter, $scene, $draft, $inputHash, $input, $route);
        if ($reused) {
            return $run?->status === RunStatus::Succeeded
                ? $run->artifacts()->where('type', ArtifactType::Context)->first()
                : null;
        }

        $budget = null;
        $response = null;
        try {
            $selection = $this->resolveRequestBudget($run, $repairBudgets);
            $budget = $selection['budget'];
            $requestLogId = (string) Str::uuid();
            $request = new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                reasoningEffort: $settings->reasoningEffort,
                systemPrompt: '你是 XNovel Coverage 语义复核器。只重新判断 goal、conflict、turn、outcome 的 status 和 evidence；不得修改正文、计划或任何 Canonical 数据。必须根据 task 的语义和完整 content 判断，不能用关键词命中代替语义完成。fulfilled/contradicted 的 evidence 必须逐字引用 content 中的连续文本；missing 的 evidence 必须为 null。原 Coverage 只是待复核输入，不是不可推翻的事实。',
                prompt: '请复核以下 Coverage：'.json_encode([
                    'scene_id' => $sceneId,
                    'task' => $task,
                    'content' => $content,
                    'coverage' => $coverage,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .2,
                maxTokens: $budget['max_completion_tokens'],
                responseSchema: PlanCoverage::schema(),
                promptVersion: self::PROMPT_VERSION,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'scene_id' => $sceneId,
                    'stage' => AiStage::Reviewer->value,
                    'substage' => GenerationStage::CoverageJudgment->value,
                    'repair_budget_tier' => $selection['tier'],
                    'ai_request_log_id' => $requestLogId,
                ],
            );
            // Judgment 必须沿用 Plan Admission 冻结的 Reviewer Route，且单独记录实际发送预算。
            $this->outputCapacity->assertRequestWithinFrozenRoute(
                $chapter,
                $run,
                AiStage::Reviewer,
                $request,
                GenerationStage::CoverageJudgment->value,
                $budget,
                $repairBudgets,
            );
            $response = $this->provider->generate($request);
            $result = PlanCoverage::validate(
                StructuredOutput::require($response, 'coverage_judgment', 'Coverage Judgment'),
                $content,
                'coverage_judgment.result',
            );

            return $this->complete($run, $chapter, $draft, $scene, $result, $response->providerRequestId, $requestLogId);
        } catch (Throwable $exception) {
            if ($exception instanceof AiProviderException && is_array($budget)) {
                // 只有完成预算耗尽且仍有更高冻结档时才允许 Queue Retry。
                $exception = $this->requestBudget->classifyRetry(
                    $exception,
                    $repairBudgets,
                    AiStage::Reviewer,
                    $budget,
                    'coverage_judgment',
                    'Coverage Judgment',
                    data_get($run->fresh()->context_snapshot, 'generation_preferences.request_budget_tier'),
                    $response,
                );
            }
            $this->failurePolicy->record($run, $exception, 'coverage_judgment_failed');

            throw $exception;
        }
    }

    /**
     * 锁定章节并创建可恢复的 Judgment Run；相同 input_hash 的成功产物直接复用。
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $route
     * @return array{0: GenerationRun|null, 1: bool}
     */
    private function startRun(Chapter $chapter, Scene $scene, GenerationArtifact $draft, string $inputHash, array $input, array $route): array
    {
        return DB::transaction(function () use ($chapter, $scene, $draft, $inputHash, $input, $route): array {
            $locked = Chapter::query()->lockForUpdate()->with(['novel.canonicalStateVersion', 'latestPlan'])->findOrFail($chapter->getKey());
            $runs = $locked->generationRuns()
                ->where('stage', GenerationStage::CoverageJudgment)
                ->where('scene_id', $scene->getKey())
                ->where('scope_type', 'coverage_judgment')
                ->where('scope_id', $scene->getKey());
            $resolution = $this->runCoordinator->resolve(
                $runs->getQuery(),
                $inputHash,
                'Coverage Judgment Run 超时未完成，已由后续投递恢复。',
            );
            if ($resolution['reused']) {
                return [$resolution['run'], true];
            }

            $generationPreferences = (array) ($input['generation_preferences'] ?? []);
            $frozenRoute = (array) ($generationPreferences['frozen_route'] ?? []);
            // Judgment 使用自己的 Prompt，但 Provider/Model/Reasoning 仍来自冻结 Reviewer Route。
            $frozenRoute['prompt_version'] = self::PROMPT_VERSION;
            $baseKey = "coverage-judgment:{$draft->getKey()}:{$scene->getKey()}:{$inputHash}";
            $idempotencyKey = $resolution['attempt'] === 1
                ? $baseKey
                : $baseKey.':attempt:'.$resolution['attempt'];
            // Judgment 期间章节处于审校边界，必须清除旧错误 Review 留下的 REWRITE 投影。
            if ($locked->status === ChapterStatus::Rewrite) {
                $locked->update(['status' => ChapterStatus::Review]);
            }
            $run = GenerationRun::query()->create([
                'novel_id' => $locked->novel_id,
                'chapter_id' => $locked->getKey(),
                'scene_id' => $scene->getKey(),
                'scope_type' => 'coverage_judgment',
                'scope_id' => $scene->getKey(),
                'stage' => GenerationStage::CoverageJudgment,
                'status' => RunStatus::Running,
                'attempt' => $resolution['attempt'],
                'idempotency_key' => $idempotencyKey,
                'input_hash' => $inputHash,
                'state_version' => $locked->novel->canonicalStateVersion?->version,
                'bible_version' => $locked->latestPlan?->bible_version,
                'prompt_version' => self::PROMPT_VERSION,
                'provider' => $input['provider'],
                'model_policy' => $input['model'],
                'context_snapshot' => [
                    ...$input,
                    'generation_preferences' => [
                        ...$generationPreferences,
                        'frozen_route' => $frozenRoute,
                        'model_capacity' => $route['model_capacity'],
                        'request_budgets' => $route['request_budgets'],
                        'repair_request_budgets' => [
                            'coverage_judgment' => $input['repair_request_budgets'],
                        ],
                    ],
                ],
                'started_at' => now(),
            ]);

            return [$run, false];
        });
    }

    /**
     * 根据同一 Judgment 幂等链的完成预算故障选择分档，普通临时故障保持原档。
     *
     * @param  array<string, array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}>  $repairBudgets
     * @return array{tier: string, ordinal: int, trigger: string|null, budget: array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int}}
     */
    private function resolveRequestBudget(GenerationRun $run, array $repairBudgets): array
    {
        $priorRuns = GenerationRun::query()
            ->where('chapter_id', $run->chapter_id)
            ->where('scene_id', $run->scene_id)
            ->where('stage', GenerationStage::CoverageJudgment)
            ->where('input_hash', $run->input_hash)
            ->where('id', '<', $run->getKey())
            ->whereNotNull('error_code')
            ->orderBy('id')
            ->get(['error_code', 'context_snapshot']);
        $selection = $this->requestBudget->resolveForAttempt(
            $repairBudgets,
            AiStage::Reviewer,
            $priorRuns,
            'coverage_judgment',
            'Coverage Judgment',
        );
        $snapshot = $run->context_snapshot ?? [];
        data_set($snapshot, 'generation_preferences.request_budget_tier', $selection['tier']);
        data_set($snapshot, 'generation_preferences.request_budget_trigger', $selection['trigger']);
        data_set($snapshot, 'generation_preferences.selected_request_budget', $selection['budget']);
        data_set($snapshot, 'generation_preferences.max_completion_tokens', $selection['budget']['max_completion_tokens']);
        $run->update(['context_snapshot' => $snapshot]);

        return $selection;
    }

    /** 将已验证 Judgment 结果保存为不可变 Context Artifact。 */
    private function complete(GenerationRun $run, Chapter $chapter, GenerationArtifact $draft, Scene $scene, array $coverage, ?string $providerRequestId, string $requestLogId): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $chapter, $draft, $scene, $coverage, $providerRequestId, $requestLogId): GenerationArtifact {
            $locked = Chapter::query()->lockForUpdate()->with(['novel.canonicalStateVersion', 'scenes.currentArtifact'])->findOrFail($chapter->getKey());
            $currentScene = $locked->scenes->firstWhere('id', $scene->getKey());
            if ($locked->novel->canonicalStateVersion?->version !== $run->state_version
                || $currentScene?->currentArtifact?->checksum !== data_get($run->context_snapshot, 'source_scene_checksum')
                || $draft->fresh()?->checksum !== data_get($run->context_snapshot, 'source_artifact_checksum')) {
                throw new AiProviderException('coverage_judgment_source_conflict', 'Coverage Judgment 保存前 Draft、Scene 或 Story State 已变化。', false);
            }

            $data = [
                'role' => 'coverage_judgment',
                'input_hash' => $run->input_hash,
                'source_artifact_id' => $draft->getKey(),
                'source_artifact_checksum' => $draft->checksum,
                'source_scene_artifact_id' => $currentScene?->current_artifact_id,
                'source_scene_checksum' => $currentScene?->currentArtifact?->checksum,
                'scene_id' => $scene->getKey(),
                'result' => [
                    'status' => 'succeeded',
                    'coverage' => $coverage,
                    'provider_request_id' => $providerRequestId,
                    'ai_request_log_id' => $requestLogId,
                ],
            ];
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::Context,
                'version' => 1,
                'content' => $encoded,
                'data' => $data,
                'checksum' => hash('sha256', $encoded),
            ]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    /**
     * 一次查询加载当前 Draft 的成功 Judgment Artifact，避免逐 Finding 查询数据库。
     *
     * @return Collection<int, GenerationArtifact>
     */
    private function judgmentsForDraft(Chapter $chapter, GenerationArtifact $draft): Collection
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('stage', GenerationStage::CoverageJudgment)
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->get()
            ->filter(fn (GenerationArtifact $artifact): bool => data_get($artifact->data, 'role') === 'coverage_judgment'
                && (int) data_get($artifact->data, 'source_artifact_id') === $draft->getKey()
                && data_get($artifact->data, 'source_artifact_checksum') === $draft->checksum)
            ->unique(fn (GenerationArtifact $artifact): int => (int) data_get($artifact->data, 'scene_id'))
            ->keyBy(fn (GenerationArtifact $artifact): int => (int) data_get($artifact->data, 'scene_id'));
    }

    /** 验证 Judgment 只能读取当前章节、当前 Draft 和当前 Scene Artifact。 */
    private function assertSource(Chapter $chapter, GenerationArtifact $draft, ?Scene $scene): void
    {
        if ($scene === null
            || (int) $scene->chapter_id !== $chapter->getKey()
            || $scene->currentArtifact === null
            || (int) $draft->generationRun?->chapter_id !== $chapter->getKey()
            || ! in_array($draft->type, [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft], true)
            || ! hash_equals($draft->checksum, hash('sha256', (string) $draft->content))) {
            throw new AiProviderException('coverage_judgment_source_invalid', 'Coverage Judgment 的 Draft 或 Scene 来源链无效。', false);
        }
    }
}
