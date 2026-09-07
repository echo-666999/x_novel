<?php

namespace App\Services;

use App\Contracts\StoryEventApplier;
use App\Data\StoryEventCandidate;
use App\Enums\EventType;

class DeterministicStoryEventApplier implements StoryEventApplier
{
    public function supports(string $eventType): bool
    {
        return EventType::tryFrom($eventType) !== null;
    }

    public function operations(StoryEventCandidate $event): array
    {
        $id = $this->segment($event->subjectId);
        $payload = $event->payload;

        if ($id === '_unresolved') {
            return [];
        }

        return match ($event->eventType) {
            EventType::CharacterMoved => $this->set("characters.{$id}.location", $payload['to'] ?? null),
            EventType::CharacterStatusChanged => $this->set("characters.{$id}.status", $payload['status'] ?? $payload['to'] ?? null),
            EventType::CharacterInjured => $this->set("characters.{$id}.health", [
                'state' => $payload['state'] ?? 'injured',
                'notes' => $payload['notes'] ?? null,
            ]),
            EventType::CharacterRecovered => $this->set("characters.{$id}.health", [
                'state' => $payload['state'] ?? 'normal',
                'notes' => $payload['notes'] ?? null,
            ]),
            EventType::CharacterGoalChanged => $this->set("characters.{$id}.goals", $payload['goals'] ?? (isset($payload['goal']) ? [$payload['goal']] : null)),
            EventType::CharacterEmotionChanged => $this->set("characters.{$id}.emotion", $payload['emotion'] ?? $payload['to'] ?? null),
            EventType::CharacterLearned => $this->knowledge($id, $payload, false),
            EventType::CharacterForgot => $this->knowledge($id, $payload, true),
            EventType::CharacterAbilityAcquired, EventType::CharacterAbilityChanged => $this->keyedSet("characters.{$id}.abilities", $payload, 'ability'),

            EventType::RelationshipChanged => $this->set("relationships.{$id}", $payload['state'] ?? $payload),
            EventType::PromiseMade => $this->append("relationships.{$id}.promises", $payload['promise'] ?? null),
            EventType::PromiseBroken => $this->remove("relationships.{$id}.promises", $payload['promise'] ?? null),
            EventType::DebtCreated => $this->append("relationships.{$id}.debts", $payload['debt'] ?? null),
            EventType::DebtResolved => $this->remove("relationships.{$id}.debts", $payload['debt'] ?? null),

            EventType::ItemAcquired, EventType::ItemTransferred => $this->itemOwnership($id, $payload),
            EventType::ItemLost => $this->set("items.{$id}.owner_id", null),
            EventType::ItemDestroyed => $this->set("items.{$id}.status", 'destroyed'),
            EventType::ItemStateChanged => $this->set("items.{$id}.status", $payload['status'] ?? $payload['to'] ?? null),

            EventType::ConflictStarted => $this->set("open_threads.{$id}", ['type' => 'conflict', 'status' => 'open', ...$payload]),
            EventType::ConflictEscalated, EventType::ThreadProgressed => $this->set("open_threads.{$id}.status", $payload['status'] ?? 'progressed'),
            EventType::ConflictResolved, EventType::ThreadClosed => $this->set("open_threads.{$id}.status", 'resolved'),
            EventType::ThreadOpened => $this->set("open_threads.{$id}", ['status' => 'open', ...$payload]),
            EventType::ReaderPromiseCreated => $this->set("reader_promises.{$id}", ['status' => 'open', ...$payload]),
            EventType::ReaderPromiseResolved => $this->set("reader_promises.{$id}.status", 'resolved'),

            EventType::ForeshadowingPlanted => $this->set("foreshadowings.{$id}", ['status' => 'planted', ...$payload]),
            EventType::ForeshadowingReinforced => [
                ...$this->set("foreshadowings.{$id}.status", 'reinforced'),
                ...$this->increment("foreshadowings.{$id}.reinforce_count", 1),
            ],
            EventType::ForeshadowingDue => $this->set("foreshadowings.{$id}.status", 'due'),
            EventType::ForeshadowingPaidOff => $this->set("foreshadowings.{$id}.status", 'paid_off'),
            EventType::ForeshadowingAbandoned => $this->set("foreshadowings.{$id}.status", 'abandoned'),

            EventType::WorldRuleRevealed, EventType::WorldRuleChanged, EventType::WorldStateChanged => $this->set("world.{$id}", $payload['state'] ?? $payload),
            EventType::LocationStateChanged => $this->set("locations.{$id}", $payload['state'] ?? $payload),
            EventType::FactionStateChanged => $this->set("world.factions.{$id}", $payload['state'] ?? $payload),

            EventType::EventCorrected, EventType::EventInvalidated, EventType::ManualCorrection => [],
        };
    }

    private function segment(?string $value): string
    {
        return $value !== null && $value !== '' && ! str_contains($value, '.') ? $value : '_unresolved';
    }

    /** @return array<int, array{op: string, path: string, value: mixed}> */
    private function set(string $path, mixed $value): array
    {
        return $value === null ? [] : [['op' => 'set', 'path' => $path, 'value' => $value]];
    }

    /** @return array<int, array{op: string, path: string, value: mixed}> */
    private function append(string $path, mixed $value): array
    {
        return $value === null ? [] : [['op' => 'append_unique', 'path' => $path, 'value' => $value]];
    }

    /** @return array<int, array{op: string, path: string, value: mixed}> */
    private function remove(string $path, mixed $value): array
    {
        return $value === null ? [] : [['op' => 'remove', 'path' => $path, 'value' => $value]];
    }

    /** @return array<int, array{op: string, path: string, value: int}> */
    private function increment(string $path, int $value): array
    {
        return [['op' => 'increment', 'path' => $path, 'value' => $value]];
    }

    /** @return array<int, array{op: string, path: string, value?: mixed}> */
    private function knowledge(string $id, array $payload, bool $forget): array
    {
        $key = $this->segment(isset($payload['key']) ? (string) $payload['key'] : ($payload['knowledge'] ?? null));

        if ($key === '_unresolved') {
            return [];
        }

        return $forget
            ? [['op' => 'unset', 'path' => "characters.{$id}.knowledge.{$key}"]]
            : $this->set("characters.{$id}.knowledge.{$key}", $payload['value'] ?? true);
    }

    /** @return array<int, array{op: string, path: string, value: mixed}> */
    private function keyedSet(string $basePath, array $payload, string $keyName): array
    {
        $key = $this->segment(isset($payload[$keyName]) ? (string) $payload[$keyName] : null);

        return $key === '_unresolved' ? [] : $this->set("{$basePath}.{$key}", $payload['value'] ?? true);
    }

    /** @return array<int, array{op: string, path: string, value: mixed}> */
    private function itemOwnership(string $id, array $payload): array
    {
        return array_values(array_merge(
            $this->set("items.{$id}.owner_type", $payload['owner_type'] ?? 'character'),
            $this->set("items.{$id}.owner_id", $payload['owner_id'] ?? $payload['to'] ?? null),
        ));
    }
}
