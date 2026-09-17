<?php

namespace App\Services;

use App\Actions\Generation\CheckNextAction;
use App\Contracts\StoryEventApplier;
use App\Data\CanonicalCommitData;
use App\Data\StatePatch;
use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\WorldEntityStatus;
use App\Jobs\RefreshNovelProjectionJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\Novel;
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
        private readonly CheckNextAction $checkNextAction,
        private readonly EmergencyStopService $emergencyStop,
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly StoryEventApplier $storyEventApplier,
        private readonly StoryArcProgressProjector $storyArcProgressProjector,
    ) {}

    public function commit(CanonicalCommitData $data): StoryStateVersion
    {
        [$stateVersion, $committed] = DB::transaction(function () use ($data): array {
            $chapter = Chapter::query()->findOrFail($data->chapterId);
            $novel = Novel::query()->lockForUpdate()->findOrFail($chapter->novel_id);
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($data->chapterId);

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
            $this->validateDraftLength($chapter, $artifact, $review);
            $this->stateValidator->validate($chapter->getKey())->assertCanCommit();
            $events = $this->eventCandidates($candidateArtifact);
            $nextVersion = ((int) $novel->storyStateVersions()->max('version')) + 1;
            $nextState = $this->statePatchBuilder->applyArtifact($currentState->state, $patchArtifact);

            if (data_get($patchArtifact->data, 'after_checksum') !== $this->storyState->checksum($nextState)) {
                throw ValidationException::withMessages(['state_patch' => 'State Patch 校验和与应用结果不一致。']);
            }

            $planning = $this->validatePlanningAcceptance($chapter, $review, $events);
            $worldEntities = $this->persistWorldEntityCandidates($novel, $chapter, $planning['world_entity_candidates']);
            $events = $this->resolveWorldEntityEvents($events, $planning['world_entity_candidates'], $worldEntities);
            $worldOperations = collect($events)
                ->flatMap(fn (StoryEventCandidate $event, int $index): array => $event->eventType === EventType::WorldEntityIntroduced
                    ? array_map(
                        fn (array $operation): array => [...$operation, 'source_event_index' => $index],
                        $this->storyEventApplier->operations($event),
                    )
                    : [])
                ->values()->all();
            $nextState = $this->statePatchBuilder->applyPatch(
                $nextState,
                new StatePatch($currentState->version, $worldOperations),
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
                    'arc_beat_audits' => $planning['arc_beat_audits'],
                    'arc_completion_audits' => $planning['arc_completion_audits'],
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
            $this->storyArcProgressProjector->refreshNovel($novel);

            return [$stateVersion, true];
        }, 3);

        UpdateMemoryJob::dispatch($data->chapterId)->afterCommit();
        RefreshNovelProjectionJob::dispatch($stateVersion->novel_id, $stateVersion->getKey())->afterCommit();

        if ($committed) {
            $this->checkNextAction->handle($stateVersion->novel, $data->chapterId);
        }

        return $stateVersion;
    }

    private function validateNovel(Novel $novel): void
    {
        if (! in_array($novel->status, [NovelStatus::Generating, NovelStatus::Completing], true)) {
            throw ValidationException::withMessages(['novel' => '只有生成中或收束中的小说可以提交正式章节。']);
        }
    }

    /** @return array{GenerationArtifact, Review, GenerationArtifact, GenerationArtifact} */
    private function loadFrozenInputs(Chapter $chapter, CanonicalCommitData $data): array
    {
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
            && $review->generationRun->prompt_version === 'manual-length-exception-v1'
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
        return array_map(fn (StoryEventCandidate $event): StoryEvent => $novel->storyEvents()->create([
            'chapter_id' => $chapter->getKey(),
            'scene_id' => $event->evidence[0]['scene_id'],
            'event_type' => $event->eventType,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'payload' => $event->payload,
            'evidence' => $event->evidence,
            'story_time' => $event->storyTime,
            'state_version' => $stateVersion,
        ]), $events);
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
     * @return array{arc_beat_audits: array<int, array<string, mixed>>, arc_completion_audits: array<int, array<string, mixed>>, world_entity_candidates: array<string, array<string, mixed>>}
     */
    private function validatePlanningAcceptance(Chapter $chapter, Review $review, array $events): array
    {
        $plan = $chapter->latestPlan;
        $reviewData = $review->artifact->data;
        $allArcAudits = collect(data_get($reviewData, 'arc_beat_audits', []));
        $allWorldAudits = collect(data_get($reviewData, 'world_entity_candidate_audits', []));
        $arcAudits = $allArcAudits
            ->where('status', 'fulfilled')->values();
        $worldAudits = $allWorldAudits
            ->where('status', 'introduced')->keyBy('candidate_key');
        $arcContracts = collect($plan?->arc_contributions ?? [])->keyBy(
            fn (array $item): string => ((int) ($item['arc_id'] ?? 0)).':'.($item['beat_key'] ?? ''),
        );
        $worldContracts = collect($plan?->world_entity_candidates ?? [])->keyBy('candidate_key');

        if ($allArcAudits->count() !== $arcContracts->count()
            || $allWorldAudits->count() !== $worldContracts->count()) {
            throw ValidationException::withMessages(['review' => 'PASS Review 缺少完整的 Arc Beat 或 World Entity Candidate 验收记录。']);
        }

        if (data_get($reviewData, 'unapproved_world_entities', []) !== []) {
            throw ValidationException::withMessages(['review' => '正文仍包含未获 Plan 批准的重大世界实体，不能 Canonical Commit。']);
        }

        foreach ($arcAudits as $audit) {
            $key = ((int) ($audit['arc_id'] ?? 0)).':'.($audit['beat_key'] ?? '');
            $matchingEvent = collect($events)->first(fn (StoryEventCandidate $event): bool => $event->eventType === EventType::StoryArcBeatCompleted
                && $event->subjectType === 'story_arc'
                && $event->subjectId === (string) ($audit['arc_id'] ?? '')
                && ($event->payload['beat_key'] ?? null) === ($audit['beat_key'] ?? null)
                && collect($event->evidence)->contains(fn (array $evidence): bool => ($evidence['quote'] ?? null) === ($audit['evidence'] ?? null))
            );
            if (! $arcContracts->has($key) || $matchingEvent === null) {
                throw ValidationException::withMessages(['arc_contributions' => '已验收的 Story Arc Beat 缺少匹配的 Plan 契约或 Event Candidate。']);
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

        $unapprovedIntroduction = collect($events)->first(fn (StoryEventCandidate $event): bool => $event->eventType === EventType::WorldEntityIntroduced && ! $worldAudits->has((string) $event->subjectId));
        if ($unapprovedIntroduction !== null) {
            throw ValidationException::withMessages(['world_entity_candidates' => '未通过 Review 的世界实体候选不能进入 Canonical Commit。']);
        }
        $acceptedArcKeys = $arcAudits->map(fn (array $audit): string => ((int) $audit['arc_id']).':'.$audit['beat_key']);
        $unapprovedArcEvent = collect($events)->first(fn (StoryEventCandidate $event): bool => $event->eventType === EventType::StoryArcBeatCompleted
            && ! $acceptedArcKeys->contains(((int) $event->subjectId).':'.($event->payload['beat_key'] ?? '')));
        if ($unapprovedArcEvent !== null) {
            throw ValidationException::withMessages(['arc_contributions' => '未通过 Review 的 Story Arc Beat 不能计入 Canonical Progress。']);
        }

        return [
            'arc_beat_audits' => $arcAudits->all(),
            'arc_completion_audits' => collect(data_get($reviewData, 'arc_completion_audits', []))->where('status', 'fulfilled')->values()->all(),
            'world_entity_candidates' => $worldAudits->map(fn (array $audit, string $key): array => [
                ...$worldContracts->get($key),
                'evidence' => $audit['evidence'],
                'scene_id' => $audit['scene_id'],
            ])->all(),
        ];
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
