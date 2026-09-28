<?php

namespace App\Services;

use App\Data\CurrentOutlineTarget;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Enums\StoryEventStatus;
use App\Enums\VolumeStatus;
use App\Models\Novel;
use App\Models\NovelOutlineBeat;
use App\Models\StoryEvent;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 从关系表和 Active Canonical Event 解析最早未完成的主线目标。
 */
class OutlineProgressResolver
{
    public function __construct(
        private readonly NovelOutlineChecksum $checksum,
        private readonly OutlineHandoffContract $handoffContract,
    ) {}

    /**
     * 校验运行态来源父链后，返回当前 Arc、Beat、Milestone 和 Handoff。
     */
    public function resolve(Novel $novel): ?CurrentOutlineTarget
    {
        $novel->loadMissing('currentOutline');
        $outline = $novel->currentOutline;
        if ($outline === null) {
            return null;
        }
        if (! hash_equals($outline->checksum, $this->checksum->for($outline))) {
            throw ValidationException::withMessages(['outline' => 'Current Outline 的关系数据与冻结 Checksum 不一致。']);
        }

        $volume = $novel->volumes()
            ->where('status', VolumeStatus::Active->value)
            ->with('sourceOutlineVolume')
            ->first();
        if ($volume?->sourceOutlineVolume === null
            || $volume->sourceOutlineVolume->novel_outline_id !== $outline->getKey()) {
            throw ValidationException::withMessages(['outline' => 'Active Volume 缺少 Current Outline 的权威来源外键。']);
        }

        $runtimeArc = $novel->storyArcs()
            ->where('volume_id', $volume->getKey())
            ->where('type', StoryArcType::Main->value)
            ->where('status', StoryArcStatus::Active->value)
            ->with('sourceOutlineArc')
            ->first();
        if ($runtimeArc?->sourceOutlineArc === null
            || $runtimeArc->sourceOutlineArc->novel_outline_id !== $outline->getKey()
            || $runtimeArc->sourceOutlineArc->novel_outline_volume_id !== $volume->source_outline_volume_id) {
            throw ValidationException::withMessages(['outline' => 'Active Main Arc 缺少与 Current Outline Volume 一致的权威来源外键。']);
        }

        $completedBeatEvents = $novel->storyEvents()
            ->where('event_type', EventType::StoryArcBeatCompleted->value)
            ->where('status', StoryEventStatus::Active->value)
            ->where('subject_type', 'story_arc')
            ->where('subject_id', (string) $runtimeArc->getKey())
            ->where('novel_outline_id', $outline->getKey())
            ->where('novel_outline_arc_id', $runtimeArc->source_outline_arc_id)
            ->whereNotNull('novel_outline_beat_id')
            ->get(['id', 'chapter_id', 'novel_outline_beat_id', 'payload', 'evidence'])
            ->keyBy(fn (StoryEvent $event): int => (int) $event->novel_outline_beat_id);
        $completedBeatIds = $completedBeatEvents->keys()
            ->map(fn ($id): int => (int) $id)
            ->all();
        $completedMilestoneIds = $novel->storyEvents()
            ->where('event_type', EventType::StoryArcBeatMilestoneCompleted->value)
            ->where('status', StoryEventStatus::Active->value)
            ->where('subject_type', 'story_arc')
            ->where('subject_id', (string) $runtimeArc->getKey())
            ->where('novel_outline_id', $outline->getKey())
            ->where('novel_outline_arc_id', $runtimeArc->source_outline_arc_id)
            ->whereNotNull('novel_outline_milestone_id')
            ->pluck('novel_outline_milestone_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $beats = NovelOutlineBeat::query()
            ->where('novel_outline_id', $outline->getKey())
            ->where('novel_outline_arc_id', $runtimeArc->source_outline_arc_id)
            ->whereNotNull('mainline_sequence')
            ->with(['milestones', 'handoffNextBeat'])
            ->orderBy('mainline_sequence')
            ->get();

        // Beat Completion 只能在其全部 Milestone 已正式完成后生效。解析器必须拒绝
        // 不完整的 Canonical Event 集合，不能因为一个提前写入的 Beat Event 跳过中间目标。
        foreach ($beats->whereIn('id', $completedBeatIds) as $completedBeat) {
            $incompleteMilestone = $completedBeat->milestones->first(
                fn ($candidate): bool => ! in_array($candidate->getKey(), $completedMilestoneIds, true),
            );
            if ($incompleteMilestone !== null) {
                throw ValidationException::withMessages([
                    'outline' => "Main Beat {$completedBeat->beat_key} 已有 Beat Completion Event，但 Milestone {$incompleteMilestone->milestone_key} 尚未正式完成。",
                ]);
            }
        }

        $beat = $beats->first(
            fn ($candidate): bool => ! in_array($candidate->getKey(), $completedBeatIds, true),
        );
        if ($beat === null) {
            return null;
        }

        $milestone = $beat->milestones->first(
            fn ($candidate): bool => ! in_array($candidate->getKey(), $completedMilestoneIds, true),
        );
        if ($milestone === null) {
            throw ValidationException::withMessages([
                'outline' => "Main Beat {$beat->beat_key} 的 Milestone 已全部完成，但缺少 Beat Completion Event；请先修复正式进度。",
            ]);
        }

        $completedBeats = NovelOutlineBeat::query()->whereIn('id', $completedBeatIds)
            ->orderBy('mainline_sequence')->get(['id', 'beat_key']);
        $completedMilestones = $outline->milestones()->whereIn('id', $completedMilestoneIds)
            ->orderBy('novel_outline_beat_id')->orderBy('sequence')->get(['id', 'milestone_key']);
        $completedBeatIds = $completedBeats->modelKeys();
        $completedMilestoneIds = $completedMilestones->modelKeys();
        $completedBeatKeys = $completedBeats->pluck('beat_key')->all();
        $completedMilestoneKeys = $completedMilestones->pluck('milestone_key')->all();
        $sourceVolume = $volume->sourceOutlineVolume;
        $sourceArc = $runtimeArc->sourceOutlineArc;

        return new CurrentOutlineTarget(
            outlineId: $outline->getKey(),
            outlineVersion: $outline->version,
            outlineChecksum: $outline->checksum,
            volumeId: $volume->getKey(),
            arcId: $runtimeArc->getKey(),
            outlineArcId: $sourceArc->getKey(),
            outlineBeatId: $beat->getKey(),
            outlineMilestoneId: $milestone->getKey(),
            volume: [
                'key' => $sourceVolume->volume_key,
                'sequence' => $sourceVolume->sequence,
                'title' => $sourceVolume->title,
                'goal' => $sourceVolume->goal,
                'climax' => $sourceVolume->climax,
                'target_words' => $sourceVolume->target_words,
            ],
            arc: [
                'key' => $sourceArc->arc_key,
                'sequence' => $sourceArc->sequence,
                'mainline_sequence' => $sourceArc->mainline_sequence,
                'type' => $sourceArc->type->value,
                'title' => $sourceArc->title,
                'goal' => $sourceArc->goal,
                'stakes' => $sourceArc->stakes,
                'completion_conditions' => $sourceArc->completion_conditions,
            ],
            beat: [
                'key' => $beat->beat_key,
                'sequence' => $beat->sequence,
                'mainline_sequence' => $beat->mainline_sequence,
                'title' => $beat->title,
                'summary' => $beat->summary,
                'chapter_budget' => ['min' => $beat->chapter_budget_min, 'max' => $beat->chapter_budget_max],
                'acceptance_criteria' => $beat->acceptance_criteria,
                'must_include' => $beat->must_include,
                'must_not_include' => $beat->must_not_include,
                'character_candidates' => $beat->character_candidates,
                'world_entity_candidates' => $beat->world_entity_candidates,
                'handoff' => $this->handoffContract->forBeat($beat),
            ],
            milestone: [
                'key' => $milestone->milestone_key,
                'sequence' => $milestone->sequence,
                'title' => $milestone->title,
                'objective' => $milestone->objective,
                'acceptance_criteria' => $milestone->acceptance_criteria,
                'must_include' => $milestone->must_include,
                'must_not_include' => $milestone->must_not_include,
            ],
            canonicalCompletedBeatKeys: $completedBeatKeys,
            canonicalCompletedMilestoneKeys: $completedMilestoneKeys,
            canonicalCompletedBeatIds: $completedBeatIds,
            canonicalCompletedMilestoneIds: $completedMilestoneIds,
            chaptersUsedForCurrentBeat: $this->chaptersUsed($novel, $outline->getKey(), $beat->getKey()),
            inboundHandoff: $this->inboundHandoff($novel, $beats, $beat, $completedBeatEvents),
        );
    }

    /**
     * 下一 Beat 只能消费上一 Beat Completion Event 中已冻结的 Handoff，不能重新解释可变关系行。
     *
     * @param  Collection<int, NovelOutlineBeat>  $beats
     * @param  Collection<int, StoryEvent>  $completedBeatEvents
     * @return array<string, mixed>|null
     */
    private function inboundHandoff(Novel $novel, $beats, NovelOutlineBeat $beat, $completedBeatEvents): ?array
    {
        $previous = $beats->first(
            fn (NovelOutlineBeat $candidate): bool => $candidate->mainline_sequence === $beat->mainline_sequence - 1,
        );
        if ($previous === null) {
            return null;
        }

        $event = $completedBeatEvents->get($previous->getKey());
        $expected = $this->handoffContract->forBeat($previous);
        $stored = data_get($event?->payload, 'handoff_contract');
        $readiness = data_get($event?->payload, 'handoff_readiness.status');

        if ($event === null
            || ! is_array($stored)
            || $stored !== $expected
            || $readiness !== 'ready'
            || $expected['next_beat_id'] !== $beat->getKey()
            || ! is_array($event->evidence)
            || $event->evidence === []) {
            throw ValidationException::withMessages([
                'handoff' => "Main Beat {$beat->beat_key} 缺少上一 Beat {$previous->beat_key} 已正式提交且与当前 Outline 一致的 Handoff 契约或证据。",
            ]);
        }

        return [
            'source_beat_id' => $previous->getKey(),
            'source_beat_key' => $previous->beat_key,
            'completion_event_id' => $event->getKey(),
            'completion_chapter_id' => $event->chapter_id,
            'contract' => $stored,
            'contract_checksum' => $this->handoffContract->checksum($stored),
            'evidence' => array_values($event->evidence),
            'entry_pending' => (int) $novel->chapters()
                ->where('status', ChapterStatus::Canonical->value)
                ->reorder('sequence', 'desc')
                ->value('id') === (int) $event->chapter_id,
        ];
    }

    /**
     * 统计当前版本指定 Beat 已消耗的 Canonical 章节数。
     */
    private function chaptersUsed(Novel $novel, int $outlineId, int $beatId): int
    {
        return $novel->chapters()
            ->where('status', ChapterStatus::Canonical->value)
            ->whereHas('plans', fn ($query) => $query
                ->where('novel_outline_id', $outlineId)
                ->where('primary_outline_beat_id', $beatId))
            ->count();
    }
}
