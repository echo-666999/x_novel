<?php

namespace App\Actions\Story;

use App\Enums\WorldEntityType;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Models\WorldEntity;
use App\Services\StoryStateService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class InitializeNovelStateAction
{
    private const SCHEMA_VERSION = 1;

    public function __construct(private readonly StoryStateService $storyState) {}

    public function handle(Novel $novel): StoryStateVersion
    {
        return DB::transaction(function () use ($novel): StoryStateVersion {
            $lockedNovel = Novel::query()
                ->lockForUpdate()
                ->findOrFail($novel->getKey());

            $existing = $lockedNovel->storyStateVersions()
                ->where('version', 0)
                ->first();

            if ($existing) {
                if ($lockedNovel->canonical_state_version_id === null) {
                    $lockedNovel->canonical_state_version_id = $existing->getKey();
                    $lockedNovel->save();
                }

                return $existing;
            }

            $lockedNovel->load([
                'currentBible',
                'characters',
                'worldEntities',
                'foreshadowings',
            ]);

            $state = $this->initialState($lockedNovel);
            $stateVersion = $lockedNovel->storyStateVersions()->create([
                'version' => 0,
                'chapter_id' => null,
                'state' => $state,
                'checksum' => $this->storyState->checksum($state),
            ]);

            $lockedNovel->canonical_state_version_id = $stateVersion->getKey();
            $lockedNovel->save();

            return $stateVersion;
        });
    }

    public function refreshBeforeFirstChapter(Novel $novel): StoryStateVersion
    {
        return DB::transaction(function () use ($novel): StoryStateVersion {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if ($lockedNovel->chapters()->exists() || $lockedNovel->storyEvents()->exists()) {
                throw new \LogicException('已有章节或正式事件时不能刷新初始故事状态。');
            }

            $lockedNovel->load(['currentBible', 'characters', 'worldEntities', 'foreshadowings']);
            $state = $this->initialState($lockedNovel);
            $version = ((int) $lockedNovel->storyStateVersions()->max('version')) + 1;
            $stateVersion = $lockedNovel->storyStateVersions()->create([
                'version' => $version,
                'chapter_id' => null,
                'state' => $state,
                'checksum' => $this->storyState->checksum($state),
            ]);

            $lockedNovel->update(['canonical_state_version_id' => $stateVersion->getKey()]);

            return $stateVersion;
        });
    }

    /** @return array<string, mixed> */
    private function initialState(Novel $novel): array
    {
        $worldEntities = $novel->worldEntities;
        $bible = $novel->currentBible;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'characters' => $novel->characters->mapWithKeys(function ($character): array {
                return [(string) $character->getKey() => [
                    'name' => $character->name,
                    'status' => $character->status->value,
                    ...Arr::except($character->current_state ?? [], ['relationships']),
                ]];
            })->all(),
            'relationships' => $novel->characters
                ->pluck('current_state')
                ->pluck('relationships')
                ->filter(fn ($relationships): bool => is_array($relationships))
                ->reduce(fn (array $relationships, array $characterRelationships): array => array_replace($relationships, $characterRelationships), []),
            'locations' => $this->worldState($worldEntities, [WorldEntityType::Location]),
            'items' => $this->worldState($worldEntities, [WorldEntityType::Item]),
            'world' => [
                'hard_constraints' => $bible?->hard_constraints ?? [],
                'entities' => $this->worldState($worldEntities, [
                    WorldEntityType::Faction,
                    WorldEntityType::Organization,
                    WorldEntityType::Rule,
                    WorldEntityType::Concept,
                ]),
            ],
            'timeline' => [],
            'open_threads' => [],
            'foreshadowings' => $novel->foreshadowings->mapWithKeys(fn ($foreshadowing): array => [
                (string) $foreshadowing->getKey() => [
                    'title' => $foreshadowing->title,
                    'status' => $foreshadowing->status->value,
                    'importance' => $foreshadowing->importance->value,
                    'reinforce_count' => $foreshadowing->reinforce_count,
                    'due_from' => $foreshadowing->due_from_chapter,
                    'due_to' => $foreshadowing->due_to_chapter,
                ],
            ])->all(),
            'reader_promises' => $bible?->ending_contract ?? [],
        ];
    }

    /**
     * @param  Collection<int, WorldEntity>  $entities
     * @param  array<WorldEntityType>  $types
     * @return array<string, mixed>
     */
    private function worldState(Collection $entities, array $types): array
    {
        return $entities
            ->whereIn('type', $types)
            ->mapWithKeys(fn ($entity): array => [
                (string) $entity->getKey() => [
                    'name' => $entity->name,
                    'type' => $entity->type->value,
                    'status' => $entity->status->value,
                    'attributes' => $entity->attributes,
                    'rules' => $entity->rules,
                    'current_state' => $entity->current_state,
                ],
            ])
            ->all();
    }
}
