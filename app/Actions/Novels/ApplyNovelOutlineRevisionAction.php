<?php

namespace App\Actions\Novels;

use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\StoryArcStatus;
use App\Enums\StoryEventStatus;
use App\Enums\VolumeStatus;
use App\Models\ChapterPlan;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\User;
use App\Services\NovelOutlineChecksum;
use App\Services\NovelOutlineValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyNovelOutlineRevisionAction
{
    public function __construct(
        private readonly CreateNovelOutlineVersionAction $createVersion,
        private readonly NovelOutlineValidator $validator,
        private readonly NovelOutlineChecksum $checksum,
    ) {}

    /** @param array<string, mixed> $content */
    public function handle(
        Novel $novel,
        array $content,
        int $expectedCurrentOutlineId,
        string $expectedCurrentChecksum,
        ?User $creator = null,
    ): NovelOutline {
        $this->validator->assertValid($content);
        $targetChecksum = $this->checksum->for($content);

        return DB::transaction(function () use ($novel, $content, $expectedCurrentOutlineId, $expectedCurrentChecksum, $creator, $targetChecksum): NovelOutline {
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

            if (! hash_equals($current->checksum, $this->checksum->for($current->content))) {
                throw ValidationException::withMessages(['outline_checksum' => 'Current Outline checksum 与冻结内容不一致。']);
            }

            if ($lockedNovel->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
                throw ValidationException::withMessages(['generation_runs' => '小说仍有排队中或运行中的 Generation Run；请等待结束或先安全停止后再修订大纲。']);
            }

            $protection = $this->protection($lockedNovel, $current);
            $this->assertProtectedNodesUnchanged($current->content, $content, $protection);
            $this->synchronizeProjections($lockedNovel, $content, $protection);

            $revision = $this->createVersion->handle(
                novel: $lockedNovel,
                content: $content,
                source: NovelOutlineSource::Revision,
                basedOn: $current,
                creator: $creator,
            );

            $current->update(['status' => NovelOutlineStatus::Superseded]);
            $revision->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
            $lockedNovel->update(['current_outline_id' => $revision->getKey()]);

            return $revision->refresh();
        }, 3);
    }

    /** @return array{beat_keys: array<string, true>, arc_keys: array<string, true>, volume_keys: array<string, true>} */
    private function protection(Novel $novel, NovelOutline $outline): array
    {
        $plans = ChapterPlan::query()
            ->whereIn('status', [PlanStatus::Draft->value, PlanStatus::Ready->value])
            ->whereHas('chapter', fn ($query) => $query
                ->where('novel_id', $novel->getKey())
                ->whereIn('status', [
                    ChapterStatus::Canonical->value,
                    ChapterStatus::Planned->value,
                    ChapterStatus::Generating->value,
                    ChapterStatus::Review->value,
                    ChapterStatus::Rewrite->value,
                    ChapterStatus::Blocked->value,
                ]))
            ->get();
        $arcIds = $plans->flatMap(fn (ChapterPlan $plan): array => collect($plan->arc_contributions ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && (int) ($item['arc_id'] ?? 0) > 0)
            ->pluck('arc_id')->map(fn ($id): int => (int) $id)->all());
        $beatKeys = $plans->flatMap(fn (ChapterPlan $plan): array => collect($plan->arc_contributions ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['beat_key'] ?? null))
            ->pluck('beat_key')->all());

        $arcEvents = $novel->storyEvents()
            ->where('status', StoryEventStatus::Active)
            ->where('subject_type', 'story_arc')
            ->get();
        $arcIds = $arcIds->merge($arcEvents->pluck('subject_id')->map(fn ($id): int => (int) $id))->filter()->unique();
        $beatKeys = $beatKeys->merge(
            $arcEvents->where('event_type', EventType::StoryArcBeatCompleted)
                ->map(fn ($event): mixed => data_get($event->payload, 'beat_key')),
        )->merge(
            collect($outline->content['baseline_completions'] ?? [])->pluck('beat_key'),
        )->filter(fn (mixed $key): bool => is_string($key) && $key !== '')->unique();
        $protectedBeatKeys = array_fill_keys($beatKeys->all(), true);

        $arcs = $novel->storyArcs()->whereIn('id', $arcIds)->get();
        $arcKeys = $arcs->pluck('outline_key')->filter()->unique();
        $volumeKeys = $novel->volumes()->whereIn('id', $arcs->pluck('volume_id')->filter())
            ->pluck('outline_key')->filter()->unique();
        foreach ($outline->content['volumes'] ?? [] as $volume) {
            foreach ($volume['arcs'] ?? [] as $arc) {
                $containsProtectedBeat = collect($arc['beats'] ?? [])->contains(
                    fn (mixed $beat): bool => is_array($beat) && isset($protectedBeatKeys[(string) ($beat['key'] ?? '')]),
                );
                if ($containsProtectedBeat) {
                    $arcKeys->push($arc['key']);
                    $volumeKeys->push($volume['key']);
                }
            }
        }
        $volumeKeys = $volumeKeys->merge(
            $novel->volumes()->whereIn('status', [VolumeStatus::Completed->value])
                ->pluck('outline_key')->filter(),
        )->unique();
        $arcKeys = $arcKeys->merge(
            $novel->storyArcs()->where('status', StoryArcStatus::Completed->value)
                ->pluck('outline_key')->filter(),
        )->unique();

        return [
            'beat_keys' => $protectedBeatKeys,
            'arc_keys' => array_fill_keys($arcKeys->all(), true),
            'volume_keys' => array_fill_keys($volumeKeys->all(), true),
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @param array<string, array<mixed, true>> $protection */
    private function assertProtectedNodesUnchanged(array $before, array $after, array $protection): void
    {
        if (! hash_equals(
            $this->checksum->for(['items' => $before['baseline_completions'] ?? []]),
            $this->checksum->for(['items' => $after['baseline_completions'] ?? []]),
        )) {
            throw ValidationException::withMessages([
                'baseline_completions' => '连载中的 Outline Revision 不能修改已经确认的历史 Baseline Completion。',
            ]);
        }

        $old = $this->nodes($before);
        $new = $this->nodes($after);

        foreach ([
            'beat_keys' => 'Beat',
            'arc_keys' => 'Story Arc',
            'volume_keys' => 'Volume',
        ] as $group => $label) {
            foreach (array_keys($protection[$group]) as $key) {
                if (! isset($old[$key], $new[$key])
                    || ! hash_equals($this->checksum->for($old[$key]), $this->checksum->for($new[$key]))) {
                    throw ValidationException::withMessages([
                        'outline_revision' => "{$label}「{$key}」已被正式内容、完成记录或当前活动 Chapter 引用，不能修改 key、顺序、归属或语义。",
                    ]);
                }
            }
        }
    }

    /** @param array<string, mixed> $content @return array<string, array<string, mixed>> */
    private function nodes(array $content): array
    {
        $nodes = [];
        foreach ($content['volumes'] ?? [] as $volume) {
            $volumeKey = (string) $volume['key'];
            $nodes[$volumeKey] = collect($volume)->except('arcs')->all();
            foreach ($volume['arcs'] ?? [] as $arc) {
                $arcKey = (string) $arc['key'];
                $nodes[$arcKey] = ['parent_key' => $volumeKey, ...collect($arc)->except('beats')->all()];
                foreach ($arc['beats'] ?? [] as $beat) {
                    $nodes[(string) $beat['key']] = ['parent_key' => $arcKey, ...$beat];
                }
            }
        }

        return $nodes;
    }

    /** @param array<string, mixed> $content @param array<string, array<mixed, true>> $protection */
    private function synchronizeProjections(Novel $novel, array $content, array $protection): void
    {
        $volumeData = collect($content['volumes'])->keyBy('key');
        $arcData = $volumeData->flatMap(fn (array $volume): Collection => collect($volume['arcs'])->mapWithKeys(
            fn (array $arc): array => [$arc['key'] => ['volume_key' => $volume['key'], ...$arc]],
        ));
        $volumes = $novel->volumes()->lockForUpdate()->get()->keyBy('outline_key');
        $arcs = $novel->storyArcs()->lockForUpdate()->get()->keyBy('outline_key');
        $referencedPlanArcIds = $this->referencedPlanArcIds($novel);

        foreach ($arcs as $key => $arc) {
            if ($key === null || $arcData->has($key)) {
                continue;
            }
            if (isset($protection['arc_keys'][$key]) || isset($referencedPlanArcIds[$arc->getKey()]) || $arc->foreshadowings()->exists()) {
                throw ValidationException::withMessages(['outline_revision' => "Story Arc「{$key}」已有正式或规划引用，不能从修订中删除。"]);
            }
            $arc->delete();
        }

        foreach ($volumes as $key => $volume) {
            if ($key === null || $volumeData->has($key)) {
                continue;
            }
            if (isset($protection['volume_keys'][$key]) || $volume->chapters()->exists() || $volume->storyArcs()->exists()) {
                throw ValidationException::withMessages(['outline_revision' => "Volume「{$key}」已有正式或规划引用，不能从修订中删除。"]);
            }
            $volume->delete();
        }

        $novel->volumes()->whereNotNull('outline_key')->update(['sequence' => DB::raw('sequence + 100000')]);
        $novel->storyArcs()->whereNotNull('outline_key')->update(['sequence' => DB::raw('sequence + 100000')]);

        $resolvedVolumes = [];
        foreach ($volumeData->sortBy('sequence') as $key => $data) {
            $volume = $volumes->get($key);
            $values = collect($data)->except(['key', 'arcs'])->all();
            if ($volume === null) {
                $volume = $novel->volumes()->create([
                    'outline_key' => $key,
                    ...$values,
                    'status' => VolumeStatus::Planned,
                ]);
            } elseif (! isset($protection['volume_keys'][$key])) {
                $volume->update($values);
            } else {
                $volume->update(['sequence' => $data['sequence']]);
            }
            $resolvedVolumes[$key] = $volume;
        }

        foreach ($arcData->sortBy(fn (array $data): array => [$data['volume_key'], $data['sequence']]) as $key => $data) {
            $volume = $resolvedVolumes[$data['volume_key']];
            $arc = $arcs->get($key);
            $values = collect($data)->except(['key', 'volume_key'])->all();
            if ($arc === null) {
                $novel->storyArcs()->create([
                    'volume_id' => $volume->getKey(),
                    'outline_key' => $key,
                    ...$values,
                    'progress' => 0,
                    'status' => StoryArcStatus::Planned,
                ]);
            } elseif (! isset($protection['arc_keys'][$key])) {
                $arc->update(['volume_id' => $volume->getKey(), ...$values]);
            } else {
                $arc->update([
                    'volume_id' => $volume->getKey(),
                    'sequence' => $data['sequence'],
                    'beats' => $data['beats'],
                ]);
            }
        }
    }

    /** @return array<int, true> */
    private function referencedPlanArcIds(Novel $novel): array
    {
        $ids = $novel->chapters()->with('plans')->get()->flatMap(
            fn ($chapter): array => $chapter->plans->flatMap(
                fn (ChapterPlan $plan): array => collect($plan->arc_contributions ?? [])->pluck('arc_id')->map(fn ($id): int => (int) $id)->all(),
            )->all(),
        )->filter()->unique()->all();

        return array_fill_keys($ids, true);
    }
}
