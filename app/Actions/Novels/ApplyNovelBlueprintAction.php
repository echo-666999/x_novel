<?php

namespace App\Actions\Novels;

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\CharacterStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Enums\WorldEntityStatus;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Services\NovelOutlineChecksum;
use App\Services\NovelOutlineValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyNovelBlueprintAction
{
    public function __construct(
        private readonly CreateBibleVersionAction $createBibleVersion,
        private readonly InitializeNovelStateAction $initializeNovelState,
        private readonly NovelOutlineValidator $outlineValidator,
        private readonly NovelOutlineChecksum $outlineChecksum,
    ) {}

    public function handle(Novel $novel, NovelOutline $outline, ?GenerationArtifact $artifact = null): Novel
    {
        return DB::transaction(function () use ($novel, $outline, $artifact): Novel {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $selected = NovelOutline::query()->lockForUpdate()->findOrFail($outline->getKey());

            if ($selected->novel_id !== $locked->getKey()) {
                throw ValidationException::withMessages(['outline' => '该 Outline Version 不属于当前小说。']);
            }

            if ($selected->status === NovelOutlineStatus::Current && $locked->current_outline_id === $selected->getKey()) {
                return $locked->refresh();
            }

            if ($selected->status !== NovelOutlineStatus::Draft) {
                throw ValidationException::withMessages(['outline' => '首次采用只接受 Draft Outline Version。']);
            }

            $this->outlineValidator->assertValid($selected->content);
            if (! hash_equals($selected->checksum, $this->outlineChecksum->for($selected->content))) {
                throw ValidationException::withMessages(['outline' => 'Outline checksum 与冻结内容不一致。']);
            }

            if ($locked->current_outline_id !== null || $locked->volumes()->exists() || $locked->storyArcs()->exists()) {
                throw ValidationException::withMessages(['outline' => '小说已有正式大纲、分卷或故事线，不能再次执行首次采用。']);
            }

            if ($locked->chapters()->exists() || $locked->storyEvents()->exists()) {
                throw ValidationException::withMessages(['outline' => '小说已经产生章节或正式事件，不能再采用初始大纲。']);
            }

            $bootstrap = $this->bootstrapData($locked, $selected, $artifact);
            if ($bootstrap !== null) {
                $this->assertEmptyBootstrapTables($locked);
                $this->persistAiBootstrap($locked, $bootstrap);
            } elseif ($locked->currentBible === null) {
                throw ValidationException::withMessages([
                    'outline' => '手工大纲采用前必须先创建小说圣经；初始人物和世界资料可在 Workspace 中手工建立。',
                ]);
            }

            $arcs = collect();
            foreach ($selected->content['volumes'] as $volumeData) {
                $volume = $locked->volumes()->create([
                    'outline_key' => $volumeData['key'],
                    'sequence' => $volumeData['sequence'],
                    'title' => $volumeData['title'],
                    'goal' => $volumeData['goal'],
                    'climax' => $volumeData['climax'],
                    'target_words' => $volumeData['target_words'],
                    'status' => $volumeData['sequence'] === 1 ? VolumeStatus::Active : VolumeStatus::Planned,
                ]);

                foreach ($volumeData['arcs'] as $arcData) {
                    $arc = $locked->storyArcs()->create([
                        'volume_id' => $volume->getKey(),
                        'outline_key' => $arcData['key'],
                        'sequence' => $arcData['sequence'],
                        'type' => $arcData['type'],
                        'title' => $arcData['title'],
                        'goal' => $arcData['goal'],
                        'stakes' => $arcData['stakes'],
                        'beats' => $arcData['beats'],
                        'completion_conditions' => $arcData['completion_conditions'],
                        'progress' => 0,
                        'status' => $volumeData['sequence'] === 1 ? StoryArcStatus::Active : StoryArcStatus::Planned,
                    ]);
                    $arcs->put($arcData['key'], $arc);
                }
            }

            if ($bootstrap !== null) {
                foreach ($bootstrap['foreshadowings'] as $item) {
                    $locked->foreshadowings()->create([
                        ...collect($item)->except('owner_arc_key')->all(),
                        'owner_arc_id' => filled($item['owner_arc_key'] ?? null) ? $arcs->get($item['owner_arc_key'])?->getKey() : null,
                        'status' => ForeshadowingStatus::Idea,
                        'reinforce_count' => 0,
                    ]);
                }
            }

            $locked->outlines()
                ->whereKeyNot($selected->getKey())
                ->where('status', NovelOutlineStatus::Draft->value)
                ->get()
                ->each(fn (NovelOutline $draft) => $draft->update(['status' => NovelOutlineStatus::Superseded]));
            $selected->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
            $locked->update([
                'current_outline_id' => $selected->getKey(),
                'status' => NovelStatus::Planning,
            ]);

            if ($locked->canonical_state_version_id === null) {
                $this->initializeNovelState->handle($locked);
            } else {
                $this->initializeNovelState->refreshBeforeFirstChapter($locked);
            }

            return $locked->refresh();
        });
    }

    /** @return array<string, mixed>|null */
    private function bootstrapData(Novel $novel, NovelOutline $outline, ?GenerationArtifact $artifact): ?array
    {
        $root = $outline;
        while ($root->based_on_outline_id !== null) {
            $root = $root->basedOn()->firstOrFail();
        }

        if ($root->source !== NovelOutlineSource::Ai) {
            if ($artifact !== null) {
                throw ValidationException::withMessages(['blueprint' => '手工 Outline 不能绑定 AI Blueprint Artifact。']);
            }

            return null;
        }

        if ($artifact === null || $artifact->generationRun()
            ->where('novel_id', $novel->getKey())
            ->where('scope_type', 'novel')
            ->doesntExist()) {
            throw ValidationException::withMessages(['blueprint' => 'AI Outline 缺少属于当前小说的来源 Artifact。']);
        }

        $artifactOutline = data_get($artifact->data, 'outline');
        $artifactChecksum = is_array($artifactOutline) ? $this->outlineChecksum->for($artifactOutline) : null;
        if ($artifactChecksum === null
            || (! hash_equals($root->checksum, $artifactChecksum) && ! hash_equals($outline->checksum, $artifactChecksum))) {
            throw ValidationException::withMessages(['blueprint' => 'AI Outline 与来源 Artifact 不匹配。']);
        }

        /** @var array<string, mixed> $data */
        $data = $artifact->data;

        return $data;
    }

    private function assertEmptyBootstrapTables(Novel $novel): void
    {
        if ($novel->bibles()->exists() || $novel->characters()->exists() || $novel->worldEntities()->exists() || $novel->foreshadowings()->exists()) {
            throw ValidationException::withMessages(['blueprint' => 'AI 初始规划只能应用到尚未手工建立 Bible、人物、世界资料或伏笔的小说。']);
        }
    }

    /** @param array<string, mixed> $data */
    private function persistAiBootstrap(Novel $novel, array $data): void
    {
        $this->createBibleVersion->execute($novel, $data['bible']);

        foreach ($data['characters'] as $character) {
            $novel->characters()->create([
                ...$character,
                'aliases' => [],
                'locked_fields' => [],
                'status' => CharacterStatus::Active,
            ]);
        }

        foreach ($data['world_entities'] as $entity) {
            $novel->worldEntities()->create([
                ...$entity,
                'locked_fields' => [],
                'status' => WorldEntityStatus::Active,
            ]);
        }
    }
}
