<?php

namespace App\Data;

final readonly class StoryStateRebuildResult
{
    /**
     * @param  array<string, mixed>  $rebuiltState
     * @param  array<int, array{path: string, before: mixed, after: mixed, before_missing: bool, after_missing: bool, type: string}>  $changes
     * @param  array<int, array{id: int, state_version: int, reason: string}>  $skippedEvents
     * @param  array<int, array{version: int, reasons: array<int, string>}>  $skippedBaselines
     */
    public function __construct(
        public int $currentVersion,
        public string $currentChecksum,
        public string $rebuiltChecksum,
        public int $baselineVersion,
        public string $baselineChecksum,
        public int $replayedEventCount,
        public ?int $firstReplayedEventId,
        public ?int $lastReplayedEventId,
        public ?int $firstReplayedStateVersion,
        public ?int $lastReplayedStateVersion,
        public array $skippedEvents,
        public array $skippedBaselines,
        public array $rebuiltState,
        public array $changes,
    ) {}

    public function matches(): bool
    {
        return hash_equals($this->currentChecksum, $this->rebuiltChecksum);
    }
}
