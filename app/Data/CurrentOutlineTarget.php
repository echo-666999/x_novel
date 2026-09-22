<?php

namespace App\Data;

readonly class CurrentOutlineTarget
{
    /**
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $arc
     * @param  array<string, mixed>  $beat
     * @param  array<int, string>  $canonicalCompletedBeatKeys
     * @param  array<int, string>  $baselineCompletedBeatKeys
     */
    public function __construct(
        public int $outlineId,
        public int $outlineVersion,
        public string $outlineChecksum,
        public int $volumeId,
        public int $arcId,
        public array $volume,
        public array $arc,
        public array $beat,
        public array $canonicalCompletedBeatKeys,
        public array $baselineCompletedBeatKeys,
        public int $chaptersUsedForCurrentBeat,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'novel_outline_id' => $this->outlineId,
            'outline_version' => $this->outlineVersion,
            'outline_checksum' => $this->outlineChecksum,
            'volume_id' => $this->volumeId,
            'volume' => $this->volume,
            'primary_arc_id' => $this->arcId,
            'arc' => $this->arc,
            'beat' => $this->beat,
            'canonical_completed_beat_keys' => $this->canonicalCompletedBeatKeys,
            'baseline_completed_beat_keys' => $this->baselineCompletedBeatKeys,
            'chapters_used_for_current_beat' => $this->chaptersUsedForCurrentBeat,
        ];
    }
}
