<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\FactStatus;
use App\Enums\NovelStatus;
use App\Enums\StoryEventStatus;
use App\Jobs\RefreshNovelProjectionJob;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Models\WorldEntity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LatestCanonicalChapterRollback
{
    public function __construct(
        private readonly MemoryInvalidator $memoryInvalidator,
        private readonly StoryArcProgressProjector $storyArcProgressProjector,
    ) {}

    /** @return array{chapter_id: int, from_state_version: int, to_state_version: int, events: int, memories: int, facts_invalidated: int, facts_restored: int, characters_removed: int, world_entities_removed: int} */
    public function rollback(Chapter $chapter, string $reason): array
    {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => '必须填写回滚原因。']);
        }

        $result = DB::transaction(function () use ($chapter): array {
            $novel = Novel::query()->lockForUpdate()->findOrFail($chapter->novel_id);
            $target = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());

            if ($target->status !== ChapterStatus::Canonical
                || $target->sequence !== $novel->current_chapter_sequence) {
                throw ValidationException::withMessages(['chapter' => '只能回滚当前最新的正式章节。']);
            }

            $currentState = StoryStateVersion::query()->lockForUpdate()->find($novel->canonical_state_version_id);

            if ($currentState === null || $currentState->chapter_id !== $target->getKey()) {
                throw ValidationException::withMessages(['state' => '当前故事状态与最新正式章节不一致。']);
            }

            $previousState = StoryStateVersion::query()
                ->where('novel_id', $novel->getKey())
                ->where('version', '<', $currentState->version)
                ->where(fn ($query) => $query->whereNull('chapter_id')->orWhere('chapter_id', '!=', $target->getKey()))
                ->orderByDesc('version')
                ->lockForUpdate()
                ->firstOrFail();
            $events = $target->storyEvents()->where('status', StoryEventStatus::Active)->lockForUpdate()->get();
            $worldEntityIds = collect(data_get($target->canonical_metadata, 'world_entity_introductions', []))
                ->pluck('world_entity_id')->map(fn ($id): int => (int) $id)->filter()->values();
            $characterIds = collect(data_get($target->canonical_metadata, 'character_introductions', []))
                ->pluck('character_id')->map(fn ($id): int => (int) $id)->filter()->values();
            $laterCharacterReference = $novel->storyEvents()
                ->where('status', StoryEventStatus::Active)
                ->where('chapter_id', '!=', $target->getKey())
                ->where('subject_type', 'character')
                ->whereIn('subject_id', $characterIds->map(fn (int $id): string => (string) $id))
                ->exists();
            $laterWorldReference = $novel->storyEvents()
                ->where('status', StoryEventStatus::Active)
                ->where('chapter_id', '!=', $target->getKey())
                ->where('subject_type', 'world_entity')
                ->whereIn('subject_id', $worldEntityIds->map(fn (int $id): string => (string) $id))
                ->exists();
            if ($laterCharacterReference || $laterWorldReference) {
                throw ValidationException::withMessages(['canonical_references' => '本章首次引入的人物或世界实体已被其他正式章节引用，不能执行简单回滚；请先重建后续 Canonical 链。']);
            }
            $eventIds = $events->pluck('id');
            $factsInvalidated = Fact::query()
                ->where('novel_id', $novel->getKey())
                ->whereIn('source_event_id', $eventIds)
                ->where('status', FactStatus::Active)
                ->update(['status' => FactStatus::Invalidated]);
            $factsRestored = $this->restoreSupersededFacts($target, $novel);

            $events->each->update(['status' => StoryEventStatus::Invalidated, 'invalidated_at' => now()]);
            $memories = $this->memoryInvalidator->invalidateForChapter($target->getKey());
            $charactersRemoved = Character::query()
                ->where('novel_id', $novel->getKey())
                ->where('source_chapter_id', $target->getKey())
                ->whereIn('id', $characterIds)
                ->delete();
            $worldEntitiesRemoved = WorldEntity::query()
                ->where('novel_id', $novel->getKey())
                ->where('source_chapter_id', $target->getKey())
                ->whereIn('id', $worldEntityIds)
                ->delete();
            $previousSequence = $novel->chapters()
                ->where('status', ChapterStatus::Canonical)
                ->where('sequence', '<', $target->sequence)
                ->max('sequence');

            $target->update(['status' => ChapterStatus::Void, 'canonical_artifact_id' => null, 'canonical_metadata' => null]);
            $novelData = [
                'canonical_state_version_id' => $previousState->getKey(),
                'current_chapter_sequence' => $previousSequence,
            ];

            if ($novel->status === NovelStatus::Completed) {
                $novelData['status'] = NovelStatus::Completing;
            }

            $novel->update($novelData);
            $this->storyArcProgressProjector->refreshNovel($novel);

            return [
                'chapter_id' => $target->getKey(),
                'from_state_version' => $currentState->version,
                'to_state_version' => $previousState->version,
                'events' => $events->count(),
                'memories' => $memories,
                'facts_invalidated' => $factsInvalidated,
                'facts_restored' => $factsRestored,
                'characters_removed' => $charactersRemoved,
                'world_entities_removed' => $worldEntitiesRemoved,
            ];
        }, 3);

        $novel = Novel::query()->findOrFail($chapter->novel_id);
        RefreshNovelProjectionJob::dispatch($novel->getKey(), (int) $novel->canonical_state_version_id)->afterCommit();

        Log::warning('Latest canonical chapter rolled back.', [...$result, 'reason' => trim($reason)]);

        return $result;
    }

    /** @return array{state: string, events: int, memories: int} */
    public function impact(Chapter $chapter): array
    {
        $current = $chapter->latestStateVersion;
        $previous = $current === null ? null : StoryStateVersion::query()
            ->where('novel_id', $chapter->novel_id)
            ->where('version', '<', $current->version)
            ->where(fn ($query) => $query->whereNull('chapter_id')->orWhere('chapter_id', '!=', $chapter->getKey()))
            ->latest('version')
            ->first();

        return [
            'state' => $current === null || $previous === null ? '无法确定' : "v{$current->version} → v{$previous->version}",
            'events' => $chapter->storyEvents()->active()->count(),
            'memories' => $this->memoryInvalidator->countForChapter($chapter->getKey()),
        ];
    }

    private function restoreSupersededFacts(Chapter $chapter, Novel $novel): int
    {
        $patch = GenerationArtifact::query()
            ->where('type', ArtifactType::StatePatch)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->latest('version')->latest('id')->first();
        $ids = collect(data_get($patch?->data, 'fact_changes', []))
            ->where('action', 'supersede')->pluck('fact_id')->filter()->all();

        return Fact::query()->where('novel_id', $novel->getKey())->whereIn('id', $ids)
            ->where('status', FactStatus::Superseded)->update(['status' => FactStatus::Active]);
    }
}
