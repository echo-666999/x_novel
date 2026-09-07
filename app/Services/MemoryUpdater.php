<?php

namespace App\Services;

use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Enums\RunStatus;
use App\Enums\StoryEventStatus;
use App\Jobs\GenerateEmbeddingJob;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class MemoryUpdater
{
    public const POLICY_VERSION = 'memory-policy-v1';

    /** @return Collection<int, Memory> */
    public function update(int $chapterId): Collection
    {
        [$chapter, $stateVersion, $events] = $this->canonicalSources($chapterId);
        $artifact = $chapter->canonicalArtifact;
        $key = "memory:{$artifact->checksum}:".self::POLICY_VERSION;
        $inputHash = hash('sha256', json_encode([
            'artifact_checksum' => $artifact->checksum,
            'state_checksum' => $stateVersion->checksum,
            'event_ids' => $events->modelKeys(),
            'policy_version' => self::POLICY_VERSION,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $run = $this->startRun($chapter, $stateVersion, $events, $key, $inputHash);

        if ($run->status === RunStatus::Succeeded) {
            $memories = $this->memoriesFor($chapter, $events);
            $this->dispatchPendingEmbeddings($memories);

            return $memories;
        }

        try {
            $memories = DB::transaction(function () use ($chapter, $stateVersion, $events, $run): Collection {
                $lockedChapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
                $this->assertCanonicalSources($lockedChapter, $stateVersion, $events);

                $memories = $events->map(fn (StoryEvent $event): Memory => Memory::query()->firstOrCreate(
                    [
                        'novel_id' => $chapter->novel_id,
                        'type' => $this->memoryType($event),
                        'source_type' => 'story_event',
                        'source_id' => $event->getKey(),
                    ],
                    [
                        'summary' => $this->summary($event),
                        'entities' => $this->entities($event),
                        'salience' => $this->salience($event),
                        'status' => MemoryStatus::Active,
                        'embedding' => null,
                        'embedding_model' => null,
                        'valid_from_chapter' => $chapter->sequence,
                        'valid_to_chapter' => null,
                    ],
                ));

                $run->update([
                    'status' => RunStatus::Succeeded,
                    'finished_at' => now(),
                    'error_code' => null,
                    'error_message' => null,
                ]);

                return $memories;
            });
            $this->dispatchPendingEmbeddings($memories);

            return $memories;
        } catch (Throwable $exception) {
            $run->update([
                'status' => RunStatus::Failed,
                'finished_at' => now(),
                'error_code' => 'memory_update_failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /** @param Collection<int, Memory> $memories */
    private function dispatchPendingEmbeddings(Collection $memories): void
    {
        $memories
            ->reject(fn (Memory $memory): bool => $memory->hasEmbedding()
                && $memory->embedding_model === (string) config('ai.embedding.model'))
            ->each(fn (Memory $memory) => GenerateEmbeddingJob::dispatch($memory->getKey())->afterCommit());
    }

    /** @return array{Chapter, StoryStateVersion, Collection<int, StoryEvent>} */
    private function canonicalSources(int $chapterId): array
    {
        $chapter = Chapter::query()
            ->with(['canonicalArtifact.generationRun', 'latestStateVersion'])
            ->findOrFail($chapterId);
        $stateVersion = $chapter->latestStateVersion;
        $events = $chapter->storyEvents()
            ->active()
            ->where('state_version', $stateVersion?->version)
            ->orderBy('id')
            ->get();

        $this->assertCanonicalSources($chapter, $stateVersion, $events);

        return [$chapter, $stateVersion, $events];
    }

    /** @param Collection<int, StoryEvent> $events */
    private function assertCanonicalSources(Chapter $chapter, ?StoryStateVersion $stateVersion, Collection $events): void
    {
        if ($chapter->status !== ChapterStatus::Canonical
            || $chapter->canonicalArtifact === null
            || $chapter->canonicalArtifact->generationRun?->chapter_id !== $chapter->getKey()
            || $stateVersion === null
            || $stateVersion->chapter_id !== $chapter->getKey()) {
            throw ValidationException::withMessages([
                'memory' => 'Memory 只能从当前章节的 Canonical Artifact、Story Events 和 State Version 创建。',
            ]);
        }

        if ($events->contains(fn (StoryEvent $event): bool => $event->novel_id !== $chapter->novel_id
            || $event->chapter_id !== $chapter->getKey()
            || $event->state_version !== $stateVersion->version
            || $event->status !== StoryEventStatus::Active)) {
            throw ValidationException::withMessages(['memory' => 'Memory Source Validation 失败。']);
        }
    }

    /** @param Collection<int, StoryEvent> $events */
    private function startRun(Chapter $chapter, StoryStateVersion $stateVersion, Collection $events, string $key, string $inputHash): GenerationRun
    {
        return DB::transaction(function () use ($chapter, $stateVersion, $events, $key, $inputHash): GenerationRun {
            Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $run = GenerationRun::query()->where('idempotency_key', $key)->first();

            if ($run !== null) {
                if ($run->input_hash !== $inputHash) {
                    throw ValidationException::withMessages(['memory' => '相同 Memory 幂等键对应的输入已经变化。']);
                }

                if ($run->status !== RunStatus::Succeeded) {
                    $run->update([
                        'status' => RunStatus::Running,
                        'started_at' => now(),
                        'finished_at' => null,
                        'error_code' => null,
                        'error_message' => null,
                    ]);
                }

                return $run;
            }

            return GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scene_id' => null,
                'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::MemorySummary,
                'status' => RunStatus::Running,
                'attempt' => 1,
                'idempotency_key' => $key,
                'input_hash' => $inputHash,
                'state_version' => $stateVersion->version,
                'prompt_version' => self::POLICY_VERSION,
                'model_policy' => null,
                'context_snapshot' => [
                    'canonical_artifact_id' => $chapter->canonical_artifact_id,
                    'state_version' => $stateVersion->version,
                    'story_event_ids' => $events->modelKeys(),
                    'memory_policy_version' => self::POLICY_VERSION,
                ],
                'started_at' => now(),
            ]);
        });
    }

    /** @param Collection<int, StoryEvent> $events @return Collection<int, Memory> */
    private function memoriesFor(Chapter $chapter, Collection $events): Collection
    {
        return Memory::query()
            ->where('novel_id', $chapter->novel_id)
            ->where('source_type', 'story_event')
            ->whereIn('source_id', $events->modelKeys())
            ->get();
    }

    private function memoryType(StoryEvent $event): MemoryType
    {
        return match ($event->event_type) {
            EventType::CharacterStatusChanged, EventType::CharacterInjured, EventType::CharacterRecovered,
            EventType::CharacterGoalChanged, EventType::CharacterAbilityAcquired, EventType::CharacterAbilityChanged,
            EventType::CharacterLearned, EventType::CharacterForgot => MemoryType::CharacterMilestone,
            EventType::RelationshipChanged, EventType::PromiseMade, EventType::PromiseBroken,
            EventType::DebtCreated, EventType::DebtResolved => MemoryType::Relationship,
            EventType::ItemAcquired, EventType::ItemTransferred, EventType::ItemLost,
            EventType::ItemDestroyed, EventType::ItemStateChanged => MemoryType::Item,
            EventType::ForeshadowingPlanted, EventType::ForeshadowingReinforced, EventType::ForeshadowingDue,
            EventType::ForeshadowingPaidOff, EventType::ForeshadowingAbandoned => MemoryType::Foreshadowing,
            EventType::ReaderPromiseCreated, EventType::ReaderPromiseResolved => MemoryType::ReaderPromise,
            EventType::WorldRuleRevealed, EventType::WorldRuleChanged, EventType::WorldStateChanged,
            EventType::FactionStateChanged => MemoryType::World,
            EventType::LocationStateChanged, EventType::CharacterMoved => MemoryType::Location,
            EventType::ConflictStarted, EventType::ConflictEscalated, EventType::ConflictResolved,
            EventType::ThreadOpened, EventType::ThreadProgressed, EventType::ThreadClosed => MemoryType::Arc,
            default => MemoryType::Event,
        };
    }

    private function salience(StoryEvent $event): float
    {
        return match ($event->event_type) {
            EventType::ForeshadowingPaidOff, EventType::ForeshadowingDue,
            EventType::WorldRuleRevealed, EventType::WorldRuleChanged => 0.900,
            EventType::CharacterStatusChanged, EventType::CharacterAbilityAcquired,
            EventType::CharacterLearned, EventType::RelationshipChanged,
            EventType::ConflictStarted, EventType::ConflictResolved => 0.800,
            EventType::EventCorrected, EventType::EventInvalidated, EventType::ManualCorrection => 0.850,
            default => 0.650,
        };
    }

    private function summary(StoryEvent $event): string
    {
        $quote = collect($event->evidence)
            ->pluck('quote')
            ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
            ->first();

        return $quote ?? $event->event_type->getLabel().'：'.json_encode(
            $event->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array<string, array<int, string>> */
    private function entities(StoryEvent $event): array
    {
        if (blank($event->subject_type) || blank($event->subject_id)) {
            return [];
        }

        $key = match ($event->subject_type) {
            'character' => 'characters',
            'world_entity' => 'world_entities',
            'foreshadowing' => 'foreshadowings',
            'story_arc' => 'arcs',
            default => $event->subject_type,
        };

        return [$key => [(string) $event->subject_id]];
    }
}
