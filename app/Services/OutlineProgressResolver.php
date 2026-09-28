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
use Illuminate\Validation\ValidationException;

/**
 * 从关系表和 Active Canonical Event 解析最早未完成的主线目标。
 */
class OutlineProgressResolver
{
    public function __construct(private readonly NovelOutlineChecksum $checksum) {}

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

        $completedBeatIds = $novel->storyEvents()
            ->where('event_type', EventType::StoryArcBeatCompleted->value)
            ->where('status', StoryEventStatus::Active->value)
            ->where('novel_outline_id', $outline->getKey())
            ->whereNotNull('novel_outline_beat_id')
            ->pluck('novel_outline_beat_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $completedMilestoneIds = $novel->storyEvents()
            ->where('event_type', EventType::StoryArcBeatMilestoneCompleted->value)
            ->where('status', StoryEventStatus::Active->value)
            ->where('novel_outline_id', $outline->getKey())
            ->whereNotNull('novel_outline_milestone_id')
            ->pluck('novel_outline_milestone_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $beat = NovelOutlineBeat::query()
            ->where('novel_outline_id', $outline->getKey())
            ->where('novel_outline_arc_id', $runtimeArc->source_outline_arc_id)
            ->whereNotNull('mainline_sequence')
            ->when($completedBeatIds !== [], fn ($query) => $query->whereNotIn('id', $completedBeatIds))
            ->with(['milestones', 'handoffNextBeat'])
            ->orderBy('mainline_sequence')
            ->first();
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

        $completedBeatKeys = NovelOutlineBeat::query()->whereIn('id', $completedBeatIds)
            ->orderBy('mainline_sequence')->pluck('beat_key')->all();
        $completedMilestoneKeys = $outline->milestones()->whereIn('id', $completedMilestoneIds)
            ->orderBy('id')->pluck('milestone_key')->all();
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
                'handoff' => [
                    'next_beat_key' => $beat->handoffNextBeat?->beat_key,
                    'transition_mode' => $beat->handoff_transition_mode,
                    'exit_result' => $beat->handoff_exit_result,
                    'next_trigger' => $beat->handoff_next_trigger,
                    'carried_states' => $beat->handoff_carried_states,
                    'open_threads' => $beat->handoff_open_threads,
                    'required_transition' => $beat->handoff_required_transition,
                    'forbidden_jump' => $beat->handoff_forbidden_jump,
                ],
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
            chaptersUsedForCurrentBeat: $this->chaptersUsed($novel, $outline->getKey(), $beat->getKey()),
        );
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
