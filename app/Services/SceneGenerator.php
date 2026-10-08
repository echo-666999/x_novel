<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\NarrativeProsePolicy;
use App\AI\StructuredOutput;
use App\Data\ContextRequest;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Exceptions\GenerationStageDeferredException;
use App\Exceptions\SceneStageDeferredException;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SceneGenerator
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly PlanAdmissionService $planAdmission,
        private readonly ContextBuilder $contextBuilder,
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly GenerationRunCoordinator $runCoordinator,
        private readonly SceneDraftStructureRepairer $structureRepairer,
        private readonly PlanCoverageEvidenceRepairer $coverageEvidenceRepairer,
        private readonly ForeshadowingCoverageEvidenceRepairer $foreshadowingCoverageEvidenceRepairer,
        private readonly GenerationFailurePolicy $failurePolicy,
        private readonly SceneExecutionClock $executionClock,
        private readonly GenerationRequestBudget $requestBudget,
        private readonly GenerationOutputCapacityGuard $outputCapacity,
    ) {}

    public function generate(int $sceneId, bool $regenerate = false, ?string $regenerationBatchId = null, bool $singleProviderCall = false): ?GenerationArtifact
    {
        $providerCalls = 0;
        $jobStartedAt = $this->executionClock->start();
        $scene = Scene::query()->with([
            'chapter.novel.canonicalStateVersion',
            'chapter.latestPlan',
            'chapter.scenes.currentArtifact',
        ])->findOrFail($sceneId);
        $chapter = $scene->chapter;
        $novel = $chapter->novel;
        $plan = $chapter->latestPlan;

        if ($chapter->status === ChapterStatus::Canonical) {
            throw new AiProviderException('canonical_scene_immutable', '正式章节不能直接重新生成场景，请先回滚该章节。', false);
        }

        if ($novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始新的 Scene 生成阶段。', false);
        }

        if ($plan === null || $novel->canonicalStateVersion === null) {
            throw new AiProviderException('scene_context_incomplete', 'Scene 生成缺少 Chapter Plan 或 Story State。', false);
        }

        $previousArtifacts = $this->previousArtifacts($scene);
        $plan = $this->planAdmission->admit($plan);
        $settings = $this->planAdmission->routeFor($plan, AiStage::Writer);
        $extractorSettings = $this->planAdmission->routeFor($plan, AiStage::Extractor);
        $rewriteSettings = $this->planAdmission->routeFor($plan, AiStage::Rewrite);
        $promptVersion = $this->planAdmission->promptVersionFor($plan, AiStage::Writer);
        $previousArtifact = $previousArtifacts->last();
        $snapshot = $this->contextBuilder->build(new ContextRequest(
            novelId: $novel->getKey(),
            chapterId: $chapter->getKey(),
            sceneId: $scene->getKey(),
            taskType: GenerationStage::SceneGeneration->value,
            bibleVersion: $this->contextBuilder->bibleVersionForChapter($chapter),
            stateVersion: $novel->canonicalStateVersion->version,
            chapterPlanId: $plan->getKey(),
            tokenBudget: (int) config('generation.scene_context_token_budget', 12_000),
            promptVersion: $promptVersion,
            model: $settings->model,
            previousArtifactId: $previousArtifact?->getKey(),
        ));
        $context = $snapshot->toArray();
        $context['temporary_state'] = $this->temporaryState($previousArtifacts->all());
        $context['previous_scene_tail'] = $this->previousSceneTail($previousArtifact);
        $context['scene_task'] = $scene->only([
            'id', 'sequence', 'pov_character_id', 'location', 'time_anchor', 'goal', 'conflict', 'turn', 'outcome',
        ]);
        $scenePlan = data_get($plan->scene_plans, $scene->sequence - 1, []);
        $context['scene_task']['transition_from_previous'] = data_get($scenePlan, 'transition_from_previous');
        $context['scene_task']['outcome_allowed'] = data_get($scenePlan, 'outcome_allowed', []);
        $context['scene_task']['outcome_forbidden'] = data_get($scenePlan, 'outcome_forbidden', []);
        $context['scene_task']['continuity_requirements'] = data_get($scenePlan, 'continuity_requirements', []);
        $context['writing_constraints'] = [
            ...$this->sceneAllocation($scene, (int) $plan->target_words),
        ];
        $writerRoute = $this->outputCapacity->frozenRoute($chapter, AiStage::Writer);
        $extractorRoute = $this->outputCapacity->frozenRoute($chapter, AiStage::Extractor);
        $rewriteRoute = $this->outputCapacity->frozenRoute($chapter, AiStage::Rewrite);
        $context['generation_preferences']['model_capacity'] = $writerRoute['model_capacity'];
        $context['generation_preferences']['request_budgets'] = $writerRoute['request_budgets'];
        // Run 自身保留可直接观测的冻结路由，页面不应依赖之后可能变化的全局配置反推。
        $context['generation_preferences']['frozen_route'] = [
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'source' => $settings->source,
            'prompt_version' => $promptVersion,
        ];
        // Scene Run 同时冻结 Extractor 与 Rewrite 的修复预算，Resume 只能读取这些三元组。
        $context['generation_preferences']['repair_request_budgets'] = [
            ...collect($extractorRoute['repair_request_budgets'])
                ->only(['scene_structure', 'coverage_evidence', 'foreshadowing_coverage'])
                ->all(),
            'length_repair' => data_get($rewriteRoute, 'repair_request_budgets.length_repair'),
        ];
        $lengthRepairBudget = $this->requestBudget->repairTier(
            $context['generation_preferences']['repair_request_budgets'],
            'length_repair',
            'initial',
            AiStage::Rewrite,
        );
        $context['generation_preferences']['substage_routes'] = [
            'prose' => $this->routeSnapshot($chapter, $settings, AiStage::Writer, $promptVersion, null),
            'structure_and_coverage' => $this->routeSnapshot($chapter, $extractorSettings, AiStage::Extractor, 'scene-support-repair-v2', null),
            'length_repair' => $this->routeSnapshot($chapter, $rewriteSettings, AiStage::Rewrite, 'scene-length-repair-v2', $lengthRepairBudget['max_completion_tokens']),
        ];
        $foreshadowingExpectations = ForeshadowingCoverage::expectationsForScene(
            data_get($context, 'l0.foreshadowing_contract', []),
            $scene->sequence,
        );
        $inputHash = app(GenerationStageFingerprint::class)->make(
            GenerationStage::SceneGeneration,
            $context,
            upstreamChecksums: array_values(array_filter([$previousArtifact?->checksum, $plan->checksum])),
            frozen: [
                'provider' => $settings->provider,
                'model' => $settings->model,
                'reasoning_effort' => $settings->reasoningEffort,
                'extractor_route' => $context['generation_preferences']['substage_routes']['structure_and_coverage'],
                'rewrite_route' => $context['generation_preferences']['substage_routes']['length_repair'],
            ],
            contractVersion: $promptVersion,
        );
        $baseKey = "scene:{$scene->getKey()}:{$inputHash}:{$promptVersion}:{$settings->model}";

        [$run, $reused] = $this->startRun($scene, $baseKey, $inputHash, $context, $settings->provider, $settings->model, $promptVersion, $regenerate, $regenerationBatchId);

        if ($reused) {
            $artifact = $run->artifacts()->where('type', ArtifactType::SceneDraft)->latest('id')->first();

            if ($artifact !== null && $scene->current_artifact_id !== $artifact->getKey()) {
                $scene->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);
            }

            return $artifact;
        }

        $budget = null;
        try {
            $budget = $this->resolveRequestBudget($run, $baseKey);
            $metadata = [
                'generation_run_id' => $run->getKey(),
                'novel_id' => $novel->getKey(),
                'chapter_id' => $chapter->getKey(),
                'scene_id' => $scene->getKey(),
            ];
            $checkpoint = $this->latestCheckpoint($scene, $inputHash);
            $payload = $checkpoint['payload'] ?? null;
            $checkpointRank = $this->checkpointRank($checkpoint['substage'] ?? null);
            if ($checkpoint !== null && (int) $checkpoint['generation_run_id'] !== $run->getKey()) {
                $resumeContext = $run->context_snapshot ?? [];
                $resumeContext['scene_execution']['resumed_from_run_id'] = $checkpoint['generation_run_id'];
                $resumeContext['scene_execution']['source_artifact_id'] = $checkpoint['artifact_id'];
                $resumeContext['scene_execution']['resumed_from_substage'] = $checkpoint['substage'];
                $run->update(['context_snapshot' => $resumeContext]);
            }
            $beforeRequest = function (string $substage, string $provider) use ($run, $jobStartedAt, $singleProviderCall, &$providerCalls): void {
                $this->assertProviderCallFits($run, $jobStartedAt, $provider, $substage);
                if ($singleProviderCall && $providerCalls >= 1) {
                    throw new GenerationStageDeferredException(GenerationStage::SceneGeneration, $substage);
                }
                $providerCalls++;
            };
            $afterRepairResponse = fn (string $kind, int $attempt, array $repairPayload) => $this->saveCheckpoint(
                $run,
                'repair_response',
                $repairPayload,
                $inputHash,
                $checkpoint['substage'] ?? null,
                ['repair_kind' => $kind, 'repair_attempt' => $attempt],
            );

            if (! is_array($payload)) {
                $beforeRequest('prose_generation', $settings->provider);
                $request = new AiRequest(
                    model: $settings->model,
                    provider: $settings->provider,
                    reasoningEffort: $this->sentReasoningEffort($settings->reasoningEffort),
                    systemPrompt: '你是 XNovel 场景写作器。只写当前场景。l0.foreshadowing_contract 是本章冻结的唯一伏笔动作契约；只能执行 actions 中 target_scene_sequence 等于当前 Scene 序号的动作，未列入 actions 的伏笔不能在本章主动铺设、强化、兑现、延期或放弃。plan_constraints.arc_contributions 和 world_entity_candidates 同样是冻结契约：只推进目标 Scene 等于当前序号的 Arc Beat，只能引入其中批准的重大世界实体，并在正文中使用 candidate_key 对应的名称与定义；不得自由创造未登记的重大地点、物品、阵营、组织、规则或概念。promised_payoff 是作者侧约束，不代表允许向读者直接揭晓；必须同时遵守 plan_constraints.must_not_reveal，并按 plan_action.action 与 acceptance_criteria 控制揭示程度。foreshadowing_coverage 必须按契约顺序返回当前 Scene 的全部伏笔动作；只有正文证据足以证明 acceptance_criteria 已实现时才能标记 fulfilled，主题相近但没有动作结果必须标记 missing，反转既定动作则标记 contradicted。fulfilled 和 contradicted 的 evidence 必须逐字引用 content，missing 的 evidence 必须为 null。l4 是唯一的 Style Contract；严格保持其中的 POV、时态和主文风，只使用指定辅助文风补充特征，不得让辅助文风覆盖主文风，并执行 expanded_parameters。第一场景必须从 previous_chapter_ending 连续展开，并把 scene_task.transition_from_previous 指定的时间、地点与行动过渡写进正文；不得从上一章结尾直接跳到次日或新地点而省略关键过程。scene_task.continuity_requirements 中 establish 只负责首次建立，persist 只写本场新增影响或必要的最短提醒，change 必须写出状态变化，callback 只在章末自然回扣；不得逐 Scene 重复解释同一伤势、限制、等待状态或监管边界。goal、conflict、turn、outcome 都是不可省略的验收项，尤其不得反转 outcome；正文行为必须位于 outcome_allowed 内且不得出现 outcome_forbidden。self_check 必须逐项返回 fulfilled、missing 或 contradicted；fulfilled 和 contradicted 的 evidence 必须逐字引用 content，missing 的 evidence 必须为 null。scene_target_words 是当前场景目标字数，maximum_scene_words 是不可超过的硬上限；字数统计排除空白和换行。当 required_scene_words 大于 0 时，正文还必须至少达到该字数，使各场景总量达到章节下限。场景可以短于目标，未使用的字数由后续场景承接。通过完整的动作、对话、环境、感官和人物反应展开既定场景，不得用提纲、摘要、无意义重复或新增重大事实凑字。返回符合 Schema 的 JSON；除固定字段和枚举值外，正文及所有自然语言内容必须使用简体中文。草稿不得修改正式故事状态。'.NarrativeProsePolicy::writing(),
                    prompt: '请根据以下权威上下文生成当前场景：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    temperature: 0.7,
                    maxTokens: $budget['max_completion_tokens'],
                    responseSchema: SceneDraftPayload::schema(),
                    promptVersion: $promptVersion,
                    metadata: [...$metadata, 'stage' => AiStage::Writer->value, 'substage' => 'prose_generation', 'route_key' => 'prose'],
                );
                // 主写作请求必须使用 Admission 冻结的 Writer 预算，并按完整消息估算上下文占用。
                $this->outputCapacity->assertRequestWithinFrozenRoute(
                    $chapter,
                    $run,
                    AiStage::Writer,
                    $request,
                    'prose_generation',
                    $budget,
                );
                $response = $this->provider->generate($request);
                $payload = StructuredOutput::require($response, 'scene', 'Scene Draft');
                $this->saveCheckpoint($run, 'prose_generated', $payload, $inputHash, null);
                $checkpointRank = 1;
            }

            if ($checkpointRank < 2) {
                $payload = $this->validatePayloadWithCoverageRepair(
                    payload: $payload,
                    context: $context,
                    provider: $extractorSettings->provider,
                    model: $extractorSettings->model,
                    reasoningEffort: $this->sentReasoningEffort($extractorSettings->reasoningEffort),
                    metadata: [...$metadata, 'route_key' => 'structure_and_coverage'],
                    beforeRequest: $beforeRequest,
                    repairState: $checkpoint ?? [],
                    afterRepairResponse: $afterRepairResponse,
                );
                $this->validatePlanConstraints($payload['content'], $plan->must_not_reveal ?? []);
                $this->saveCheckpoint($run, 'evidence_validated', $payload, $inputHash, 'prose_generated');
            }

            if ($checkpointRank < 3) {
                $payload = $this->repairLengthIfNeeded(
                    payload: $payload,
                    context: $context,
                    provider: $rewriteSettings->provider,
                    model: $rewriteSettings->model,
                    promptVersion: 'scene-length-repair-v2',
                    reasoningEffort: $this->sentReasoningEffort($rewriteSettings->reasoningEffort),
                    metadata: $metadata,
                    beforeRequest: $beforeRequest,
                    extractorProvider: $extractorSettings->provider,
                    extractorModel: $extractorSettings->model,
                    extractorReasoningEffort: $this->sentReasoningEffort($extractorSettings->reasoningEffort),
                    startingAttempt: ($checkpoint['repair_kind'] ?? null) === 'length'
                        ? ((int) ($checkpoint['repair_attempt'] ?? 0)) + 1
                        : 1,
                    afterResponse: fn (int $attempt, array $repairPayload) => $this->saveCheckpoint(
                        $run,
                        'length_repair_response',
                        $repairPayload,
                        $inputHash,
                        $checkpoint['substage'] ?? 'evidence_validated',
                        ['repair_kind' => 'length', 'repair_attempt' => $attempt],
                    ),
                    repairState: $checkpoint ?? [],
                    afterRepairResponse: $afterRepairResponse,
                );
                $this->saveCheckpoint($run, 'length_validated', $payload, $inputHash, 'evidence_validated');
            }
            $this->validatePlanConstraints($payload['content'], $plan->must_not_reveal ?? []);
            $this->validateLength($payload['content'], $context['writing_constraints']);

            return $this->complete(
                $run,
                $scene,
                $payload,
                $snapshot->stateVersion,
                $context['writing_constraints'],
                PlanCoverage::expectations($scene->only(PlanCoverage::ELEMENTS), $scenePlan),
                $foreshadowingExpectations,
            );
        } catch (Throwable $exception) {
            if ($exception instanceof AiProviderException && is_array($budget)) {
                // 正文阶段按耗尽类型升级冻结预算；不存在更高额度时立即终止，不能原参数重放。
                $exception = $this->requestBudget->classifyRetry(
                    $exception,
                    (array) data_get($run->context_snapshot, 'generation_preferences.request_budgets', []),
                    AiStage::Writer,
                    $budget,
                    'scene',
                    'Scene Draft',
                    data_get($run->fresh()->context_snapshot, 'generation_preferences.request_budget_tier'),
                );
            }
            $this->failRun($run, $exception);
            throw $exception;
        }
    }

    public function markTerminalFailure(int $sceneId, Throwable $exception): void
    {
        DB::transaction(function () use ($sceneId): void {
            $scene = Scene::query()->lockForUpdate()->with('chapter')->findOrFail($sceneId);
            $scene->update(['status' => SceneStatus::Failed]);
            $scene->chapter->update(['status' => ChapterStatus::Blocked]);
        });
    }

    private function previousArtifacts(Scene $scene)
    {
        $previousScenes = $scene->chapter->scenes()
            ->where('sequence', '<', $scene->sequence)
            ->reorder('sequence')
            ->get();
        $incomplete = $previousScenes->first(fn (Scene $previous): bool => $previous->current_artifact_id === null
            || ! in_array($previous->status, [SceneStatus::Draft, SceneStatus::Accepted], true));

        if ($incomplete !== null) {
            throw new AiProviderException(
                'previous_scene_incomplete',
                "Scene {$scene->sequence} 必须等待 Scene {$incomplete->sequence} 成功后才能执行。",
                false,
            );
        }

        return GenerationArtifact::query()
            ->whereIn('id', $previousScenes->pluck('current_artifact_id'))
            ->get()
            ->sortBy(fn (GenerationArtifact $artifact): int => (int) $previousScenes
                ->firstWhere('current_artifact_id', $artifact->getKey())?->sequence)
            ->values();
    }

    /** @param array<int, GenerationArtifact> $artifacts
     * @return array<string, mixed>
     */
    private function temporaryState(array $artifacts): array
    {
        $state = [];

        foreach ($artifacts as $artifact) {
            $state = array_replace_recursive($state, data_get($artifact->data, 'temporary_state_delta', []));
        }

        return $state;
    }

    private function previousSceneTail(?GenerationArtifact $artifact): ?string
    {
        if (blank($artifact?->content)) {
            return null;
        }

        return mb_substr($artifact->content, -(int) config('generation.previous_scene_tail_characters', 1_000));
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Scene $scene, string $baseKey, string $inputHash, array $context, string $provider, string $model, string $promptVersion, bool $regenerate, ?string $regenerationBatchId): array
    {
        return DB::transaction(function () use ($scene, $baseKey, $inputHash, $context, $provider, $model, $promptVersion, $regenerationBatchId): array {
            $scene = Scene::query()->lockForUpdate()->findOrFail($scene->getKey());
            $runs = $scene->generationRuns()->where('stage', GenerationStage::SceneGeneration);
            $resolution = $this->runCoordinator->resolve($runs->getQuery(), $inputHash, 'Scene Run 超时未完成，已由后续投递恢复。');
            if ($resolution['reused']) {
                return [$resolution['run'], true];
            }
            $attempt = $resolution['attempt'];
            $key = $attempt === 1 ? $baseKey : $baseKey.':attempt:'.$attempt;
            $run = GenerationRun::query()->create([
                'novel_id' => $scene->chapter->novel_id,
                'chapter_id' => $scene->chapter_id,
                'scene_id' => $scene->getKey(),
                'scope_type' => 'scene',
                'scope_id' => $scene->getKey(),
                'stage' => GenerationStage::SceneGeneration,
                'status' => RunStatus::Running,
                'attempt' => $attempt,
                'idempotency_key' => $key,
                'input_hash' => $inputHash,
                'state_version' => data_get($context, 'state_version'),
                'bible_version' => data_get($context, 'bible_version'),
                'prompt_version' => $promptVersion,
                'provider' => $provider,
                'model_policy' => $model,
                'context_snapshot' => [
                    ...$context,
                    'regeneration_batch_id' => $regenerationBatchId,
                ],
                'started_at' => now(),
            ]);
            $scene->update(['status' => SceneStatus::Generating]);
            $scene->chapter()->update(['status' => ChapterStatus::Generating]);

            return [$run, false];
        });
    }

    /**
     * 保存不可变 Scene Draft，并把 Coverage 证据未验证标记交给后续 Judgment。
     *
     * @param  array<string, mixed>  $payload
     */
    private function complete(GenerationRun $run, Scene $scene, array $payload, int $expectedStateVersion, array $writingConstraints, array $planExpectations, array $foreshadowingExpectations): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $scene, $payload, $expectedStateVersion, $writingConstraints, $planExpectations, $foreshadowingExpectations): GenerationArtifact {
            $scene = Scene::query()->lockForUpdate()->with('chapter.novel')->findOrFail($scene->getKey());
            $currentVersion = $scene->chapter->novel->canonicalStateVersion()->value('version');

            if ($currentVersion !== $expectedStateVersion) {
                throw new AiProviderException('state_version_conflict', 'Scene 生成期间 Canonical Story State 已变化。', false);
            }

            $version = GenerationArtifact::query()
                ->where('type', ArtifactType::SceneDraft)
                ->whereHas('generationRun', fn ($query) => $query
                    ->where('scene_id', $scene->getKey())
                    ->where('stage', GenerationStage::SceneGeneration))
                ->max('version');

            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::SceneDraft,
                'version' => ((int) $version) + 1,
                'content' => $payload['content'],
                'data' => [
                    ...collect($payload)->except('content')->all(),
                    'plan_findings' => [
                        ...PlanCoverage::findings(
                            $scene->getKey(),
                            $payload['self_check'],
                            $planExpectations,
                            'scene_self_check',
                            (array) ($payload['coverage_evidence_unverified'] ?? []),
                        ),
                        ...ForeshadowingCoverage::findings(
                            $scene->getKey(),
                            $payload['foreshadowing_coverage'],
                            $foreshadowingExpectations,
                            'scene_foreshadowing_coverage',
                        ),
                    ],
                    'word_count' => $this->lengthPolicy->count($payload['content']),
                    'target_words' => $writingConstraints['scene_target_words'],
                    'required_words' => $writingConstraints['required_scene_words'],
                    'allocated_scene_words' => $writingConstraints['allocated_scene_words'],
                    'remaining_scene_count' => $writingConstraints['remaining_scene_count'],
                ],
                'checksum' => hash('sha256', $payload['content']),
            ]);
            $scene->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    /** @param array<int, string> $mustNotReveal */
    private function validatePlanConstraints(string $content, array $mustNotReveal): void
    {
        foreach ($mustNotReveal as $forbidden) {
            if (filled($forbidden) && mb_stripos($content, (string) $forbidden) !== false) {
                throw new AiProviderException('scene_plan_violation', "Scene 正文包含禁止揭示内容：{$forbidden}", false);
            }
        }
    }

    /** @param array<string, mixed> $writingConstraints */
    private function validateLength(string $content, array $writingConstraints): void
    {
        $actual = $this->lengthPolicy->count($content);
        $required = (int) $writingConstraints['required_scene_words'];

        $maximum = (int) $writingConstraints['maximum_scene_words'];

        if ($actual > $maximum) {
            throw new AiProviderException(
                'scene_budget_exceeded',
                "当前场景 {$actual} 字，最多允许 {$maximum} 字。",
                false,
            );
        }

        if ($required > 0 && $actual < $required) {
            $chapterTotal = (int) $writingConstraints['allocated_scene_words'] + $actual;

            throw new AiProviderException(
                'scene_budget_shortfall',
                "当前已是最后一个待生成场景；生成后本章场景合计 {$chapterTotal} 字，章节目标 {$writingConstraints['chapter_target_words']} 字，至少需要达到 {$writingConstraints['chapter_minimum_words']} 字。当前场景至少需要 {$required} 字。",
                false,
            );
        }
    }

    /** @param array<string, mixed> $payload
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function repairLengthIfNeeded(
        array $payload,
        array $context,
        string $provider,
        string $model,
        string $promptVersion,
        ?string $reasoningEffort,
        array $metadata,
        ?callable $beforeRequest = null,
        ?string $extractorProvider = null,
        ?string $extractorModel = null,
        ?string $extractorReasoningEffort = null,
        int $startingAttempt = 1,
        ?callable $afterResponse = null,
        array $repairState = [],
        ?callable $afterRepairResponse = null,
    ): array {
        $constraints = $context['writing_constraints'];
        $required = (int) $constraints['required_scene_words'];
        $maximum = (int) $constraints['maximum_scene_words'];

        for ($attempt = $startingAttempt; $attempt <= (int) config('generation.max_length_repair_attempts', 1); $attempt++) {
            $actual = $this->lengthPolicy->count($payload['content']);
            $tooShort = $required > 0 && $actual < $required;
            $tooLong = $actual > $maximum;

            if (! $tooShort && ! $tooLong) {
                break;
            }

            $foreshadowingCoverageTemplate = is_array($payload['foreshadowing_coverage'] ?? null)
                ? $payload['foreshadowing_coverage']
                : [];
            $currentSceneForeshadowingActions = ForeshadowingCoverage::expectationsForScene(
                data_get($context, 'l0.foreshadowing_contract', []),
                (int) data_get($context, 'scene_task.sequence'),
            );

            // 字数修复按自身尝试次数读取 Rewrite Route 下的精确冻结预算。
            $repairContract = $this->outputCapacity->frozenRepairBudgetFromMetadata(
                $metadata,
                AiStage::Rewrite,
                'length_repair',
                $attempt === 1 ? 'initial' : 'retry',
            );
            $budget = $repairContract['budget'];
            $beforeRequest?->__invoke('length_repair', $provider);
            $request = new AiRequest(
                model: $model,
                provider: $provider,
                reasoningEffort: $reasoningEffort,
                systemPrompt: ($tooLong
                    ? '你是 XNovel 场景压缩器。输入包含一份字数超限的场景草稿。l4 是唯一的 Style Contract；压缩后必须保持其中的 POV、时态、主文风和辅助文风层级。请在不改变场景目标、冲突、转折、结果和既定事实的前提下，删除重复解释、重复感受和不推动情节的细节，返回完整替换稿。必须重新按 Schema 检查 goal、conflict、turn、outcome。draft.foreshadowing_coverage 是伏笔 Coverage 的身份模板；返回数组必须保持完全相同的长度、顺序、foreshadowing_id 和 action，模板为空时必须返回 []。current_scene_foreshadowing_actions 只用于重新判断 status 和逐字 evidence，不得加入其他 Scene 的动作。只有最终正文足以证明 acceptance_criteria 时才能标记 fulfilled。所有 fulfilled 和 contradicted 的 evidence 必须逐字引用最终 content，missing 的 evidence 必须为 null。最终正文不得超过 maximum_scene_words；字数统计排除空白和换行。不得截断句子，不得输出摘要或解释，不得新增重大事实。返回符合 Schema 的 JSON，所有自然语言使用简体中文。'
                    : '你是 XNovel 场景扩写器。输入包含一份字数不足的场景草稿。l4 是唯一的 Style Contract；扩写后必须保持其中的 POV、时态、主文风和辅助文风层级。请在不改变场景目标、冲突、转折、结果和既定事实的前提下，将它扩写为完整替换稿。必须保留原有有效内容，通过动作过程、对话反应、环境感官、人物心理和自然过渡补足细节。必须重新按 Schema 检查 goal、conflict、turn、outcome。draft.foreshadowing_coverage 是伏笔 Coverage 的身份模板；返回数组必须保持完全相同的长度、顺序、foreshadowing_id 和 action，模板为空时必须返回 []。current_scene_foreshadowing_actions 只用于重新判断 status 和逐字 evidence，不得加入其他 Scene 的动作。只有最终正文足以证明 acceptance_criteria 时才能标记 fulfilled。所有 fulfilled 和 contradicted 的 evidence 必须逐字引用最终 content，missing 的 evidence 必须为 null。完整正文至少达到 required_scene_words，并尽量接近 scene_target_words，且不得超过 maximum_scene_words；字数统计排除空白和换行。不得输出提纲、摘要、解释或无意义重复，不得新增重大事实、能力、世界规则或角色知识。返回符合 Schema 的 JSON，所有自然语言使用简体中文。').NarrativeProsePolicy::writing(),
                prompt: ($tooLong ? '请压缩以下超限场景：' : '请扩写以下短稿：').json_encode([
                    'scene_task' => $context['scene_task'],
                    'writing_constraints' => $constraints,
                    'l4' => $context['l4'],
                    'current_scene_foreshadowing_actions' => $currentSceneForeshadowingActions,
                    'must_not_reveal' => data_get($context, 'l0.plan_constraints.must_not_reveal', []),
                    'repair_attempt' => $attempt,
                    'current_words' => $actual,
                    'required_reduction_words' => $tooLong ? $actual - $maximum : 0,
                    'draft' => $payload,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.4,
                maxTokens: $budget['max_completion_tokens'],
                responseSchema: SceneDraftPayload::schema(),
                promptVersion: $promptVersion,
                metadata: [...$metadata, 'stage' => AiStage::Rewrite->value, 'substage' => 'length_repair', 'route_key' => 'length_repair', 'length_repair_attempt' => $attempt, 'length_repair_mode' => $tooLong ? 'compress' : 'expand', 'repair_budget_tier' => $attempt === 1 ? 'initial' : 'retry'],
            );
            // 长度修复切换到 Rewrite Route 后，也必须按该 Route 的冻结模型容量重新门禁。
            $this->outputCapacity->assertRequestFromMetadata(
                $request,
                AiStage::Rewrite,
                'length_repair',
                $budget,
                $repairContract['tiers'],
            );
            $response = $this->provider->generate($request);

            $repairedPayload = $response->structuredData;
            if (is_array($repairedPayload)) {
                $repairedPayload['foreshadowing_coverage'] = ForeshadowingCoverage::reconcileWithIdentityTemplate(
                    is_array($repairedPayload['foreshadowing_coverage'] ?? null) ? $repairedPayload['foreshadowing_coverage'] : [],
                    $foreshadowingCoverageTemplate,
                );
            }
            $afterResponse?->__invoke($attempt, is_array($repairedPayload) ? $repairedPayload : $payload);
            $repairedPayload = StructuredOutput::require($response, 'scene_length_repair', 'Scene Length Repair');
            $repairedPayload['foreshadowing_coverage'] = ForeshadowingCoverage::reconcileWithIdentityTemplate(
                is_array($repairedPayload['foreshadowing_coverage'] ?? null) ? $repairedPayload['foreshadowing_coverage'] : [],
                $foreshadowingCoverageTemplate,
            );
            $payload = $this->validatePayloadWithCoverageRepair(
                payload: $repairedPayload,
                context: $context,
                provider: $extractorProvider ?? $provider,
                model: $extractorModel ?? $model,
                reasoningEffort: $extractorReasoningEffort ?? $reasoningEffort,
                metadata: [...$metadata, 'route_key' => 'structure_and_coverage'],
                beforeRequest: $beforeRequest,
                repairState: $repairState,
                afterRepairResponse: $afterRepairResponse,
            );
        }

        return $payload;
    }

    /**
     * 保持正文不变，只修复 Coverage 引用；修复耗尽时保留“证据未验证”来源，不能伪装成语义缺失。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function validatePayloadWithCoverageRepair(array $payload, array $context, string $provider, string $model, ?string $reasoningEffort, array $metadata, ?callable $beforeRequest = null, array $repairState = [], ?callable $afterRepairResponse = null): array
    {
        $structureRepaired = false;
        $coverageRepaired = false;
        $foreshadowingCoverageRepaired = false;
        $coverageUnverified = [];
        $foreshadowingExpectations = ForeshadowingCoverage::expectationsForScene(
            data_get($context, 'l0.foreshadowing_contract', []),
            (int) data_get($context, 'scene_task.sequence'),
        );
        $startingAttempt = static fn (string $kind): int => ($repairState['repair_kind'] ?? null) === $kind
            ? ((int) ($repairState['repair_attempt'] ?? 0)) + 1
            : 1;

        while (true) {
            try {
                $validated = SceneDraftPayload::validate($payload, $foreshadowingExpectations);

                return [
                    ...$validated,
                    'coverage_evidence_unverified' => $coverageUnverified,
                ];
            } catch (ValidationException $exception) {
                if (! $structureRepaired && $this->containsOnlyStructureErrors($exception)) {
                    $payload = [
                        ...$payload,
                        ...$this->structureRepairer->repair(
                            payload: $payload,
                            provider: $provider,
                            model: $model,
                            metadata: $metadata,
                            sceneTask: $context['scene_task'] ?? null,
                            reasoningEffort: $reasoningEffort,
                            beforeRequest: $beforeRequest,
                            startingAttempt: $startingAttempt('structure'),
                            afterResponse: function (?array $response, int $attempt) use (&$payload, $afterRepairResponse): void {
                                $afterRepairResponse?->__invoke('structure', $attempt, is_array($response) ? [...$payload, ...$response] : $payload);
                            },
                        ),
                    ];
                    $structureRepaired = true;

                    continue;
                }

                if (! $coverageRepaired && $this->containsOnlyCoverageEvidenceErrors($exception)) {
                    $reportedCoverage = is_array($payload['self_check'] ?? null) ? $payload['self_check'] : [];
                    $payload['self_check'] = $this->coverageEvidenceRepairer->repair(
                        coverage: $reportedCoverage,
                        content: is_string($payload['content'] ?? null) ? $payload['content'] : '',
                        provider: $provider,
                        model: $model,
                        metadata: $metadata,
                        task: $context['scene_task'] ?? null,
                        path: 'self_check',
                        reasoningEffort: $reasoningEffort,
                        beforeRequest: $beforeRequest,
                        startingAttempt: $startingAttempt('coverage'),
                        afterResponse: function (?array $response, int $attempt) use (&$payload, $afterRepairResponse): void {
                            $afterRepairResponse?->__invoke('coverage', $attempt, [
                                ...$payload,
                                'self_check' => is_array($response) ? $response : ($payload['self_check'] ?? null),
                            ]);
                        },
                    );
                    // 修复耗尽后的 missing 只用于维持 Artifact Schema，原语义声明必须单独留痕等待复核。
                    $coverageUnverified = PlanCoverage::unverifiedElements($reportedCoverage, $payload['self_check']);
                    $coverageRepaired = true;

                    continue;
                }

                if (! $foreshadowingCoverageRepaired && $this->containsOnlyForeshadowingCoverageEvidenceErrors($exception)) {
                    $payload['foreshadowing_coverage'] = $this->foreshadowingCoverageEvidenceRepairer->repair(
                        coverage: is_array($payload['foreshadowing_coverage'] ?? null) ? $payload['foreshadowing_coverage'] : [],
                        content: is_string($payload['content'] ?? null) ? $payload['content'] : '',
                        expectations: $foreshadowingExpectations,
                        provider: $provider,
                        model: $model,
                        metadata: $metadata,
                        path: 'foreshadowing_coverage',
                        reasoningEffort: $reasoningEffort,
                        beforeRequest: $beforeRequest,
                        startingAttempt: $startingAttempt('foreshadowing_coverage'),
                        afterResponse: function (?array $response, int $attempt) use (&$payload, $afterRepairResponse): void {
                            $afterRepairResponse?->__invoke('foreshadowing_coverage', $attempt, [
                                ...$payload,
                                'foreshadowing_coverage' => is_array(data_get($response, 'coverage'))
                                    ? data_get($response, 'coverage')
                                    : ($payload['foreshadowing_coverage'] ?? []),
                            ]);
                        },
                    );
                    $foreshadowingCoverageRepaired = true;

                    continue;
                }

                throw $exception;
            }
        }
    }

    private function containsOnlyStructureErrors(ValidationException $exception): bool
    {
        $fields = array_keys($exception->errors());

        return $fields !== [] && collect($fields)->every(
            fn (string $field): bool => $field === 'temporary_state_delta' || str_starts_with($field, 'declared_events'),
        );
    }

    private function containsOnlyCoverageEvidenceErrors(ValidationException $exception): bool
    {
        $fields = array_keys($exception->errors());

        return $fields !== [] && collect($fields)->every(
            fn (string $field): bool => preg_match('/^self_check\.(goal|conflict|turn|outcome)\.evidence$/', $field) === 1,
        );
    }

    private function containsOnlyForeshadowingCoverageEvidenceErrors(ValidationException $exception): bool
    {
        $fields = array_keys($exception->errors());

        return $fields !== [] && collect($fields)->every(
            fn (string $field): bool => preg_match('/^foreshadowing_coverage\.\d+\.evidence$/', $field) === 1,
        );
    }

    /** @return array<string, int> */
    private function sceneAllocation(Scene $scene, int $chapterTarget): array
    {
        $otherScenes = $scene->chapter->scenes->where('id', '!=', $scene->getKey());
        $allocatedWords = $otherScenes->sum(fn (Scene $other): int => $this->lengthPolicy->count($other->currentArtifact?->content));
        $remainingSceneCount = 1 + $otherScenes->whereNull('current_artifact_id')->count();

        return $this->lengthPolicy->sceneAllocation(
            $chapterTarget,
            $allocatedWords,
            $remainingSceneCount,
            $scene->chapter->scenes->count(),
        );
    }

    private function failRun(GenerationRun $run, Throwable $exception): void
    {
        $this->failurePolicy->record(
            $run,
            $exception,
            $exception instanceof ValidationException ? 'scene_validation_failed' : 'scene_generation_failed',
        );
    }

    /** @return array{substage: string, payload: array<string, mixed>, artifact_id: int, generation_run_id: int, repair_kind?: string, repair_attempt?: int}|null */
    private function latestCheckpoint(Scene $scene, string $inputHash): ?array
    {
        $artifacts = GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('scene_id', $scene->getKey())
                ->where('stage', GenerationStage::SceneGeneration)
                ->where('input_hash', $inputHash))
            ->latest('id')
            ->get();

        $artifact = $artifacts->first(fn (GenerationArtifact $candidate): bool => in_array(
            data_get($candidate->data, 'checkpoint'),
            ['length_validated', 'evidence_validated', 'repair_response', 'length_repair_response', 'prose_generated'],
            true,
        ) && is_array(data_get($candidate->data, 'payload')));

        if ($artifact !== null) {
            return array_filter([
                'substage' => data_get($artifact->data, 'checkpoint'),
                'payload' => data_get($artifact->data, 'payload'),
                'artifact_id' => $artifact->getKey(),
                'generation_run_id' => $artifact->generation_run_id,
                'repair_kind' => data_get($artifact->data, 'repair_kind'),
                'repair_attempt' => data_get($artifact->data, 'repair_attempt'),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return null;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $checkpointMetadata */
    private function saveCheckpoint(GenerationRun $run, string $substage, array $payload, string $inputHash, ?string $sourceCheckpoint, array $checkpointMetadata = []): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $substage, $payload, $inputHash, $sourceCheckpoint, $checkpointMetadata): GenerationArtifact {
            $lockedRun = GenerationRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $existing = $lockedRun->artifacts()
                ->where('type', ArtifactType::Context)
                ->get()
                ->first(fn (GenerationArtifact $artifact): bool => data_get($artifact->data, 'checkpoint') === $substage);

            if ($existing !== null) {
                return $existing;
            }

            $substageFingerprint = app(GenerationStageFingerprint::class)->make(
                GenerationStage::SceneGeneration,
                ['checkpoint' => $substage, 'payload' => $payload, 'metadata' => $checkpointMetadata],
                upstreamChecksums: array_values(array_filter([$inputHash, $sourceCheckpoint])),
                frozen: ['state_version' => $lockedRun->state_version, 'prompt_version' => $lockedRun->prompt_version],
                contractVersion: 'scene-substage-checkpoint-v1',
            );

            $data = array_filter([
                'checkpoint' => $substage,
                'source_checkpoint' => $sourceCheckpoint,
                'input_hash' => $inputHash,
                'substage_fingerprint' => $substageFingerprint,
                'state_version' => $lockedRun->state_version,
                'prompt_version' => $lockedRun->prompt_version,
                ...$checkpointMetadata,
                'payload' => $payload,
            ], static fn (mixed $value): bool => $value !== null);

            return $lockedRun->artifacts()->create([
                'type' => ArtifactType::Context,
                'version' => ((int) $lockedRun->artifacts()->where('type', ArtifactType::Context)->max('version')) + 1,
                'content' => is_string($payload['content'] ?? null) ? $payload['content'] : null,
                'data' => $data,
                'checksum' => $substageFingerprint,
            ]);
        });
    }

    private function checkpointRank(?string $substage): int
    {
        return match ($substage) {
            'prose_generated', 'repair_response', 'length_repair_response' => 1,
            'evidence_validated' => 2,
            'length_validated' => 3,
            default => 0,
        };
    }

    /** @return array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int} */
    private function resolveRequestBudget(GenerationRun $run, string $baseKey): array
    {
        $priorRuns = GenerationRun::query()
            ->where('scene_id', $run->scene_id)
            ->where('stage', GenerationStage::SceneGeneration)
            ->where('provider', $run->provider)
            ->where('model_policy', $run->model_policy)
            ->where('id', '<', $run->getKey())
            ->whereNotNull('error_code')
            ->orderBy('id')
            ->get(['idempotency_key', 'error_code', 'context_snapshot'])
            ->filter(fn (GenerationRun $prior): bool => $prior->idempotency_key === $baseKey
                || str_starts_with($prior->idempotency_key, $baseKey.':attempt:'))
            ->values();
        $budgets = (array) data_get($run->context_snapshot, 'generation_preferences.request_budgets', []);
        $selection = $this->requestBudget->resolveForAttempt($budgets, AiStage::Writer, $priorRuns, 'scene', 'Scene Draft');
        $budget = $selection['budget'];
        $snapshot = $run->context_snapshot ?? [];
        data_set($snapshot, 'generation_preferences.scene_retry_ordinal', $selection['ordinal']);
        data_set($snapshot, 'generation_preferences.request_budget_tier', $selection['tier']);
        data_set($snapshot, 'generation_preferences.request_budget_trigger', $selection['trigger']);
        data_set($snapshot, 'generation_preferences.selected_request_budget', $budget);
        data_set($snapshot, 'generation_preferences.max_completion_tokens', $budget['max_completion_tokens']);
        $run->update(['context_snapshot' => $snapshot]);

        return $budget;
    }

    private function assertProviderCallFits(GenerationRun $run, int $jobStartedAt, string $provider, string $substage): void
    {
        // The heartbeat write also proves PostgreSQL is reachable before an external request starts.
        $run->touch();
        $softTimeout = (int) config('generation.scene_job_soft_timeout_seconds', 300);
        $safetyMargin = (int) config('generation.scene_job_safety_margin_seconds', 20);
        $providerTimeout = (int) config("ai.providers.{$provider}.timeout", 150);
        $elapsed = $this->executionClock->elapsedSeconds($jobStartedAt);
        $remaining = max(0, $softTimeout - $elapsed);
        $required = $providerTimeout + $safetyMargin;
        $snapshot = $run->fresh()->context_snapshot ?? [];
        $snapshot['scene_execution'] = [
            ...(is_array($snapshot['scene_execution'] ?? null) ? $snapshot['scene_execution'] : []),
            'owning_stage' => GenerationStage::SceneGeneration->value,
            'substage' => $substage,
            'job_attempt' => $run->attempt,
            'elapsed_seconds' => $elapsed,
            'remaining_job_seconds_at_dispatch' => $remaining,
            'provider_timeout_seconds' => $providerTimeout,
        ];
        $run->update(['context_snapshot' => $snapshot]);

        if ($remaining < $required) {
            throw new SceneStageDeferredException($substage, $remaining, $required);
        }
    }

    private function sentReasoningEffort(?string $reasoningEffort): ?string
    {
        // 当前已注册的文本 Provider 都会发送显式 reasoning_effort；留空仍表示采用 Provider 默认值。
        return $reasoningEffort;
    }

    /** 保存子阶段所使用的冻结 Route、容量和修复预算，供运行详情直接展示。 */
    private function routeSnapshot(Chapter $chapter, object $settings, AiStage $stage, string $promptVersion, ?int $maxTokens): array
    {
        $route = $this->outputCapacity->frozenRoute($chapter, $stage);

        return array_filter([
            'stage' => $stage->value,
            'provider' => $settings->provider,
            'model' => $settings->model,
            'prompt_version' => $promptVersion,
            'reasoning_effort_configured' => $settings->reasoningEffort,
            'reasoning_effort_sent' => $this->sentReasoningEffort($settings->reasoningEffort),
            'max_completion_tokens' => $maxTokens,
            'model_capacity' => $route['model_capacity'],
            'request_budgets' => $route['request_budgets'],
            'repair_request_budgets' => $route['repair_request_budgets'],
        ], static fn (mixed $value): bool => $value !== null);
    }
}
