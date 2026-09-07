<?php

namespace App\Services;

use App\Data\CanonicalCommitData;
use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
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
    ) {}

    public function commit(CanonicalCommitData $data): StoryStateVersion
    {
        $stateVersion = DB::transaction(function () use ($data): StoryStateVersion {
            $chapter = Chapter::query()->findOrFail($data->chapterId);
            $novel = Novel::query()->lockForUpdate()->findOrFail($chapter->novel_id);
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($data->chapterId);

            if ($chapter->canonical_artifact_id !== null) {
                return $this->resolveDuplicate($chapter, $data);
            }

            $this->validateNovel($novel);
            $currentState = $novel->canonicalStateVersion()->first();

            if ($currentState === null || $currentState->version !== $data->expectedStateVersion) {
                throw ValidationException::withMessages(['state' => 'Expected State Version 与当前 Canonical Story State 不一致。']);
            }

            [$artifact, $review, $candidateArtifact, $patchArtifact] = $this->loadFrozenInputs($chapter, $data);
            $this->stateValidator->validate($chapter->getKey())->assertCanCommit();
            $events = $this->eventCandidates($candidateArtifact);
            $nextVersion = $currentState->version + 1;
            $nextState = $this->statePatchBuilder->applyArtifact($currentState->state, $patchArtifact);

            if (data_get($patchArtifact->data, 'after_checksum') !== $this->storyState->checksum($nextState)) {
                throw ValidationException::withMessages(['state_patch' => 'State Patch 校验和与应用结果不一致。']);
            }

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
                'word_count' => mb_strlen($artifact->content ?? ''),
            ]);
            $novel->update([
                'canonical_state_version_id' => $stateVersion->getKey(),
                'current_chapter_sequence' => max($novel->current_chapter_sequence, $chapter->sequence),
            ]);

            return $stateVersion;
        }, 3);

        UpdateMemoryJob::dispatch($data->chapterId)->afterCommit();

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

    private function resolveDuplicate(Chapter $chapter, CanonicalCommitData $data): StoryStateVersion
    {
        if ($chapter->canonical_artifact_id !== $data->artifactId) {
            throw ValidationException::withMessages(['chapter' => '章节已经使用另一个 Artifact 完成 Canonical Commit。']);
        }

        $stateVersion = $chapter->stateVersions()->where('version', $data->expectedStateVersion + 1)->first();

        if ($stateVersion === null) {
            throw ValidationException::withMessages(['chapter' => '章节已标记正式，但缺少对应 Story State Version。']);
        }

        return $stateVersion;
    }
}
