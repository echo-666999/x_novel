<?php

namespace App\Services;

use App\Data\ProjectionHealth;
use App\Enums\ForeshadowingStatus;
use App\Enums\WorldEntityType;
use App\Models\Novel;
use App\Models\WorldEntity;
use Illuminate\Support\Arr;
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

            $foreshadowingStatuses[$foreshadowing->getKey()] = $status->value;

            if ($foreshadowing->status !== $status) {
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

            foreach (Arr::only($health->foreshadowingStatuses, $health->foreshadowingDriftIds) as $id => $status) {
                $lockedNovel->foreshadowings()->whereKey($id)->update(['status' => $status]);
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
}
