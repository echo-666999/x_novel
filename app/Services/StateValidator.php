<?php

namespace App\Services;

use App\Data\StateFinding;
use App\Data\StatePatch;
use App\Data\StateValidationResult;
use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
use App\Enums\EventType;
use App\Enums\FactStatus;
use App\Enums\StateFindingSeverity;
use App\Models\Chapter;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class StateValidator
{
    private const INTENTIONAL_OVERRIDES = [
        'resurrection', 'false_death_revealed', 'illusion', 'flashback', 'dream', 'time_shift',
    ];

    public function validate(int $chapterId): StateValidationResult
    {
        $chapter = Chapter::query()->with(['novel.canonicalStateVersion', 'latestPlan'])->findOrFail($chapterId);
        $patchArtifact = $this->latestArtifact($chapter, ArtifactType::StatePatch);

        if ($patchArtifact === null) {
            return new StateValidationResult([$this->hard('INVALID_STATE_PATCH', '本章尚未生成 State Patch。')]);
        }

        $candidateArtifact = GenerationArtifact::query()
            ->whereKey(data_get($patchArtifact->data, 'source_artifact_id'))
            ->where('type', ArtifactType::EventCandidate)
            ->first();

        if ($candidateArtifact === null) {
            return new StateValidationResult([$this->hard('INVALID_EVENT_REFERENCE', 'State Patch 引用的 Event Candidate Artifact 不存在。')]);
        }

        $latestDraft = $this->latestDraft($chapter);
        if ($latestDraft !== null && (int) data_get($candidateArtifact->data, 'source_artifact_id') !== $latestDraft->getKey()) {
            return new StateValidationResult([$this->hard('INVALID_EVENT_REFERENCE', 'State Patch 对应的事件候选不是从当前最新章节草稿提取的。')]);
        }

        $stateVersion = $chapter->novel->canonicalStateVersion;
        $findings = [];

        if ($stateVersion === null || data_get($patchArtifact->data, 'expected_state_version') !== $stateVersion->version) {
            $findings[] = $this->hard(
                'STATE_VERSION_CONFLICT',
                'State Patch 的 Expected State Version 与当前 Canonical Story State 不一致。',
                relatedStatePath: 'schema_version',
            );
        }

        try {
            $patch = $this->patch($patchArtifact);
            $events = $this->events($candidateArtifact);
        } catch (Throwable $exception) {
            return new StateValidationResult([
                ...$findings,
                $this->hard('INVALID_STATE_PATCH', $exception->getMessage()),
            ]);
        }

        $state = $stateVersion?->state ?? [];
        $lockedFacts = $chapter->novel->facts()
            ->where('status', FactStatus::Active)
            ->where('locked', true)
            ->get();

        $this->validateReferences($chapter, $events, $findings);
        $this->validateLockedFacts($lockedFacts, $patchArtifact, $events, $findings);
        $this->validateEvents($chapter, $state, $events, $findings);
        $this->validatePatchRules($state, $patch, $events, $findings);

        return new StateValidationResult($this->uniqueFindings($findings));
    }

    /** @return array<int, StoryEventCandidate> */
    private function events(GenerationArtifact $artifact): array
    {
        $events = data_get($artifact->data, 'events');

        if (! is_array($events) || ! array_is_list($events)) {
            throw ValidationException::withMessages(['events' => 'Event Candidate Artifact 数据无效。']);
        }

        return array_map(fn (array $event): StoryEventCandidate => StoryEventCandidate::fromArray($event), $events);
    }

    private function latestDraft(Chapter $chapter): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey())->whereNull('scene_id'))
            ->latest('id')
            ->first();
    }

    private function patch(GenerationArtifact $artifact): StatePatch
    {
        return new StatePatch(
            expectedStateVersion: (int) data_get($artifact->data, 'expected_state_version', -1),
            operations: data_get($artifact->data, 'operations', []),
            factChanges: data_get($artifact->data, 'fact_changes', []),
            foreshadowingChanges: data_get($artifact->data, 'foreshadowing_changes', []),
        );
    }

    /** @param array<int, StoryEventCandidate> $events @param array<int, StateFinding> $findings */
    private function validateReferences(Chapter $chapter, array $events, array &$findings): void
    {
        foreach ($events as $event) {
            if ($event->subjectId === null || $event->subjectType === null) {
                continue;
            }

            $valid = match ($event->subjectType) {
                'character' => $chapter->novel->characters()->whereKey($event->subjectId)->exists(),
                'world_entity' => $chapter->novel->worldEntities()->whereKey($event->subjectId)->exists(),
                'foreshadowing' => $chapter->novel->foreshadowings()->whereKey($event->subjectId)->exists(),
                'chapter' => (string) $chapter->getKey() === $event->subjectId,
                default => true,
            };

            if (! $valid) {
                $findings[] = $this->hard(
                    'INVALID_EVENT_REFERENCE',
                    '候选事件引用了当前小说之外的实体。',
                    $event,
                );
            }
        }
    }

    /** @param Collection<int, Fact> $facts @param array<int, StoryEventCandidate> $events @param array<int, StateFinding> $findings */
    private function validateLockedFacts(Collection $facts, GenerationArtifact $patchArtifact, array $events, array &$findings): void
    {
        $changes = collect(data_get($patchArtifact->data, 'changes', []));

        foreach ($facts as $fact) {
            $expected = $fact->value['value'] ?? $fact->value;
            $paths = $this->factPaths($fact);
            $conflict = $changes->first(fn (array $change): bool => in_array($change['path'] ?? null, $paths, true)
                && ($change['after'] ?? null) !== $expected);

            if ($conflict === null) {
                continue;
            }

            $event = $events[$conflict['source_event_index']] ?? null;
            $findings[] = $this->hard(
                str_contains($fact->predicate, 'knowledge') || str_starts_with($fact->predicate, 'knows_')
                    ? 'KNOWLEDGE_CONFLICT'
                    : (str_contains($fact->predicate, 'rule') ? 'WORLD_RULE_CONFLICT' : 'LOCKED_FACT_CONFLICT'),
                "候选状态变化与锁定事实 #{$fact->getKey()} 冲突。",
                $event,
                $fact->getKey(),
                $conflict['path'],
                ['expected' => $expected, 'candidate' => $conflict['after'] ?? null],
            );
        }
    }

    /** @param array<string, mixed> $state @param array<int, StoryEventCandidate> $events @param array<int, StateFinding> $findings */
    private function validateEvents(Chapter $chapter, array $state, array $events, array &$findings): void
    {
        foreach ($events as $index => $event) {
            if ($event->subjectType !== 'character' || $event->subjectId === null) {
                continue;
            }

            $base = "characters.{$event->subjectId}";
            $status = data_get($state, "{$base}.status");

            if ($status === 'dead' && $this->isDeadCharacterAction($event) && ! $this->hasIntentionalOverride($chapter)) {
                $findings[] = $this->hard(
                    'CHARACTER_DEAD_CONFLICT',
                    '已死亡角色发生了普通行动，且 Chapter Plan 未声明复活、假死、梦境或时间跳跃。',
                    $event,
                    relatedStatePath: "{$base}.status",
                );
            }

            $requiredKnowledge = $event->payload['required_knowledge'] ?? null;
            if ($requiredKnowledge !== null
                && data_get($state, "{$base}.knowledge.{$requiredKnowledge}") !== true
                && ! $this->hasPriorEvent($events, $index, EventType::CharacterLearned, $event->subjectId, 'key', $requiredKnowledge)) {
                $findings[] = $this->hard('KNOWLEDGE_CONFLICT', '角色依据尚未获得的知识行动。', $event, relatedStatePath: "{$base}.knowledge.{$requiredKnowledge}");
            }

            $location = $event->payload['location'] ?? $event->payload['at'] ?? null;
            $currentLocation = data_get($state, "{$base}.location");
            if ($location !== null && $currentLocation !== null && $location !== $currentLocation
                && ! $this->hasPriorEvent($events, $index, EventType::CharacterMoved, $event->subjectId)
                && ! $this->hasIntentionalOverride($chapter)) {
                $findings[] = $this->hard('LOCATION_CONFLICT', "角色从「{$currentLocation}」无移动事件出现在「{$location}」。", $event, relatedStatePath: "{$base}.location");
            }

            $ability = $event->payload['ability_used'] ?? null;
            if ($ability !== null && data_get($state, "{$base}.abilities.{$ability}") !== true
                && ! $this->hasPriorEvent($events, $index, EventType::CharacterAbilityAcquired, $event->subjectId, 'ability', $ability)
                && ! $this->planContains($chapter, 'reveal')) {
                $findings[] = $this->hard('ABILITY_CONFLICT', '角色使用了尚未获得且未在 Plan 声明揭示的能力。', $event, relatedStatePath: "{$base}.abilities.{$ability}");
            }

            $itemId = $event->payload['item_id'] ?? null;
            $holderId = $event->payload['holder_id'] ?? null;
            $ownerPath = $itemId === null ? null : "items.{$itemId}.owner_id";
            $currentOwner = $ownerPath === null ? null : data_get($state, $ownerPath);
            if ($ownerPath !== null && $holderId !== null && $currentOwner !== null && (string) $currentOwner !== (string) $holderId
                && ! $this->hasPriorItemTransfer($events, $index, (string) $itemId, (string) $holderId)) {
                $findings[] = $this->hard('ITEM_OWNERSHIP_CONFLICT', '角色持有不属于自己的关键物品，且没有取得、转移或丢失事件。', $event, relatedStatePath: $ownerPath);
            }
        }
    }

    /** @param array<string, mixed> $state @param array<int, StateFinding> $findings */
    private function validatePatchRules(array $state, StatePatch $patch, array $events, array &$findings): void
    {
        foreach ($patch->operations as $operation) {
            if (! str_starts_with($operation['path'], 'items.')) {
                continue;
            }

            if (! str_ends_with($operation['path'], '.owner_id') || $operation['op'] !== 'set') {
                continue;
            }

            $currentOwner = data_get($state, $operation['path']);
            $eventType = ($events[$operation['source_event_index']] ?? null)?->eventType->value;

            if ($currentOwner !== null && $currentOwner !== $operation['value']
                && ! in_array($eventType, [EventType::ItemTransferred->value, EventType::ItemLost->value, EventType::ItemAcquired->value], true)) {
                $findings[] = $this->hard('ITEM_OWNERSHIP_CONFLICT', '关键物品的持有人发生变化，但没有取得、转移或丢失事件。', relatedStatePath: $operation['path']);
            }
        }
    }

    private function isDeadCharacterAction(StoryEventCandidate $event): bool
    {
        return in_array($event->eventType, [
            EventType::CharacterMoved,
            EventType::CharacterInjured,
            EventType::CharacterRecovered,
            EventType::CharacterGoalChanged,
            EventType::CharacterEmotionChanged,
            EventType::CharacterLearned,
            EventType::CharacterAbilityAcquired,
            EventType::CharacterAbilityChanged,
        ], true);
    }

    /** @param array<int, StoryEventCandidate> $events */
    private function hasPriorEvent(array $events, int $beforeIndex, EventType $type, string $subjectId, ?string $payloadKey = null, mixed $payloadValue = null): bool
    {
        foreach (array_slice($events, 0, $beforeIndex + 1) as $event) {
            if ($event->eventType !== $type || $event->subjectId !== $subjectId) {
                continue;
            }

            if ($payloadKey === null || ($event->payload[$payloadKey] ?? null) === $payloadValue) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, StoryEventCandidate> $events */
    private function hasPriorItemTransfer(array $events, int $beforeIndex, string $itemId, string $holderId): bool
    {
        foreach (array_slice($events, 0, $beforeIndex + 1) as $event) {
            if (! in_array($event->eventType, [EventType::ItemAcquired, EventType::ItemTransferred], true)
                || $event->subjectId !== $itemId) {
                continue;
            }

            if ((string) ($event->payload['owner_id'] ?? $event->payload['to'] ?? '') === $holderId) {
                return true;
            }
        }

        return false;
    }

    private function hasIntentionalOverride(Chapter $chapter): bool
    {
        foreach (self::INTENTIONAL_OVERRIDES as $override) {
            if ($this->planContains($chapter, $override)) {
                return true;
            }
        }

        return false;
    }

    private function planContains(Chapter $chapter, string $needle): bool
    {
        if ($chapter->latestPlan === null) {
            return false;
        }

        return str_contains(strtolower(json_encode($chapter->latestPlan->toArray(), JSON_UNESCAPED_UNICODE) ?: ''), strtolower($needle));
    }

    /** @return array<int, string> */
    private function factPaths(Fact $fact): array
    {
        $predicate = str_replace(['knows_', 'knowledge.', 'ability.', 'abilities.'], ['', '', '', ''], $fact->predicate);

        return match ($fact->subject_type) {
            'character' => match (true) {
                str_starts_with($fact->predicate, 'knows_'), str_starts_with($fact->predicate, 'knowledge.') => ["characters.{$fact->subject_id}.knowledge.{$predicate}"],
                str_starts_with($fact->predicate, 'ability.'), str_starts_with($fact->predicate, 'abilities.') => ["characters.{$fact->subject_id}.abilities.{$predicate}"],
                default => ["characters.{$fact->subject_id}.{$fact->predicate}"],
            },
            'world_entity' => ["world.{$fact->subject_id}.{$fact->predicate}", "world.{$fact->subject_id}"],
            default => ["world.{$fact->predicate}"],
        };
    }

    private function latestArtifact(Chapter $chapter, ArtifactType $type): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', $type)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->latest('version')
            ->latest('id')
            ->first();
    }

    private function hard(string $code, string $message, ?StoryEventCandidate $event = null, ?int $relatedFactId = null, ?string $relatedStatePath = null, array $metadata = []): StateFinding
    {
        return new StateFinding(
            code: $code,
            severity: StateFindingSeverity::Hard,
            message: $message,
            subjectType: $event?->subjectType,
            subjectId: $event?->subjectId,
            evidence: $event?->evidence ?? [],
            relatedFactId: $relatedFactId,
            relatedStatePath: $relatedStatePath,
            metadata: $metadata,
        );
    }

    /** @param array<int, StateFinding> $findings @return array<int, StateFinding> */
    private function uniqueFindings(array $findings): array
    {
        return collect($findings)->unique(fn (StateFinding $finding): string => implode('|', [
            $finding->code, $finding->subjectType, $finding->subjectId, $finding->relatedFactId, $finding->relatedStatePath,
        ]))->values()->all();
    }
}
