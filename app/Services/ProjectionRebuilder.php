<?php

namespace App\Services;

use App\Data\ProjectionHealth;
use App\Enums\EventType;
use App\Enums\ForeshadowingStatus;
use App\Enums\WorldEntityType;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\WorldEntity;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectionRebuilder
{
    public function inspect(Novel $novel): ProjectionHealth
    {
        $novel->loadMissing(['canonicalStateVersion', 'characters', 'worldEntities', 'foreshadowings']);
        $version = $novel->canonicalStateVersion;

        if ($version === null) {
            throw ValidationException::withMessages([
                'state' => '小说尚未初始化 Canonical Story State。',
            ]);
        }

        $state = $version->state;
        $characterStates = [];
        $worldEntityStates = [];
        $foreshadowingStatuses = [];
        $foreshadowingProjections = [];
        $characterDriftIds = [];
        $worldEntityDriftIds = [];
        $foreshadowingDriftIds = [];
        $errors = [];

        foreach ($novel->characters as $character) {
            $snapshot = data_get($state, 'characters.'.$character->getKey());

            if (! is_array($snapshot)) {
                continue;
            }

            $expected = Arr::except($snapshot, ['name', 'status']);
            $characterStates[$character->getKey()] = $expected;

            if ($character->current_state !== $expected) {
                $characterDriftIds[] = $character->getKey();
            }
        }

        foreach ($novel->worldEntities as $entity) {
            $snapshot = $this->worldSnapshot($state, $entity);

            if (! is_array($snapshot)) {
                continue;
            }

            $expected = $this->worldCurrentState($snapshot);
            $worldEntityStates[$entity->getKey()] = $expected;

            if ($entity->current_state !== $expected) {
                $worldEntityDriftIds[] = $entity->getKey();
            }
        }

        $baseline = $novel->storyStateVersions()
            ->whereNull('chapter_id')
            ->where('version', '<=', $version->version)
            ->latest('version')
            ->first()?->state ?? [];
        $foreshadowingEvents = $novel->storyEvents()
            ->active()
            ->where('state_version', '<=', $version->version)
            ->where(function ($query): void {
                $query->where('subject_type', 'foreshadowing')
                    ->whereIn('event_type', array_map(fn (EventType $type): string => $type->value, [
                        EventType::ForeshadowingPlanted,
                        EventType::ForeshadowingReinforced,
                        EventType::ForeshadowingPaidOff,
                        EventType::ForeshadowingAbandoned,
                    ]))
                    ->orWhere('event_type', EventType::ManualCorrection->value);
            })
            ->orderBy('state_version')
            ->orderBy('id')
            ->get();

        foreach ($novel->foreshadowings as $foreshadowing) {
            $value = data_get($state, 'foreshadowings.'.$foreshadowing->getKey().'.status');

            if ($value === null) {
                continue;
            }

            $status = is_string($value) ? ForeshadowingStatus::tryFrom($value) : null;

            if ($status === null) {
                $errors[] = "伏笔 #{$foreshadowing->getKey()} 的 Canonical status 无效。";

                continue;
            }

            $projection = $this->foreshadowingProjection(
                $foreshadowing->getKey(),
                data_get($baseline, 'foreshadowings.'.$foreshadowing->getKey(), []),
                data_get($state, 'foreshadowings.'.$foreshadowing->getKey(), []),
                $foreshadowingEvents,
            );
            $canonicalCount = (int) data_get($state, 'foreshadowings.'.$foreshadowing->getKey().'.reinforce_count', 0);

            if ($projection['status'] !== $status->value || $projection['reinforce_count'] !== $canonicalCount) {
                $errors[] = "伏笔 #{$foreshadowing->getKey()} 的 Canonical State 与 Active Story Events 重放结果不一致。";

                continue;
            }

            $foreshadowingStatuses[$foreshadowing->getKey()] = $projection['status'];
            $foreshadowingProjections[$foreshadowing->getKey()] = $projection;

            if ($foreshadowing->status->value !== $projection['status']
                || $foreshadowing->reinforce_count !== $projection['reinforce_count']
                || $foreshadowing->setup_chapter_id !== $projection['setup_chapter_id']
                || $foreshadowing->payoff_chapter_id !== $projection['payoff_chapter_id']) {
                $foreshadowingDriftIds[] = $foreshadowing->getKey();
            }
        }

        return new ProjectionHealth(
            stateVersion: $version->version,
            charactersChecked: count($characterStates),
            worldEntitiesChecked: count($worldEntityStates),
            foreshadowingsChecked: count($foreshadowingStatuses),
            characterDriftIds: $characterDriftIds,
            worldEntityDriftIds: $worldEntityDriftIds,
            foreshadowingDriftIds: $foreshadowingDriftIds,
            errors: $errors,
            characterStates: $characterStates,
            worldEntityStates: $worldEntityStates,
            foreshadowingStatuses: $foreshadowingStatuses,
            foreshadowingProjections: $foreshadowingProjections,
        );
    }

    public function rebuild(Novel $novel): ProjectionHealth
    {
        return DB::transaction(function () use ($novel): ProjectionHealth {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $health = $this->inspect($lockedNovel);

            if ($health->errors !== []) {
                throw ValidationException::withMessages(['projection' => $health->errors]);
            }

            foreach (Arr::only($health->characterStates, $health->characterDriftIds) as $id => $state) {
                $lockedNovel->characters()->whereKey($id)->update(['current_state' => $state]);
            }

            foreach (Arr::only($health->worldEntityStates, $health->worldEntityDriftIds) as $id => $state) {
                $lockedNovel->worldEntities()->whereKey($id)->update(['current_state' => $state]);
            }

            foreach (Arr::only($health->foreshadowingProjections, $health->foreshadowingDriftIds) as $id => $projection) {
                $lockedNovel->foreshadowings()->whereKey($id)->update($projection);
            }

            $lockedNovel->unsetRelations();

            return $this->inspect($lockedNovel);
        }, 3);
    }

    /** @param array<string, mixed> $state */
    private function worldSnapshot(array $state, WorldEntity $entity): mixed
    {
        $id = $entity->getKey();

        return match ($entity->type) {
            WorldEntityType::Location => data_get($state, "locations.{$id}"),
            WorldEntityType::Item => data_get($state, "items.{$id}"),
            WorldEntityType::Faction => data_get($state, "world.factions.{$id}")
                ?? data_get($state, "world.{$id}")
                ?? data_get($state, "world.entities.{$id}"),
            default => data_get($state, "world.{$id}")
                ?? data_get($state, "world.entities.{$id}"),
        };
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function worldCurrentState(array $snapshot): array
    {
        $current = is_array($snapshot['current_state'] ?? null) ? $snapshot['current_state'] : [];
        $overlay = Arr::except($snapshot, ['name', 'type', 'status', 'attributes', 'rules', 'current_state']);

        return array_replace_recursive($current, $overlay);
    }

    /**
     * @param  Collection<int, StoryEvent>  $events
     * @return array{status: string, reinforce_count: int, setup_chapter_id: int|null, payoff_chapter_id: int|null}
     */
    private function foreshadowingProjection(int $id, mixed $baseline, mixed $canonical, Collection $events): array
    {
        $relevantEvents = $events->filter(fn ($event): bool => ($event->subject_type === 'foreshadowing' && (int) $event->subject_id === $id)
            || ($event->event_type === EventType::ManualCorrection
                && str_starts_with((string) data_get($event->payload, 'path'), "foreshadowings.{$id}")));
        $baseline = is_array($baseline) && $baseline !== []
            ? $baseline
            : ($relevantEvents->isEmpty() && is_array($canonical) ? $canonical : []);
        $projection = [
            'status' => ForeshadowingStatus::tryFrom((string) ($baseline['status'] ?? ''))?->value ?? ForeshadowingStatus::Idea->value,
            'reinforce_count' => max(0, (int) ($baseline['reinforce_count'] ?? 0)),
            'setup_chapter_id' => null,
            'payoff_chapter_id' => null,
        ];

        foreach ($relevantEvents as $event) {
            if ($event->event_type === EventType::ManualCorrection) {
                $this->applyManualForeshadowingCorrection($projection, $id, $event->payload);

                continue;
            }

            if ($event->subject_type !== 'foreshadowing' || (int) $event->subject_id !== $id) {
                continue;
            }

            switch ($event->event_type) {
                case EventType::ForeshadowingPlanted:
                    $this->applyPlant($projection, $event->chapter_id);
                    break;
                case EventType::ForeshadowingReinforced:
                    $this->applyReinforce($projection);
                    break;
                case EventType::ForeshadowingPaidOff:
                    $this->applyPayoff($projection, $event->chapter_id);
                    break;
                case EventType::ForeshadowingAbandoned:
                    $this->applyAbandon($projection);
                    break;
                default:
                    break;
            }
        }

        return $projection;
    }

    /** @param array<string, mixed> $projection */
    private function applyPlant(array &$projection, ?int $chapterId): void
    {
        $projection['status'] = ForeshadowingStatus::Planted->value;
        $projection['setup_chapter_id'] ??= $chapterId;
    }

    /** @param array<string, mixed> $projection */
    private function applyReinforce(array &$projection): void
    {
        $projection['status'] = ForeshadowingStatus::Reinforced->value;
        $projection['reinforce_count']++;
    }

    /** @param array<string, mixed> $projection */
    private function applyPayoff(array &$projection, ?int $chapterId): void
    {
        $projection['status'] = ForeshadowingStatus::PaidOff->value;
        $projection['payoff_chapter_id'] = $chapterId;
    }

    /** @param array<string, mixed> $projection */
    private function applyAbandon(array &$projection): void
    {
        $projection['status'] = ForeshadowingStatus::Abandoned->value;
        $projection['payoff_chapter_id'] = null;
    }

    /** @param array<string, mixed> $projection @param array<string, mixed> $payload */
    private function applyManualForeshadowingCorrection(array &$projection, int $id, array $payload): void
    {
        $path = (string) ($payload['path'] ?? '');
        $prefix = "foreshadowings.{$id}";

        if ($path === $prefix && is_array($payload['after'] ?? null)) {
            $after = $payload['after'];
            $this->setCorrectedStatus($projection, $after['status'] ?? null);
            if (array_key_exists('reinforce_count', $after)) {
                $projection['reinforce_count'] = max(0, (int) $after['reinforce_count']);
            }

            return;
        }

        if ($path === $prefix.'.status') {
            $this->setCorrectedStatus($projection, $payload['after'] ?? null);
        } elseif ($path === $prefix.'.reinforce_count') {
            $projection['reinforce_count'] = max(0, (int) ($payload['after'] ?? 0));
        }
    }

    /** @param array<string, mixed> $projection */
    private function setCorrectedStatus(array &$projection, mixed $value): void
    {
        $status = is_string($value) ? ForeshadowingStatus::tryFrom($value) : null;
        if ($status !== null && ! $status->isLegacyDue()) {
            $projection['status'] = $status->value;
        }
    }
}
