<?php

namespace App\Data;

final readonly class MvpReadinessSummary
{
    public function __construct(
        public int $novelId,
        public string $novelTitle,
        public int $canonicalChapters,
        public string $status,
        public int $duplicateCanonicalCommits,
        public int $missingCanonicalChapters,
        public int $stateIntegrityIssues,
        public int $untraceableUsageRecords,
        public float $trackedCost,
        public bool $pauseCommitGuard,
        public bool $resumeSequenceGuard,
        public bool $lockedFactGuard,
        public bool $criticalForeshadowingGuard,
    ) {}

    public function percent(): int
    {
        return min(100, $this->canonicalChapters);
    }

    public function isReady(): bool
    {
        return $this->canonicalChapters >= 100
            && $this->duplicateCanonicalCommits === 0
            && $this->missingCanonicalChapters === 0
            && $this->stateIntegrityIssues === 0
            && $this->untraceableUsageRecords === 0
            && $this->pauseCommitGuard
            && $this->resumeSequenceGuard
            && $this->lockedFactGuard
            && $this->criticalForeshadowingGuard;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'running' => '运行中',
            'paused' => '已暂停',
            'completed' => '已完成',
            'stopped' => '已停止',
            default => '未知',
        };
    }
}
