<?php

namespace App\Data;

/**
 * 冻结章节规划当前应使用的关系化 Outline 完整目标链。
 */
readonly class CurrentOutlineTarget
{
    /**
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $arc
     * @param  array<string, mixed>  $beat
     * @param  array<string, mixed>  $milestone
     * @param  array<int, string>  $canonicalCompletedBeatKeys
     * @param  array<int, string>  $canonicalCompletedMilestoneKeys
     */
    public function __construct(
        public int $outlineId,
        public int $outlineVersion,
        public string $outlineChecksum,
        public int $volumeId,
        public int $arcId,
        public int $outlineArcId,
        public int $outlineBeatId,
        public int $outlineMilestoneId,
        public array $volume,
        public array $arc,
        public array $beat,
        public array $milestone,
        public array $canonicalCompletedBeatKeys,
        public array $canonicalCompletedMilestoneKeys,
        public int $chaptersUsedForCurrentBeat,
    ) {}

    /** @return array<string, mixed> 返回可安全写入 Context Snapshot 的目标快照。 */
    public function toArray(): array
    {
        return [
            'novel_outline_id' => $this->outlineId,
            'outline_version' => $this->outlineVersion,
            'outline_checksum' => $this->outlineChecksum,
            'volume_id' => $this->volumeId,
            'volume' => $this->volume,
            'primary_arc_id' => $this->arcId,
            'primary_outline_arc_id' => $this->outlineArcId,
            'primary_outline_beat_id' => $this->outlineBeatId,
            'primary_outline_milestone_id' => $this->outlineMilestoneId,
            'arc' => $this->arc,
            'beat' => $this->beat,
            'milestone' => $this->milestone,
            'canonical_completed_beat_keys' => $this->canonicalCompletedBeatKeys,
            'canonical_completed_milestone_keys' => $this->canonicalCompletedMilestoneKeys,
            'chapters_used_for_current_beat' => $this->chaptersUsedForCurrentBeat,
        ];
    }
}
