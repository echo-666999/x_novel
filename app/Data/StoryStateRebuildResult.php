<?php

namespace App\Data;

final readonly class StoryStateRebuildResult
{
    /**
     * @param  array<string, mixed>  $rebuiltState
     * @param  array<int, array{path: string, before: mixed, after: mixed, before_missing: bool, after_missing: bool, type: string}>  $changes
     */
    public function __construct(
        public int $currentVersion,
        public string $currentChecksum,
        public string $rebuiltChecksum,
        public int $replayedEventCount,
        public array $rebuiltState,
        public array $changes,
    ) {}

    public function matches(): bool
    {
        return hash_equals($this->currentChecksum, $this->rebuiltChecksum);
    }
}
