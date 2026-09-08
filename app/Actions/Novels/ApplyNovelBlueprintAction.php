<?php

namespace App\Actions\Novels;

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\CharacterStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\NovelStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Enums\WorldEntityStatus;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyNovelBlueprintAction
{
    public function __construct(
        private readonly CreateBibleVersionAction $createBibleVersion,
        private readonly InitializeNovelStateAction $initializeNovelState,
    ) {}

    public function handle(Novel $novel, GenerationArtifact $artifact): Novel
    {
        $data = $artifact->data ?? [];

        return DB::transaction(function () use ($novel, $artifact, $data): Novel {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if ($artifact->generationRun()->where('novel_id', $locked->getKey())->where('scope_type', 'novel')->doesntExist()) {
                throw ValidationException::withMessages(['blueprint' => '该规划不属于当前小说。']);
            }

            if ($locked->bibles()->exists() || $locked->volumes()->exists() || $locked->storyArcs()->exists()
                || $locked->characters()->exists() || $locked->worldEntities()->exists() || $locked->foreshadowings()->exists()) {
                throw ValidationException::withMessages(['blueprint' => '小说已有规划数据。为避免覆盖人工内容，只能对空白小说采用初始规划。']);
            }

            if ($locked->chapters()->exists() || $locked->storyEvents()->exists()) {
                throw ValidationException::withMessages(['blueprint' => '小说已经产生章节或正式事件，不能再采用初始规划。']);
            }

            $this->createBibleVersion->execute($locked, $data['bible']);

            $volumes = collect($data['volumes'])->mapWithKeys(function (array $volume, int $index) use ($locked): array {
                $created = $locked->volumes()->create([
                    'sequence' => $index + 1,
                    'title' => $volume['title'],
                    'goal' => $volume['goal'],
                    'climax' => $volume['climax'],
                    'target_words' => $volume['target_words'],
                    'status' => $index === 0 ? VolumeStatus::Active : VolumeStatus::Planned,
                ]);

                return [$volume['key'] => $created];
            });

            $arcs = collect($data['story_arcs'])->mapWithKeys(function (array $arc) use ($locked, $volumes): array {
                $created = $locked->storyArcs()->create([
                    'volume_id' => $volumes->get($arc['volume_key'])->getKey(),
                    'type' => $arc['type'],
                    'title' => $arc['title'],
                    'goal' => $arc['goal'],
                    'stakes' => $arc['stakes'],
                    'beats' => $arc['beats'],
                    'completion_conditions' => $arc['completion_conditions'],
                    'progress' => 0,
                    'status' => $volumes->get($arc['volume_key'])->sequence === 1 ? StoryArcStatus::Active : StoryArcStatus::Planned,
                ]);

                return [$arc['key'] => $created];
            });

            foreach ($data['characters'] as $character) {
                $locked->characters()->create([
                    ...$character,
                    'aliases' => [],
                    'locked_fields' => [],
                    'status' => CharacterStatus::Active,
                ]);
            }

            foreach ($data['world_entities'] as $entity) {
                $locked->worldEntities()->create([
                    ...$entity,
                    'locked_fields' => [],
                    'status' => WorldEntityStatus::Active,
                ]);
            }

            foreach ($data['foreshadowings'] as $item) {
                $locked->foreshadowings()->create([
                    ...collect($item)->except('owner_arc_key')->all(),
                    'owner_arc_id' => filled($item['owner_arc_key'] ?? null) ? $arcs->get($item['owner_arc_key'])?->getKey() : null,
                    'status' => ForeshadowingStatus::Idea,
                    'reinforce_count' => 0,
                ]);
            }

            $locked->update(['status' => NovelStatus::Planning]);

            if ($locked->canonical_state_version_id === null) {
                $this->initializeNovelState->handle($locked);
            } else {
                $this->initializeNovelState->refreshBeforeFirstChapter($locked);
            }

            return $locked->refresh();
        });
    }
}
