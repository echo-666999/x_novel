<?php

namespace App\Actions\Novels;

use App\Data\NormalizedNovelOutline;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\RunStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Models\ChapterPlan;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\User;
use App\Services\NormalizedNovelOutlineValidator;
use App\Services\NovelOutlineChecksum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 在保护已引用节点的前提下创建并采用新的关系化 Outline 版本。
 */
class ApplyNovelOutlineRevisionAction
{
    public function __construct(
        private readonly CreateNormalizedNovelOutlineVersionAction $createVersion,
        private readonly NormalizedNovelOutlineValidator $validator,
        private readonly NovelOutlineChecksum $checksum,
    ) {}

    /** @param array<string, mixed>|NormalizedNovelOutline $outline 校验预期版本后原子切换修订。 */
    public function handle(
        Novel $novel,
        array|NormalizedNovelOutline $outline,
        int $expectedCurrentOutlineId,
        string $expectedCurrentChecksum,
        ?User $creator = null,
    ): NovelOutline {
        $dto = $outline instanceof NormalizedNovelOutline ? $outline : NormalizedNovelOutline::fromArray($outline);
        $this->validator->assertValid($dto);
        $targetChecksum = $this->checksum->for($dto);

        return DB::transaction(function () use ($novel, $dto, $expectedCurrentOutlineId, $expectedCurrentChecksum, $creator, $targetChecksum): NovelOutline {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $current = $lockedNovel->currentOutline()->lockForUpdate()->first();
            if ($current === null || $current->status !== NovelOutlineStatus::Current) {
                throw ValidationException::withMessages(['outline' => '小说尚未采用 Current Outline，不能执行连载中修订。']);
            }
            if (hash_equals($current->checksum, $targetChecksum)) {
                return $current;
            }
            if ($current->getKey() !== $expectedCurrentOutlineId
                || ! hash_equals($current->checksum, $expectedCurrentChecksum)) {
                throw ValidationException::withMessages(['outline_checksum' => 'Current Outline 已变化，请重新载入后再提交修订。']);
            }
            if (! hash_equals($current->checksum, $this->checksum->for($current))) {
                throw ValidationException::withMessages(['outline_checksum' => 'Current Outline checksum 与关系定义不一致。']);
            }
            if ($lockedNovel->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
                throw ValidationException::withMessages(['generation_runs' => '小说仍有排队中或运行中的 Generation Run；请先安全停止后再修订大纲。']);
            }

            $before = NormalizedNovelOutline::fromModel($current)->toArray();
            $after = $dto->toArray();
            $protection = $this->protectedKeys($lockedNovel, $current);
            $this->assertProtectedNodesUnchanged($before, $after, $protection);

            $revision = $this->createVersion->handle(
                novel: $lockedNovel,
                outline: $dto,
                source: NovelOutlineSource::Revision,
                basedOn: $current,
                creator: $creator,
            );
            $this->synchronizeRuntimeProjections($lockedNovel, $revision, $protection);

            $current->update(['status' => NovelOutlineStatus::Superseded]);
            $revision->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
            $lockedNovel->update(['current_outline_id' => $revision->getKey()]);

            return $revision->refresh()->load('volumes.arcs.beats.milestones');
        }, 3);
    }

    /** @return array{volume: array<string, true>, arc: array<string, true>, beat: array<string, true>, milestone: array<string, true>} 收集不得被修订的正式引用节点。 */
    private function protectedKeys(Novel $novel, NovelOutline $outline): array
    {
        $planArcIds = ChapterPlan::query()->where('novel_outline_id', $outline->getKey())
            ->pluck('primary_outline_arc_id')->filter()->all();
        $planBeatIds = ChapterPlan::query()->where('novel_outline_id', $outline->getKey())
            ->pluck('primary_outline_beat_id')->filter()->all();
        $planMilestoneIds = ChapterPlan::query()->where('novel_outline_id', $outline->getKey())
            ->pluck('primary_outline_milestone_id')->filter()->all();
        $eventArcIds = $novel->storyEvents()->where('novel_outline_id', $outline->getKey())
            ->pluck('novel_outline_arc_id')->filter()->all();
        $eventBeatIds = $novel->storyEvents()->where('novel_outline_id', $outline->getKey())
            ->pluck('novel_outline_beat_id')->filter()->all();
        $eventMilestoneIds = $novel->storyEvents()->where('novel_outline_id', $outline->getKey())
            ->pluck('novel_outline_milestone_id')->filter()->all();

        $arcs = $outline->arcs()->whereIn('id', [...$planArcIds, ...$eventArcIds])->get();
        $beats = $outline->beats()->whereIn('id', [...$planBeatIds, ...$eventBeatIds])->with('arc.volume')->get();
        $milestones = $outline->milestones()->whereIn('id', [...$planMilestoneIds, ...$eventMilestoneIds])->with('beat.arc.volume')->get();
        $volumeKeys = $arcs->load('volume')->pluck('volume.volume_key')
            ->merge($beats->pluck('arc.volume.volume_key'))
            ->merge($milestones->pluck('beat.arc.volume.volume_key'));
        $arcKeys = $arcs->pluck('arc_key')
            ->merge($beats->pluck('arc.arc_key'))
            ->merge($milestones->pluck('beat.arc.arc_key'));
        $beatKeys = $beats->pluck('beat_key')->merge($milestones->pluck('beat.beat_key'));

        $completedVolumes = $novel->volumes()->where('status', VolumeStatus::Completed->value)
            ->with('sourceOutlineVolume')->get()->pluck('sourceOutlineVolume.volume_key');
        $completedArcs = $novel->storyArcs()->where('status', StoryArcStatus::Completed->value)
            ->with('sourceOutlineArc.volume')->get();

        return [
            'volume' => array_fill_keys($volumeKeys->merge($completedVolumes)->filter()->unique()->all(), true),
            'arc' => array_fill_keys($arcKeys->merge($completedArcs->pluck('sourceOutlineArc.arc_key'))->filter()->unique()->all(), true),
            'beat' => array_fill_keys($beatKeys->filter()->unique()->all(), true),
            'milestone' => array_fill_keys($milestones->pluck('milestone_key')->filter()->unique()->all(), true),
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @param array<string, array<string, true>> $protection 拒绝修改、移动或删除已引用节点。 */
    private function assertProtectedNodesUnchanged(array $before, array $after, array $protection): void
    {
        $old = $this->nodes($before);
        $new = $this->nodes($after);
        foreach (['volume' => 'Volume', 'arc' => 'Story Arc', 'beat' => 'Beat', 'milestone' => 'Milestone'] as $type => $label) {
            foreach (array_keys($protection[$type]) as $key) {
                if (! isset($old[$type][$key], $new[$type][$key])
                    || ! hash_equals($this->checksum->for($old[$type][$key]), $this->checksum->for($new[$type][$key]))) {
                    throw ValidationException::withMessages([
                        'outline_revision' => "{$label}「{$key}」已被 Plan、Canonical Event 或完成状态引用，不能修改、移动或删除。",
                    ]);
                }
            }
        }
    }

    /** @param array<string, mixed> $data @return array<string, array<string, array<string, mixed>>> 按稳定 Key 展平各层节点供版本差异校验。 */
    private function nodes(array $data): array
    {
        $nodes = ['volume' => [], 'arc' => [], 'beat' => [], 'milestone' => []];
        foreach ($data['volumes'] as $volume) {
            $volumeKey = $volume['key'];
            $nodes['volume'][$volumeKey] = collect($volume)->except('arcs')->all();
            foreach ($volume['arcs'] as $arc) {
                $arcKey = $arc['key'];
                $nodes['arc'][$arcKey] = ['parent_key' => $volumeKey, ...collect($arc)->except('beats')->all()];
                foreach ($arc['beats'] as $beat) {
                    $beatKey = $beat['key'];
                    $nodes['beat'][$beatKey] = ['parent_key' => $arcKey, ...collect($beat)->except('milestones')->all()];
                    foreach ($beat['milestones'] as $milestone) {
                        $nodes['milestone'][$milestone['key']] = ['parent_key' => $beatKey, ...$milestone];
                    }
                }
            }
        }

        return $nodes;
    }

    /** @param array<string, array<string, true>> $protection 将运行态 Volume 与 Arc 重新绑定到新版本定义。 */
    private function synchronizeRuntimeProjections(Novel $novel, NovelOutline $revision, array $protection): void
    {
        $revision->load('volumes.arcs');
        $newVolumes = $revision->volumes->keyBy('volume_key');
        $newArcs = $revision->arcs->keyBy('arc_key');
        $volumes = $novel->volumes()->lockForUpdate()->with('sourceOutlineVolume')->get()
            ->keyBy(fn ($volume): ?string => $volume->sourceOutlineVolume?->volume_key);
        $arcs = $novel->storyArcs()->lockForUpdate()->with(['sourceOutlineArc', 'foreshadowings'])->get()
            ->keyBy(fn ($arc): ?string => $arc->sourceOutlineArc?->arc_key);

        foreach ($arcs as $key => $arc) {
            if ($key !== null && ! $newArcs->has($key)) {
                if (isset($protection['arc'][$key]) || $arc->foreshadowings->isNotEmpty()) {
                    throw ValidationException::withMessages(['outline_revision' => "Story Arc「{$key}」已有引用，不能删除。"]);
                }
                $arc->delete();
            }
        }
        foreach ($volumes as $key => $volume) {
            if ($key !== null && ! $newVolumes->has($key)) {
                if (isset($protection['volume'][$key]) || $volume->chapters()->exists() || $volume->storyArcs()->exists()) {
                    throw ValidationException::withMessages(['outline_revision' => "Volume「{$key}」已有引用，不能删除。"]);
                }
                $volume->delete();
            }
        }

        // 先移开旧顺序，避免交换顺序时触发唯一约束。
        $novel->volumes()->update(['sequence' => DB::raw('sequence + 100000')]);
        $novel->storyArcs()->update(['sequence' => DB::raw('sequence + 100000')]);
        $resolvedVolumes = [];
        foreach ($newVolumes as $key => $definition) {
            $runtime = $volumes->get($key);
            $values = [
                'source_outline_volume_id' => $definition->getKey(),
                'sequence' => $definition->sequence,
                'title' => $definition->title,
                'goal' => $definition->goal,
                'climax' => $definition->climax,
                'target_words' => $definition->target_words,
            ];
            if ($runtime === null) {
                $runtime = $novel->volumes()->create([...$values, 'status' => VolumeStatus::Planned]);
            } else {
                $runtime->update($values);
            }
            $resolvedVolumes[$key] = $runtime;
        }

        foreach ($newArcs as $key => $definition) {
            $volumeKey = $definition->volume->volume_key;
            $runtime = $arcs->get($key);
            $values = [
                'volume_id' => $resolvedVolumes[$volumeKey]->getKey(),
                'source_outline_arc_id' => $definition->getKey(),
                'sequence' => $definition->sequence,
                'type' => $definition->type,
                'title' => $definition->title,
                'goal' => $definition->goal,
                'stakes' => $definition->stakes,
                'completion_conditions' => $definition->completion_conditions,
            ];
            if ($runtime === null) {
                $novel->storyArcs()->create([...$values, 'progress' => 0, 'status' => StoryArcStatus::Planned]);
            } else {
                $runtime->update($values);
            }
        }
    }
}
