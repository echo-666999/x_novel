<?php

namespace App\Services;

use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\NarrativeProsePolicy;
use App\AI\PromptVersionResolver;
use App\AI\StructuredOutput;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\ForeshadowingPlanAction;
use App\Enums\ForeshadowingTimingStatus;
use App\Enums\GenerationStage;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Exceptions\GenerationPreflightException;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChapterPlanner
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly PromptVersionResolver $promptVersionResolver,
        private readonly PlanValidator $planValidator,
        private readonly ClosureDebtService $closureDebt,
        private readonly SyncScenesFromChapterPlanAction $syncScenes,
        private readonly NarrativeStyleProfile $narrativeStyleProfile,
        private readonly ContextBuilder $contextBuilder,
        private readonly PreviousChapterEnding $previousChapterEnding,
        private readonly GenerationRunLease $runLease,
        private readonly ForeshadowingPlanningGate $foreshadowingPlanningGate,
        private readonly ForeshadowingLifecycleResolver $foreshadowingLifecycleResolver,
        private readonly StoryArcBeatContract $storyArcBeatContract,
        private readonly OutlineContextBuilder $outlineContextBuilder,
        private readonly GenerationFailurePolicy $failurePolicy,
    ) {}

    public function generate(int $chapterId, bool $regenerate = false): ?ChapterPlan
    {
        $chapter = Chapter::query()->with(['novel.canonicalStateVersion', 'volume'])->findOrFail($chapterId);
        $novel = $chapter->novel;

        if ($novel->status->value === 'paused') {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始新的规划阶段。', false);
        }

        $this->foreshadowingPlanningGate->assertModelPlanningAllowed($chapter);

        $settings = $this->settingsResolver->resolve(AiStage::Planner, $novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Planner);
        $context = $this->context($chapter, $regenerate);
        $context['prompt_version'] = $promptVersion;
        $context['generation_preferences']['planner_token_budget'] = [
            'initial_max_completion_tokens' => (int) config('generation.planner_max_output_tokens', 12_000),
            'retry_max_completion_tokens' => (int) config('generation.planner_retry_max_output_tokens', 16_000),
            'final_retry_max_completion_tokens' => (int) config('generation.planner_final_retry_max_output_tokens', 24_000),
        ];
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'prompt_version' => $promptVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $baseKey = "plan:{$chapter->getKey()}:{$context['state_version']}:{$context['bible_version']}:".
            ($context['novel_outline_id'] ?? 'legacy').':'.($context['outline_checksum'] ?? 'legacy').":{$promptVersion}:".
            hash('sha256', $settings->provider.'|'.$settings->model.'|'.($settings->reasoningEffort ?? 'default').'|'.$settings->source);

        [$run, $reused] = $this->startRun($chapter, $baseKey, $inputHash, $context, $settings->provider, $settings->model, $promptVersion, $regenerate);

        if ($reused) {
            $planId = data_get($run->context_snapshot, 'chapter_plan_id');

            return is_numeric($planId) ? ChapterPlan::query()->find((int) $planId) : null;
        }

        try {
            $maxTokens = $this->resolveRequestBudget($run, $baseKey);
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                reasoningEffort: $settings->reasoningEffort,
                systemPrompt: $this->systemPrompt($novel),
                prompt: '请根据以下权威上下文创建下一章可执行计划。除固定 JSON 字段和枚举值外，所有自然语言内容必须使用简体中文。'
                    .'引用规则：pov_character_id 只能使用 characters[].id；required_facts 只能使用 active_facts[].id，active_facts 为空时必须返回 []；'
                    .'foreshadowing_actions 只能引用 foreshadowings_requiring_action[].id，并且 action 必须来自对应 allowed_model_actions；没有任务时必须返回 []。'
                    .'存在 current_outline_target 时，novel_outline_id 必须逐字复制；arc_contributions 必须恰有一个 role=primary，并分别令 arc_id=primary_arc_id、beat_key=primary_beat_key、beat_index=primary_beat_sequence，再指定目标 Scene 与 acceptance_criteria 中的一项；支线只能从 active_arcs 中 type=subplot 的真实 Beat 逐字复制并标记 role=secondary，不能替代 Main Primary Beat。历史上下文没有 current_outline_target 时 novel_outline_id 返回 null，arc_contributions 继续从 active_arcs[].beats 复制并标记 role=secondary，没有推进项时返回 []。'
                    .'存在 current_outline_target 时，character_candidates 和 world_entity_candidates 只能从对应数组中选择并逐字段复制，当前节点没有 Candidate 时必须返回 []；历史上下文没有 current_outline_target 时 character_candidates 必须返回 []。Beat 的 must_include 必须合并到 must_reveal，must_not_include 必须合并到 must_not_reveal 或 forbidden_conflicts。'
                    .'world_entity_candidates 只用于剧情确实需要且 existing_world_entities 中不存在的重大地点、物品、阵营、组织、规则或概念；必须使用稳定 candidate_key、说明去重依据和目标 Scene，不需要新实体时返回 []。'
                    .'每个伏笔动作必须指定目标 Scene 序号和可由正文验收的 acceptance_criteria。模型禁止选择 defer 或 abandon；这两类动作只能由用户在计划编辑页明确授权。'
                    .'每个 Scene 的 outcome_allowed 必须列出该结果允许的具体行为，outcome_forbidden 必须列出会反转或越过该结果的行为；没有边界项时返回 []。'
                    .'跨 Scene 持续信息必须使用 continuity_requirements 和稳定 key 明确分为 establish（本章首次出现）、persist（本章内持续但只写增量）、change（本章内后续场景必须变化）或 callback（章末允许回扣）。每个 key 按本章 Scene 顺序首次出现时必须使用 establish，即使该状态继承自 previous_chapter_ending 或 Canonical Story State，也要在首个 Scene 用 establish 说明本章起始基线；只有较早 Scene 已 establish 后才能使用 persist、change 或 callback，callback 只能位于章末 Scene。不得把同一持续状态重复写进多个 Scene 的 goal/conflict/turn/outcome。'
                    .'每个 Scene Plan 都必须返回 transition_from_previous；第一场景应说明如何承接 previous_chapter_ending，若没有上一章则返回 null，后续场景说明如何承接前一场景。上下文：'
                    .json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.4,
                maxTokens: $maxTokens,
                responseSchema: ChapterPlanPayload::schema(),
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $novel->getKey(),
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Planner->value,
                ],
            ));

            $payload = ChapterPlanPayload::validate(
                StructuredOutput::require($response, 'plan', 'Chapter Plan'),
            );
            $payload = $this->applyOutlineContract($payload, $context['current_outline_target']);
            $payload['target_words'] = (int) data_get($novel->settings, 'generation.chapter_target_words', $payload['target_words']);
            $candidate = new ChapterPlan($payload);
            $candidate->setRelation('chapter', $chapter);
            $this->planValidator->validate($candidate)->assertCanGenerate();

            return $this->complete($run, $chapter, $payload, $response->content, $context['state_version'], $regenerate);
        } catch (Throwable $exception) {
            $this->fail($run, $exception);
            throw $exception;
        }
    }

    private function resolveRequestBudget(GenerationRun $run, string $baseKey): int
    {
        $priorTruncatedRuns = GenerationRun::query()
            ->where('chapter_id', $run->chapter_id)
            ->where('stage', GenerationStage::ChapterPlanning)
            ->where('provider', $run->provider)
            ->where('model_policy', $run->model_policy)
            ->where('id', '<', $run->getKey())
            ->whereIn('error_code', ['plan_output_truncated', 'provider_output_truncated'])
            ->get(['idempotency_key', 'context_snapshot'])
            ->filter(fn (GenerationRun $prior): bool => $prior->idempotency_key === $baseKey
                || str_starts_with($prior->idempotency_key, $baseKey.':attempt:'))
            ->values();
        $retryOrdinal = max($priorTruncatedRuns->count() + 1, min($run->attempt, 3));
        $budget = (array) data_get($run->context_snapshot, 'generation_preferences.planner_token_budget', []);
        $maxTokens = match ($retryOrdinal) {
            1 => (int) ($budget['initial_max_completion_tokens'] ?? 12_000),
            2 => (int) ($budget['retry_max_completion_tokens'] ?? 16_000),
            default => (int) ($budget['final_retry_max_completion_tokens'] ?? 24_000),
        };
        $priorMaximum = $priorTruncatedRuns
            ->map(fn (GenerationRun $prior): int => (int) data_get($prior->context_snapshot, 'generation_preferences.max_completion_tokens', 0))
            ->max();
        $snapshot = $run->context_snapshot ?? [];
        data_set($snapshot, 'generation_preferences.planner_retry_ordinal', $retryOrdinal);
        data_set($snapshot, 'generation_preferences.max_completion_tokens', $maxTokens);
        $run->update(['context_snapshot' => $snapshot]);

        if (is_int($priorMaximum) && $priorMaximum >= $maxTokens) {
            throw new AiProviderException(
                'plan_output_budget_exhausted',
                "Chapter Plan 已在冻结的最高输出预算 {$maxTokens} Token 下被截断；请提高预算或调整 Planner 模型后再重试。",
                false,
            );
        }

        return $maxTokens;
    }

    /**
     * Outline 中的引用和硬约束已经由 Laravel 冻结，不应依赖模型逐字复写。
     * 模型负责选择场景与补充计划；这里恢复权威原文后再交给 PlanValidator 审核。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $outlineTarget
     * @return array<string, mixed>
     */
    private function applyOutlineContract(array $payload, ?array $outlineTarget): array
    {
        if ($outlineTarget === null) {
            return $payload;
        }

        $payload['novel_outline_id'] = (int) $outlineTarget['novel_outline_id'];
        $payload['must_reveal'] = $this->mergeAuthoritativeConstraints(
            (array) ($outlineTarget['must_include'] ?? []),
            (array) ($payload['must_reveal'] ?? []),
        );
        $payload['must_not_reveal'] = $this->mergeAuthoritativeConstraints(
            (array) ($outlineTarget['must_not_include'] ?? []),
            (array) ($payload['must_not_reveal'] ?? []),
        );

        foreach ($payload['arc_contributions'] as &$contribution) {
            if (($contribution['role'] ?? null) !== 'primary') {
                continue;
            }

            $contribution['arc_id'] = (int) $outlineTarget['primary_arc_id'];
            $contribution['beat_key'] = (string) $outlineTarget['primary_beat_key'];
            $contribution['beat_index'] = (int) $outlineTarget['primary_beat_sequence'];
        }
        unset($contribution);

        foreach (['character_candidates', 'world_entity_candidates'] as $field) {
            $contracts = collect($outlineTarget[$field] ?? [])->keyBy('candidate_key');
            $payload[$field] = collect($payload[$field] ?? [])->map(
                fn (mixed $candidate): mixed => is_array($candidate) && $contracts->has($candidate['candidate_key'] ?? null)
                    ? $contracts->get($candidate['candidate_key'])
                    : $candidate,
            )->values()->all();
        }

        return $payload;
    }

    /** @param array<int, mixed> $authoritative @param array<int, mixed> $generated @return array<int, string> */
    private function mergeAuthoritativeConstraints(array $authoritative, array $generated): array
    {
        $authoritative = collect($authoritative)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values();

        $supplemental = collect($generated)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->reject(function (string $value) use ($authoritative): bool {
                return $authoritative->contains(function (string $contract) use ($value): bool {
                    if ($value === $contract) {
                        return true;
                    }

                    $suffix = mb_substr($value, mb_strlen($contract), 1);

                    return str_starts_with($value, $contract)
                        && in_array($suffix, ['：', ':', '。', '；', ';', '，', ','], true);
                });
            })
            ->unique()
            ->values();

        return $authoritative->concat($supplemental)->values()->all();
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $provider, string $model, string $promptVersion, bool $regenerate): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $provider, $model, $promptVersion, $regenerate): array {
            Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = GenerationRun::query()->where('chapter_id', $chapter->getKey())->where('stage', GenerationStage::ChapterPlanning);

            $activeRun = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();

            if ($this->runLease->isFresh($activeRun)) {
                return [$activeRun, true];
            }

            if ($activeRun !== null) {
                $activeRun->update([
                    'status' => RunStatus::Failed,
                    'error_code' => 'worker_interrupted',
                    'error_message' => '规划 Run 超时未完成，已由后续投递恢复。',
                    'error_retryable' => false,
                    'error_metadata' => ['category' => 'worker_lost'],
                    'finished_at' => now(),
                ]);
            }

            if (! $regenerate && ($succeeded = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$succeeded, true];
            }

            $attempt = ((int) $runs->clone()->max('attempt')) + 1;
            $key = $attempt === 1 ? $baseKey : $baseKey.":attempt:{$attempt}";

            return [GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::ChapterPlanning,
                'status' => RunStatus::Running,
                'attempt' => $attempt,
                'idempotency_key' => $key,
                'input_hash' => $inputHash,
                'state_version' => $context['state_version'],
                'bible_version' => $context['bible_version'],
                'prompt_version' => $promptVersion,
                'provider' => $provider,
                'model_policy' => $model,
                'context_snapshot' => $context,
                'started_at' => now(),
            ]), false];
        });
    }

    private function complete(GenerationRun $run, Chapter $chapter, array $payload, string $content, int $expectedStateVersion, bool $regenerate): ChapterPlan
    {
        return DB::transaction(function () use ($run, $chapter, $payload, $content, $expectedStateVersion, $regenerate): ChapterPlan {
            $novel = Novel::query()->lockForUpdate()->findOrFail($chapter->novel_id);
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());

            if ($novel->canonicalStateVersion()->value('version') !== $expectedStateVersion) {
                throw new AiProviderException(
                    'state_version_conflict',
                    '生成期间 Canonical Story State 已变化，请基于最新状态重新规划。',
                    false,
                );
            }

            $expectedOutlineId = data_get($run->context_snapshot, 'novel_outline_id');
            $expectedOutlineChecksum = data_get($run->context_snapshot, 'outline_checksum');
            $outlineChanged = ($expectedOutlineId === null) !== ($novel->current_outline_id === null)
                || ($expectedOutlineId !== null && (int) $novel->current_outline_id !== (int) $expectedOutlineId)
                || ($expectedOutlineId !== null && $novel->currentOutline()->value('checksum') !== $expectedOutlineChecksum);
            if ($outlineChanged) {
                throw new AiProviderException(
                    'outline_version_conflict',
                    '生成期间 Current Novel Outline 已变化，请基于最新大纲重新规划。',
                    false,
                );
            }

            $version = ((int) $chapter->plans()->max('version')) + 1;
            $chapter->plans()->where('status', PlanStatus::Ready)->update(['status' => PlanStatus::Superseded]);
            $plan = $chapter->plans()->create(['version' => $version, 'status' => PlanStatus::Ready, ...$payload]);
            $checksum = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $run->artifacts()->create(['type' => ArtifactType::ChapterPlan, 'version' => 1, 'content' => $content, 'data' => $payload, 'checksum' => $checksum]);
            $run->update([
                'status' => RunStatus::Succeeded,
                'context_snapshot' => [...($run->context_snapshot ?? []), 'chapter_plan_id' => $plan->getKey()],
                'finished_at' => now(),
            ]);
            $this->syncScenes->execute(
                $chapter,
                replaceGenerated: $regenerate && $chapter->status === ChapterStatus::Void,
            );
            $chapter->update(['status' => ChapterStatus::Generating]);

            return $plan;
        });
    }

    private function fail(GenerationRun $run, Throwable $exception): void
    {
        $this->failurePolicy->record(
            $run,
            $exception,
            $exception instanceof ValidationException ? 'plan_validation_failed' : 'plan_generation_failed',
        );
    }

    /** @return array<string, mixed> */
    private function context(Chapter $chapter, bool $restartPipeline): array
    {
        $novel = $chapter->novel;
        $bible = $restartPipeline
            ? $novel->currentBible()->first()
            : $novel->bibles()->where('version', $this->contextBuilder->bibleVersionForChapter($chapter))->first();

        if ($bible === null || $novel->canonicalStateVersion === null || $chapter->volume === null) {
            throw new AiProviderException('planner_context_incomplete', 'Chapter Planner 缺少 Bible、Story State 或 Current Volume。', false);
        }

        $styleContract = $this->narrativeStyleProfile->contractForBible($bible);
        $outlineContext = null;
        if ($novel->current_outline_id !== null) {
            $outlineContext = $this->outlineContextBuilder->build($novel);
            $maximum = data_get($outlineContext, 'chapter_budget.max');
            if (is_int($maximum) && $outlineContext['chapters_used_for_current_beat'] >= $maximum) {
                throw new GenerationPreflightException(
                    'outline_beat_budget_exhausted',
                    "当前 Outline Beat「{$outlineContext['beat']['title']}」已使用 {$outlineContext['chapters_used_for_current_beat']} 章，达到预算上限 {$maximum}；自动 Planner 已在调用模型前停止。",
                );
            }
        }

        $context = [
            'novel' => ['id' => $novel->getKey(), 'title' => $novel->title, 'status' => $novel->status->value],
            'generation_preferences' => [
                'chapter_target_words' => (int) data_get($novel->settings, 'generation.chapter_target_words', 3_000),
            ],
            'chapter' => ['id' => $chapter->getKey(), 'sequence' => $chapter->sequence],
            'bible_version' => $bible->version,
            'style_contract_checksum' => $styleContract['checksum'],
            'l4' => $styleContract,
            'bible' => $bible->only(['logline', 'themes', 'tone', 'pov', 'tense', 'taboos', 'hard_constraints', 'ending_contract']),
            'state_version' => $novel->canonicalStateVersion->version,
            ...($outlineContext ?? []),
            'current_outline_target' => $outlineContext,
            'story_state' => $novel->canonicalStateVersion->state,
            'volume' => $chapter->volume->only(['id', 'sequence', 'title', 'goal', 'climax', 'target_words']),
            'active_arcs' => $novel->storyArcs()->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('volume_id')->orWhere('volume_id', $chapter->volume_id))
                ->get()
                ->map(fn ($arc): array => [
                    ...$arc->only(['id', 'volume_id', 'type', 'title', 'goal', 'stakes', 'completion_conditions', 'progress']),
                    'beats' => $this->storyArcBeatContract->forArc($arc),
                ])->all(),
            'existing_world_entities' => $novel->worldEntities()->where('status', 'active')->get()
                ->map->only(['id', 'type', 'name', 'description'])->all(),
            'characters' => $novel->characters()->get()->map->only(['id', 'name', 'role', 'status', 'goals', 'knowledge'])->all(),
            'active_facts' => $novel->facts()->where('status', 'active')->get()->map->only(['id', 'subject_type', 'subject_id', 'predicate', 'value', 'locked'])->all(),
            'foreshadowings_requiring_action' => $this->foreshadowingContext($chapter),
            'recent_summaries' => $novel->chapters()
                ->where('status', ChapterStatus::Canonical)
                ->where('sequence', '<', $chapter->sequence)
                ->whereNotNull('summary')
                ->reorder('sequence', 'desc')
                ->limit(10)
                ->get(['sequence', 'summary'])
                ->reverse()
                ->values()
                ->all(),
            'previous_chapter_ending' => $this->previousChapterEnding->for($chapter),
        ];

        if ($novel->status->value === 'completing') {
            $debt = $this->closureDebt->calculate($novel);
            $context['closing_restrictions'] = [
                'active' => true,
                'forbidden_new_elements' => [
                    'core_character',
                    'main_story_arc',
                    'hard_world_rule',
                    'high_importance_foreshadowing',
                ],
                'instruction' => '推进 Ending Contract 或降低 Closure Debt，不得开启新的核心故事义务。',
            ];
            $context['closure_debt'] = [
                'total' => $debt->total(),
                'critical' => $debt->critical(),
                'items' => $debt->toArray(),
            ];
        }

        return $context;
    }

    /** @return array<int, array<string, mixed>> */
    private function foreshadowingContext(Chapter $chapter): array
    {
        $novel = $chapter->novel;
        $foreshadowings = $novel->foreshadowings()
            ->where('due_from_chapter', '<=', $chapter->sequence)
            ->orderBy('due_to_chapter')
            ->get()
            ->reject(fn ($foreshadowing): bool => $this->foreshadowingLifecycleResolver
                ->status($foreshadowing, $novel)
                ->isTerminal());
        $events = $novel->storyEvents()
            ->where('status', 'active')
            ->where('subject_type', 'foreshadowing')
            ->whereIn('subject_id', $foreshadowings->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($event): string => (string) $event->subject_id);

        return $foreshadowings
            ->map(function ($foreshadowing) use ($chapter, $events, $novel): array {
                $status = $this->foreshadowingLifecycleResolver->status($foreshadowing, $novel);
                $timing = ForeshadowingTimingStatus::forTargetChapter(
                    $status,
                    $foreshadowing->due_from_chapter,
                    $foreshadowing->due_to_chapter,
                    $chapter->sequence,
                );

                return [
                    ...$foreshadowing->only([
                        'id', 'title', 'description', 'promised_payoff', 'due_from_chapter',
                        'due_to_chapter', 'importance', 'owner_arc_id', 'setup_chapter_id',
                        'payoff_chapter_id', 'reinforce_count', 'notes',
                    ]),
                    'content_status' => $status->value,
                    'content_status_source' => $this->foreshadowingLifecycleResolver->source($foreshadowing, $novel),
                    'projection_status' => $foreshadowing->status->value,
                    'timing_status' => $timing?->value,
                    'allowed_model_actions' => ForeshadowingPlanAction::modelValuesForStatus($status),
                    'important_events' => $events->get((string) $foreshadowing->getKey(), collect())
                        ->map(fn ($event): array => [
                            'id' => $event->getKey(),
                            'chapter_id' => $event->chapter_id,
                            'scene_id' => $event->scene_id,
                            'event_type' => $event->event_type->value,
                            'payload' => $event->payload,
                            'evidence' => $event->evidence,
                        ])
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function systemPrompt(Novel $novel): string
    {
        $prompt = '你是 XNovel 章节规划器。只返回符合指定 Schema 的 JSON，不得编造任何实体 ID；所有自然语言内容必须使用简体中文。l4 是本次 Pipeline 唯一的 Style Contract：章节 tone 只能在其基调范围内形成局部变体，主文风决定主体表达，辅助文风不得覆盖主文风，POV 与时态不得改变。计划必须连续承接上一章正式结尾。若时间、地点或行动发生跳跃，必须在第一场景的 transition_from_previous 中写明正文要呈现的过渡过程，不得静默跳过。Scene outcome 必须是明确验收结果，并用 outcome_allowed 与 outcome_forbidden 消除行为边界歧义。foreshadowings_requiring_action 是本章必须明确处理的伏笔契约来源；只能按 allowed_model_actions 规划 plant、reinforce 或 pay_off，不得自行延期或放弃。Story Arc 只能引用 active_arcs 中当前分卷或跨卷 Arc 的真实 Beat。重大新世界实体必须先进入 world_entity_candidates，不能只在 Scene 文本中自由创造。';

        if ($novel->status->value === 'completing') {
            $prompt .= ' 当前处于收束阶段：不得新增核心人物、主线、硬世界规则或高重要度伏笔；计划必须推进结局契约或降低收束债务。';
        }

        return $prompt.NarrativeProsePolicy::planning();
    }
}
