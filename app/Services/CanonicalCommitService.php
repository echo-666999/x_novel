<?php

namespace App\Services;

use App\Actions\Chapters\AcceptOverlengthChapterAction;
use App\Contracts\StoryEventApplier;
use App\Data\CanonicalCommitData;
use App\Data\StatePatch;
use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
use App\Enums\ChapterStatus;
use App\Enums\CharacterStatus;
use App\Enums\EventType;
use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\WorldEntityStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\Review;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CanonicalCommitService
{
    public function __construct(
        private readonly StatePatchBuilder $statePatchBuilder,
        private readonly StateValidator $stateValidator,
        private readonly StoryStateService $storyState,
        private readonly EmergencyStopService $emergencyStop,
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly StoryEventApplier $storyEventApplier,
        private readonly StoryArcProgressProjector $storyArcProgressProjector,
        private readonly OutlineCompletionService $outlineCompletion,
        private readonly CanonicalPostCommitDispatcher $postCommitDispatcher,
    ) {}

    public function commit(CanonicalCommitData $data): StoryStateVersion
    {
        [$stateVersion] = DB::transaction(function () use ($data): array {
            $chapter = Chapter::query()->findOrFail($data->chapterId);
            $novel = Novel::query()->lockForUpdate()->findOrFail($chapter->novel_id);
            $task = $novel->current_generation_task_id ? NovelGenerationTask::whereKey($novel->current_generation_task_id)->lockForUpdate()->firstOrFail() : null;
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($data->chapterId);
            if ($task && ($data->taskId !== $task->id || $data->controlVersion !== $task->control_version)) {
                throw new \LogicException('任务或控制版本已改变，不能提交旧预览。');
            }

            if ($chapter->canonical_artifact_id !== null) {
                return [$this->resolveDuplicate($chapter, $data), false];
            }

            $this->emergencyStop->assertCanonicalCommitAllowed();
            $this->validateNovel($novel);
            $currentState = $novel->canonicalStateVersion()->first();

            if ($currentState === null || $currentState->version !== $data->expectedStateVersion) {
                throw ValidationException::withMessages(['state' => 'Expected State Version 与当前 Canonical Story State 不一致。']);
            }

            [$artifact, $review, $candidateArtifact, $patchArtifact] = $this->loadFrozenInputs($chapter, $data);
            if (! $task) {
                $this->validateDraftLength($chapter, $artifact, $review);
            }
            $this->stateValidator->validate($chapter->getKey(), $task ? $candidateArtifact->id : null, $task ? $patchArtifact->id : null, $task ? $artifact->id : null)->assertCanCommit();
            $events = $this->eventCandidates($candidateArtifact);
            $nextVersion = ((int) $novel->storyStateVersions()->max('version')) + 1;
            $nextState = $this->statePatchBuilder->applyArtifact($currentState->state, $patchArtifact);

            if (data_get($patchArtifact->data, 'after_checksum') !== $this->storyState->checksum($nextState)) {
                throw ValidationException::withMessages(['state_patch' => 'State Patch 校验和与应用结果不一致。']);
            }

            $planning = $task ? ['outline_completion' => [], 'arc_beat_audits' => [], 'arc_completion_audits' => [], 'character_candidates' => [], 'world_entity_candidates' => []]
                : $this->validatePlanningAcceptance($chapter, $review, $candidateArtifact, $events);
            $characters = $this->persistCharacterCandidates($novel, $chapter, $planning['character_candidates']);
            $worldEntities = $this->persistWorldEntityCandidates($novel, $chapter, $planning['world_entity_candidates']);
            $events = $this->resolveCharacterEvents($events, $planning['character_candidates'], $characters);
            $events = $this->resolveWorldEntityEvents($events, $planning['world_entity_candidates'], $worldEntities);
            $introductionOperations = collect($events)
                ->flatMap(fn (StoryEventCandidate $event, int $index): array => in_array($event->eventType, [EventType::CharacterIntroduced, EventType::WorldEntityIntroduced], true)
                    ? array_map(
                        fn (array $operation): array => [...$operation, 'source_event_index' => $index],
                        $this->storyEventApplier->operations($event),
                    )
                    : [])
                ->values()->all();
            $nextState = $this->statePatchBuilder->applyPatch(
                $nextState,
                new StatePatch($currentState->version, $introductionOperations),
            );
            $persistedEvents = $this->persistEvents($novel, $chapter, $events, $nextVersion);
            $this->applyFactChanges($novel, $patchArtifact, $persistedEvents);

            $stateVersion = $novel->storyStateVersions()->create([
                'version' => $nextVersion,
                'chapter_id' => $chapter->getKey(),
                'state' => $nextState,
                'checksum' => $this->storyState->checksum($nextState),
            ]);

            $chapter->update([
                'status' => ChapterStatus::Canonical,
                'canonical_artifact_id' => $artifact->getKey(),
                'word_count' => $this->lengthPolicy->count($artifact->content),
                'canonical_metadata' => [
                    'book_sources' => $task ? ['task_id' => $task->id, 'plan_id' => $chapter->current_plan_id, 'review_id' => $review->id, 'event_artifact_id' => $candidateArtifact->id, 'patch_artifact_id' => $patchArtifact->id, 'previous_state_id' => $currentState->id] : null,
                    'outline_completion' => $planning['outline_completion'],
                    'arc_beat_audits' => $planning['arc_beat_audits'],
                    'arc_completion_audits' => $planning['arc_completion_audits'],
                    'character_introductions' => collect($characters)->map(
                        fn ($character, string $candidateKey): array => [
                            'candidate_key' => $candidateKey,
                            'character_id' => $character->getKey(),
                        ],
                    )->values()->all(),
                    'world_entity_introductions' => collect($worldEntities)->map(
                        fn ($entity, string $candidateKey): array => [
                            'candidate_key' => $candidateKey,
                            'world_entity_id' => $entity->getKey(),
                        ],
                    )->values()->all(),
                ],
            ]);
            $novel->update([
                'canonical_state_version_id' => $stateVersion->getKey(),
                'current_chapter_sequence' => max($novel->current_chapter_sequence, $chapter->sequence),
            ]);
            if ($task) {
                // 正式效果、提交凭证和摘要待办同事务；进程退出后仍能从数据库补派。
                $receipt = GenerationRun::create(['novel_id' => $novel->id, 'chapter_id' => $chapter->id, 'generation_task_id' => $task->id, 'pipeline_version' => 'pipeline-v1', 'stage' => 'commit', 'scope_type' => 'chapter', 'scope_id' => $chapter->id, 'scope_key' => 'commit:'.$chapter->id, 'logical_step_key' => 'book-commit:'.$artifact->id, 'idempotency_key' => 'book-commit:'.$artifact->id, 'input_hash' => $artifact->checksum, 'status' => 'succeeded', 'attempt' => 1, 'execution_contract' => ['review_id' => $review->id, 'control_version' => $task->control_version], 'started_at' => now(), 'finished_at' => now()]);
                $proof = $receipt->artifacts()->create(['type' => ArtifactType::OperationRecord, 'data' => ['state_version_id' => $stateVersion->id, 'canonical_artifact_id' => $artifact->id], 'checksum' => app(GenerationInputIdentity::class)->hash([$stateVersion->id, $artifact->id]), 'validation_status' => 'validated']);
                $receipt->update(['result_artifact_id' => $proof->id]);
                $task->update(['current_phase' => 'memory_summary', 'pending_transition' => ['stage' => 'book_control', 'canonical_chapter_id' => $chapter->id], 'last_progress_at' => now()]);
            } else {
                $this->storyArcProgressProjector->refreshNovel($novel);
            }

            return [$stateVersion, true];
        }, 3);

        // Duplicate commits also repair a dispatch gap after a process crash. Every job in
        // this chain revalidates its canonical source and is independently idempotent.
        $this->postCommitDispatcher->dispatch(
            Chapter::query()->findOrFail($data->chapterId),
            $stateVersion,
        );

        return $stateVersion;
    }

    private function validateNovel(Novel $novel): void
    {
        // 任务暂停优先于旧 Novel 状态；在途返回不得绕过暂停正式提交。
        if ($novel->current_generation_task_id && NovelGenerationTask::findOrFail($novel->current_generation_task_id)->status !== 'running') {
            throw ValidationException::withMessages(['task' => '整书任务未运行，禁止正式提交。']);
        }
        if (! in_array($novel->status, $novel->current_generation_task_id ? [NovelStatus::Planning, NovelStatus::Generating, NovelStatus::Completing] : [NovelStatus::Generating, NovelStatus::Completing], true)) {
            throw ValidationException::withMessages(['novel' => '只有生成中或收束中的小说可以提交正式章节。']);
        }
    }

    /** @return array{GenerationArtifact, Review, GenerationArtifact, GenerationArtifact} */
    private function loadFrozenInputs(Chapter $chapter, CanonicalCommitData $data): array
    {
        if ($chapter->novel->current_generation_task_id) {
            return $this->loadBookInputs($chapter, $data);
        }
        $artifact = GenerationArtifact::query()->findOrFail($data->artifactId);
        $review = Review::query()->with(['artifact', 'generationRun'])->findOrFail($data->reviewId);
        $candidate = GenerationArtifact::query()->with('generationRun')->findOrFail($data->eventCandidateArtifactId);
        $patch = GenerationArtifact::query()->with('generationRun')->findOrFail($data->statePatchArtifactId);

        if (! in_array($artifact->type, [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft], true)
            || $artifact->generationRun()->where('chapter_id', $chapter->getKey())->doesntExist()) {
            throw ValidationException::withMessages(['artifact' => 'Canonical Artifact 不属于当前章节。']);
        }

        if ($artifact->checksum !== $data->artifactChecksum || hash('sha256', $artifact->content ?? '') !== $artifact->checksum) {
            throw ValidationException::withMessages(['artifact' => 'Canonical Artifact 校验和已变化。']);
        }

        if ($review->decision !== ReviewDecision::Pass
            || $review->generationRun->chapter_id !== $chapter->getKey()
            || $review->generationRun->state_version !== $data->expectedStateVersion
            || (int) data_get($review->artifact->data, 'source_artifact_id') !== $artifact->getKey()) {
            throw ValidationException::withMessages(['review' => '必须使用当前 Draft 对应的 PASS Review。']);
        }

        if ($candidate->type !== ArtifactType::EventCandidate
            || $candidate->generationRun->chapter_id !== $chapter->getKey()
            || $candidate->generationRun->state_version !== $data->expectedStateVersion
            || (int) data_get($candidate->data, 'source_artifact_id') !== $artifact->getKey()) {
            throw ValidationException::withMessages(['events' => 'Event Candidates 与当前 Draft 不一致。']);
        }

        if ($patch->type !== ArtifactType::StatePatch
            || $patch->generationRun->chapter_id !== $chapter->getKey()
            || (int) data_get($patch->data, 'source_artifact_id') !== $candidate->getKey()
            || (int) data_get($patch->data, 'expected_state_version', -1) !== $data->expectedStateVersion) {
            throw ValidationException::withMessages(['state_patch' => 'State Patch 与当前 Event Candidates 或 State Version 不一致。']);
        }

        $latestPatchId = GenerationArtifact::query()
            ->where('type', ArtifactType::StatePatch)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->latest('version')->latest('id')->value('id');

        if ($latestPatchId !== $patch->getKey()) {
            throw ValidationException::withMessages(['state_patch' => '必须提交当前章节最新的 State Patch。']);
        }

        return [$artifact, $review, $candidate, $patch];
    }

    /** 新版只认当前完整草稿及同源 PASS，不借用旧 Arc/Beat 或评分合同。 */
    private function loadBookInputs(Chapter $chapter, CanonicalCommitData $data): array
    {
        $task = NovelGenerationTask::findOrFail($data->taskId);
        $plan = ChapterPlan::where('chapter_id', $chapter->id)->findOrFail($chapter->current_plan_id);
        $draft = GenerationArtifact::findOrFail($data->artifactId);
        $review = Review::with(['artifact', 'generationRun'])->findOrFail($data->reviewId);
        $candidate = GenerationArtifact::findOrFail($data->eventCandidateArtifactId);
        $patch = GenerationArtifact::findOrFail($data->statePatchArtifactId);
        $r = $review->artifact->data;
        if ($plan->schema_version !== 'book-plan-v1' || $plan->source_state_version_id !== $task->novel->canonical_state_version_id
            || $chapter->current_draft_artifact_id !== $draft->id || $draft->generationRun->chapter_id !== $chapter->id
            || ! in_array($draft->type, [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft], true)
            || ! hash_equals($draft->checksum, $data->artifactChecksum)
            || ! hash_equals($draft->checksum, app(GenerationInputIdentity::class)->hash([$draft->content, $draft->data, $draft->source_manifest]))
            || $review->decision !== ReviewDecision::Pass || ($r['decision'] ?? null) !== 'PASS'
            || $review->generationRun->status !== RunStatus::Succeeded || $review->generationRun->generation_task_id !== $task->id
            || ($r['source_artifact_id'] ?? null) !== $draft->id || ($r['chapter_plan_id'] ?? null) !== $plan->id
            || ($r['event_artifact_id'] ?? null) !== $candidate->id || ($r['patch_artifact_id'] ?? null) !== $patch->id
            || ($r['source_state_version_id'] ?? null) !== $plan->source_state_version_id
            || $candidate->type !== ArtifactType::EventCandidate || $patch->type !== ArtifactType::StatePatch
            || ($candidate->data['source_artifact_id'] ?? null) !== $draft->id || ($patch->data['source_artifact_id'] ?? null) !== $candidate->id
            || ($patch->data['expected_state_version'] ?? null) !== $data->expectedStateVersion
            || $chapter->sequence !== ((int) $task->novel->current_chapter_sequence) + 1) {
            throw new \LogicException('正式提交来源、章节顺序或 PASS 已过期，请保留新稿重新复验。');
        }
        foreach ([$draft, $review->artifact, $candidate, $patch] as $artifact) {
            if ($artifact->novel_id !== $task->novel_id || $artifact->validation_status !== ArtifactValidationStatus::Validated || $artifact->generationRun->chapter_id !== $chapter->id) {
                throw new \LogicException('正式提交产物归属或验证状态不正确。');
            }
        }
        app(GenerationRunCoordinator::class)->assertSources($task, $review->generationRun->source_manifest, $review->generationRun->execution_contract);
        // 提交前再次执行证据及叙事结论归纳，防止仅更改 Review 行绕过真实报告。
        $verified = app(NovelChapterPipeline::class)->validateOutput($review->generationRun, $r);
        if ($verified['decision'] !== 'PASS') {
            throw new \LogicException('当前审校报告仍有阻塞项。');
        }

        return [$draft, $review, $candidate, $patch];
    }

    private function validateDraftLength(Chapter $chapter, GenerationArtifact $artifact, Review $review): void
    {
        $target = (int) $chapter->latestPlan?->target_words;
        if ($target < 1) {
            throw ValidationException::withMessages(['artifact' => 'Canonical Artifact 缺少有效的章节目标字数。']);
        }

        $actual = $this->lengthPolicy->count($artifact->content);
        $minimum = $this->lengthPolicy->chapterMinimum($target);
        $maximum = $this->lengthPolicy->chapterMaximum($target);

        if ($actual < $minimum) {
            throw ValidationException::withMessages(['artifact' => "Canonical Artifact 当前 {$actual} 字，少于严格下限 {$minimum} 字。"]);
        }

        if ($actual <= $maximum) {
            return;
        }

        $reviewData = $review->artifact->data;
        $hasRecordedException = data_get($reviewData, 'manual_length_exception') === true
            && $review->generationRun->prompt_version === AcceptOverlengthChapterAction::OPERATION_CONTRACT_VERSION
            && $review->generationRun->model_policy === 'manual'
            && data_get($review->generationRun->context_snapshot, 'manual_length_exception') === true
            && (int) data_get($reviewData, 'source_artifact_id') === $artifact->getKey()
            && (int) data_get($reviewData, 'actual_words') === $actual
            && (int) data_get($reviewData, 'maximum_words') === $maximum
            && filled(data_get($reviewData, 'manual_length_exception_reason'))
            && collect(data_get($reviewData, 'findings', []))->contains(
                fn (array $finding): bool => data_get($finding, 'code') === 'CHAPTER_LENGTH_TOO_LONG',
            );

        if (! $hasRecordedException) {
            throw ValidationException::withMessages(['artifact' => "Canonical Artifact 当前 {$actual} 字，超过严格上限 {$maximum} 字；必须先压缩正文或明确执行“接受超限版本”。"]);
        }
    }

    /** @return array<int, StoryEventCandidate> */
    private function eventCandidates(GenerationArtifact $artifact): array
    {
        $events = data_get($artifact->data, 'events');

        if (! is_array($events) || ! array_is_list($events)) {
            throw ValidationException::withMessages(['events' => 'Event Candidate Artifact 数据无效。']);
        }

        return array_map(fn (array $event): StoryEventCandidate => StoryEventCandidate::fromArray($event), $events);
    }

    /** @param array<int, StoryEventCandidate> $events @return array<int, StoryEvent> */
    private function persistEvents(Novel $novel, Chapter $chapter, array $events, int $stateVersion): array
    {
        return array_map(function (StoryEventCandidate $event) use ($novel, $chapter, $stateVersion): StoryEvent {
            // 进度事件的权威节点由冻结 Plan 覆盖，绝不信任模型返回数据库 ID。
            $outlineReferences = $this->outlineReferencesForEvent($chapter, $event);

            if (in_array($event->eventType, [EventType::StoryArcBeatCompleted, EventType::StoryArcBeatMilestoneCompleted], true)) {
                // Novel 行已在事务入口锁定；并发 Commit 会在这里串行化，并复用先到者
                // 写入的 Active Completion。数据库 Partial Unique Index 是最终防线。
                $existing = $novel->storyEvents()
                    ->active()
                    ->where('event_type', $event->eventType->value)
                    ->where('subject_type', 'story_arc')
                    ->where('subject_id', $event->subjectId)
                    ->where('novel_outline_beat_id', $outlineReferences['novel_outline_beat_id'])
                    ->when(
                        $event->eventType === EventType::StoryArcBeatMilestoneCompleted,
                        fn ($query) => $query->where('novel_outline_milestone_id', $outlineReferences['novel_outline_milestone_id']),
                        fn ($query) => $query->whereNull('novel_outline_milestone_id'),
                    )
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            return $novel->storyEvents()->create([
                'chapter_id' => $chapter->getKey(),
                'scene_id' => $event->evidence[0]['scene_id'] ?? null,
                'event_type' => $event->eventType,
                'subject_type' => $event->subjectType,
                'subject_id' => $event->subjectId,
                'payload' => $event->payload,
                'evidence' => $event->evidence,
                'story_time' => $event->storyTime,
                'state_version' => $stateVersion,
                ...$outlineReferences,
            ]);
        }, $events);
    }

    /** @return array<string, int|null> */
    private function outlineReferencesForEvent(Chapter $chapter, StoryEventCandidate $event): array
    {
        $empty = [
            'novel_outline_id' => null,
            'novel_outline_arc_id' => null,
            'novel_outline_beat_id' => null,
            'novel_outline_milestone_id' => null,
        ];
        if (! in_array($event->eventType, [EventType::StoryArcBeatCompleted, EventType::StoryArcBeatMilestoneCompleted], true)) {
            return $empty;
        }

        $plan = $chapter->latestPlan;
        if ($plan === null
            || $plan->novel_outline_id === null
            || $plan->primary_outline_arc_id === null
            || $plan->primary_outline_beat_id === null
            || $plan->primary_outline_milestone_id === null) {
            throw ValidationException::withMessages(['story_events' => 'Completion Event 缺少冻结的 Outline Version/Arc/Beat/Milestone 来源。']);
        }

        $arc = $chapter->novel->storyArcs()->find((int) $event->subjectId);
        if ($arc === null || $arc->source_outline_arc_id !== $plan->primary_outline_arc_id) {
            throw ValidationException::withMessages(['story_events' => 'Completion Event 的 Story Arc 与 Chapter Plan 冻结的 Outline Arc 不一致。']);
        }

        return [
            'novel_outline_id' => $plan->novel_outline_id,
            'novel_outline_arc_id' => $plan->primary_outline_arc_id,
            'novel_outline_beat_id' => $plan->primary_outline_beat_id,
            'novel_outline_milestone_id' => $event->eventType === EventType::StoryArcBeatMilestoneCompleted
                ? $plan->primary_outline_milestone_id
                : null,
        ];
    }

    /** @param array<int, StoryEvent> $events */
    private function applyFactChanges(Novel $novel, GenerationArtifact $patch, array $events): void
    {
        foreach (data_get($patch->data, 'fact_changes', []) as $change) {
            $validated = Validator::make($change, [
                'action' => ['required', Rule::in(['create', 'supersede'])],
                'fact_id' => ['required_if:action,supersede', 'integer', 'min:1'],
                'subject_type' => ['required_if:action,create', 'string'],
                'subject_id' => ['required_if:action,create', 'integer', 'min:1'],
                'predicate' => ['required_if:action,create', 'string'],
                'value' => ['required_if:action,create'],
                'hardness' => ['sometimes', Rule::enum(FactHardness::class)],
                'confidence' => ['sometimes', 'numeric', 'between:0,1'],
                'source_event_index' => ['required', 'integer', 'min:0'],
            ])->validate();
            $sourceEvent = $events[$validated['source_event_index']] ?? null;

            if ($sourceEvent === null) {
                throw ValidationException::withMessages(['fact_changes' => 'Fact Change 引用了不存在的 Story Event。']);
            }

            if ($validated['action'] === 'supersede') {
                $fact = $novel->facts()->whereKey($validated['fact_id'])->lockForUpdate()->firstOrFail();

                if ($fact->locked) {
                    throw ValidationException::withMessages(['fact_changes' => 'Canonical Commit 不能替代锁定事实。']);
                }

                $fact->update(['status' => FactStatus::Superseded]);

                continue;
            }

            $this->validateFactSubject($novel, $validated['subject_type'], $validated['subject_id']);

            Fact::query()->create([
                'novel_id' => $novel->getKey(),
                'subject_type' => $validated['subject_type'],
                'subject_id' => $validated['subject_id'],
                'predicate' => $validated['predicate'],
                'value' => $validated['value'],
                'hardness' => $validated['hardness'] ?? FactHardness::Hard,
                'confidence' => $validated['confidence'] ?? 1,
                'status' => FactStatus::Active,
                'locked' => false,
                'source_type' => FactSourceType::StoryEvent,
                'source_event_id' => $sourceEvent->getKey(),
            ]);
        }
    }

    private function validateFactSubject(Novel $novel, string $subjectType, int $subjectId): void
    {
        $valid = match ($subjectType) {
            'novel' => $subjectId === $novel->getKey(),
            'character' => $novel->characters()->whereKey($subjectId)->exists(),
            'world_entity' => $novel->worldEntities()->whereKey($subjectId)->exists(),
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['fact_changes' => 'Fact Change 引用了当前小说之外的主体。']);
        }
    }

    /**
     * @param  array<int, StoryEventCandidate>  $events
     * @return array{outline_completion: array<string, mixed>, arc_beat_audits: array<int, array<string, mixed>>, arc_completion_audits: array<int, array<string, mixed>>, character_candidates: array<string, array<string, mixed>>, world_entity_candidates: array<string, array<string, mixed>>}
     */
    private function validatePlanningAcceptance(Chapter $chapter, Review $review, GenerationArtifact $candidateArtifact, array $events): array
    {
        $plan = $chapter->latestPlan;
        $reviewData = $review->artifact->data;
        $allArcAudits = collect(data_get($reviewData, 'arc_beat_audits', []));
        $allCharacterAudits = collect(data_get($reviewData, 'character_candidate_audits', []));
        $allWorldAudits = collect(data_get($reviewData, 'world_entity_candidate_audits', []));
        $arcAudits = $allArcAudits
            ->where('status', 'fulfilled')->values();
        $worldAudits = $allWorldAudits
            ->where('status', 'introduced')->keyBy('candidate_key');
        $characterAudits = $allCharacterAudits
            ->where('status', 'introduced')->keyBy('candidate_key');
        $arcContracts = collect($plan?->arc_contributions ?? [])->keyBy(
            fn (array $item): string => ((int) ($item['arc_id'] ?? 0)).':'.($item['beat_key'] ?? ''),
        );
        $worldContracts = collect($plan?->world_entity_candidates ?? [])->keyBy('candidate_key');
        $characterContracts = collect($plan?->character_candidates ?? [])->keyBy('candidate_key');
        $outlineCompletion = $this->outlineCompletion->validateCommit(
            $chapter,
            $reviewData,
            $candidateArtifact->data ?? [],
            $events,
        );

        if ($allArcAudits->count() !== $arcContracts->count()
            || $allCharacterAudits->count() !== $characterContracts->count()
            || $allWorldAudits->count() !== $worldContracts->count()) {
            throw ValidationException::withMessages(['review' => 'PASS Review 缺少完整的 Arc Beat、Character Candidate 或 World Entity Candidate 验收记录。']);
        }

        if (data_get($reviewData, 'unapproved_characters', []) !== []) {
            throw ValidationException::withMessages(['review' => '正文仍包含未获 Plan 批准的持续性人物，不能 Canonical Commit。']);
        }
        if (data_get($reviewData, 'unapproved_world_entities', []) !== []) {
            throw ValidationException::withMessages(['review' => '正文仍包含未获 Plan 批准的重大世界实体，不能 Canonical Commit。']);
        }

        foreach ($arcAudits as $audit) {
            $key = ((int) ($audit['arc_id'] ?? 0)).':'.($audit['beat_key'] ?? '');
            if (! $arcContracts->has($key)) {
                throw ValidationException::withMessages(['arc_contributions' => '已验收的 Story Arc Contribution 缺少匹配的 Plan 契约。']);
            }
        }

        foreach ($worldAudits as $candidateKey => $audit) {
            $matchingEvent = collect($events)->first(fn (StoryEventCandidate $event): bool => $event->eventType === EventType::WorldEntityIntroduced
                && $event->subjectType === 'world_entity'
                && $event->subjectId === $candidateKey
                && ($event->payload['candidate_key'] ?? null) === $candidateKey
                && collect($event->evidence)->contains(fn (array $evidence): bool => ($evidence['quote'] ?? null) === ($audit['evidence'] ?? null))
            );
            if (! $worldContracts->has($candidateKey) || $matchingEvent === null) {
                throw ValidationException::withMessages(['world_entity_candidates' => '已验收的世界实体候选缺少匹配的 Plan 契约或 Event Candidate。']);
            }
        }

        foreach ($characterAudits as $candidateKey => $audit) {
            $matchingEvent = collect($events)->first(fn (StoryEventCandidate $event): bool => $event->eventType === EventType::CharacterIntroduced
                && $event->subjectType === 'character'
                && $event->subjectId === $candidateKey
                && ($event->payload['candidate_key'] ?? null) === $candidateKey
                && collect($event->evidence)->contains(fn (array $evidence): bool => ($evidence['quote'] ?? null) === ($audit['evidence'] ?? null))
            );
            if (! $characterContracts->has($candidateKey) || $matchingEvent === null) {
                throw ValidationException::withMessages(['character_candidates' => '已验收的人物候选缺少匹配的 Plan 契约或 Event Candidate。']);
            }
        }

        $unapprovedCharacterIntroduction = collect($events)->first(fn (StoryEventCandidate $event): bool => $event->eventType === EventType::CharacterIntroduced && ! $characterAudits->has((string) $event->subjectId));
        if ($unapprovedCharacterIntroduction !== null) {
            throw ValidationException::withMessages(['character_candidates' => '未通过 Review 的人物候选不能进入 Canonical Commit。']);
        }

        $unapprovedIntroduction = collect($events)->first(fn (StoryEventCandidate $event): bool => $event->eventType === EventType::WorldEntityIntroduced && ! $worldAudits->has((string) $event->subjectId));
        if ($unapprovedIntroduction !== null) {
            throw ValidationException::withMessages(['world_entity_candidates' => '未通过 Review 的世界实体候选不能进入 Canonical Commit。']);
        }

        return [
            'outline_completion' => $outlineCompletion,
            'arc_beat_audits' => $arcAudits->all(),
            'arc_completion_audits' => collect(data_get($reviewData, 'arc_completion_audits', []))->where('status', 'fulfilled')->values()->all(),
            'character_candidates' => $characterAudits->map(fn (array $audit, string $key): array => [
                ...$characterContracts->get($key),
                'evidence' => $audit['evidence'],
                'scene_id' => $audit['scene_id'],
            ])->all(),
            'world_entity_candidates' => $worldAudits->map(fn (array $audit, string $key): array => [
                ...$worldContracts->get($key),
                'evidence' => $audit['evidence'],
                'scene_id' => $audit['scene_id'],
            ])->all(),
        ];
    }

    /** @param array<string, array<string, mixed>> $candidates @return array<string, Character> */
    private function persistCharacterCandidates(Novel $novel, Chapter $chapter, array $candidates): array
    {
        $characters = [];
        foreach ($candidates as $candidateKey => $candidate) {
            $duplicate = $novel->characters()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($candidate['name']))])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['character_candidates' => "人物候选「{$candidate['name']}」与现有正式人物重名，不能自动提交。"]);
            }

            $characters[$candidateKey] = $novel->characters()->firstOrCreate(
                ['source_chapter_id' => $chapter->getKey(), 'source_candidate_key' => $candidateKey],
                [
                    'name' => $candidate['name'],
                    'aliases' => [],
                    'role' => $candidate['role'],
                    'profile' => $candidate['profile'],
                    'motivation' => $candidate['motivation'],
                    'personality' => $candidate['personality'],
                    'abilities' => $candidate['abilities'],
                    'knowledge' => $candidate['knowledge'],
                    'current_state' => [],
                    'locked_fields' => [],
                    'status' => CharacterStatus::Active,
                ],
            );
        }

        return $characters;
    }

    /** @param array<int, StoryEventCandidate> $events @param array<string, array<string, mixed>> $candidates @param array<string, Character> $characters @return array<int, StoryEventCandidate> */
    private function resolveCharacterEvents(array $events, array $candidates, array $characters): array
    {
        return array_map(function (StoryEventCandidate $event) use ($candidates, $characters): StoryEventCandidate {
            if ($event->eventType !== EventType::CharacterIntroduced) {
                return $event;
            }

            $candidateKey = (string) $event->subjectId;
            $candidate = $candidates[$candidateKey] ?? null;
            $character = $characters[$candidateKey] ?? null;
            if ($candidate === null || $character === null) {
                throw ValidationException::withMessages(['character_candidates' => '人物候选解析失败。']);
            }

            return new StoryEventCandidate(
                eventType: $event->eventType,
                subjectType: 'character',
                subjectId: (string) $character->getKey(),
                payload: [
                    ...$event->payload,
                    'candidate_key' => $candidateKey,
                    'state' => [
                        'character_id' => $character->getKey(),
                        'name' => $candidate['name'],
                        'role' => $candidate['role'],
                        'profile' => $candidate['profile'],
                        'motivation' => $candidate['motivation'],
                        'personality' => $candidate['personality'],
                        'abilities' => $candidate['abilities'],
                        'knowledge' => $candidate['knowledge'],
                        'status' => CharacterStatus::Active->value,
                    ],
                ],
                evidence: $event->evidence,
                storyTime: $event->storyTime,
                confidence: $event->confidence,
            );
        }, $events);
    }

    /** @param array<string, array<string, mixed>> $candidates @return array<string, \App\Models\WorldEntity> */
    private function persistWorldEntityCandidates(Novel $novel, Chapter $chapter, array $candidates): array
    {
        $entities = [];
        foreach ($candidates as $candidateKey => $candidate) {
            $duplicate = $novel->worldEntities()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($candidate['name']))])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['world_entity_candidates' => "世界实体候选「{$candidate['name']}」与现有实体重名，不能自动提交。"]);
            }

            $entities[$candidateKey] = $novel->worldEntities()->firstOrCreate(
                ['source_chapter_id' => $chapter->getKey(), 'source_candidate_key' => $candidateKey],
                [
                    'type' => $candidate['type'],
                    'name' => $candidate['name'],
                    'description' => $candidate['description'],
                    'attributes' => [],
                    'rules' => [],
                    'current_state' => [],
                    'locked_fields' => [],
                    'status' => WorldEntityStatus::Active,
                ],
            );
        }

        return $entities;
    }

    /** @param array<int, StoryEventCandidate> $events @param array<string, array<string, mixed>> $candidates @param array<string, \App\Models\WorldEntity> $entities @return array<int, StoryEventCandidate> */
    private function resolveWorldEntityEvents(array $events, array $candidates, array $entities): array
    {
        return array_map(function (StoryEventCandidate $event) use ($candidates, $entities): StoryEventCandidate {
            if ($event->eventType !== EventType::WorldEntityIntroduced) {
                return $event;
            }

            $candidateKey = (string) $event->subjectId;
            $candidate = $candidates[$candidateKey] ?? null;
            $entity = $entities[$candidateKey] ?? null;
            if ($candidate === null || $entity === null) {
                throw ValidationException::withMessages(['world_entity_candidates' => '世界实体候选解析失败。']);
            }

            return new StoryEventCandidate(
                eventType: $event->eventType,
                subjectType: 'world_entity',
                subjectId: (string) $entity->getKey(),
                payload: [
                    ...$event->payload,
                    'candidate_key' => $candidateKey,
                    'state' => [
                        'entity_id' => $entity->getKey(),
                        'type' => $candidate['type'],
                        'name' => $candidate['name'],
                        'description' => $candidate['description'],
                        'status' => WorldEntityStatus::Active->value,
                    ],
                ],
                evidence: $event->evidence,
                storyTime: $event->storyTime,
                confidence: $event->confidence,
            );
        }, $events);
    }

    private function resolveDuplicate(Chapter $chapter, CanonicalCommitData $data): StoryStateVersion
    {
        if ($chapter->canonical_artifact_id !== $data->artifactId) {
            throw ValidationException::withMessages(['chapter' => '章节已经使用另一个 Artifact 完成 Canonical Commit。']);
        }

        $stateVersion = $chapter->stateVersions()->latest('version')->first();

        if ($stateVersion === null) {
            throw ValidationException::withMessages(['chapter' => '章节已标记正式，但缺少对应 Story State Version。']);
        }

        return $stateVersion;
    }
}
