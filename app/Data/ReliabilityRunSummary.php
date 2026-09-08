<?php

namespace App\Data;

final readonly class ReliabilityRunSummary
{
    public function __construct(
        public int $novelId,
        public string $novelTitle,
        public int $startSequence,
        public int $targetSequence,
        public int $canonicalChapters,
        public string $status,
        public int $retryRuns,
        public int $workerCrashes,
        public int $workerRecoveries,
        public int $memories,
        public int $embeddedMemories,
        public int $foreshadowingEvents,
        public int $reviews,
        public int $rewrites,
        public float $cost,
        public ?float $costDriftPercent,
        public int $averageContextTokens,
        public int $maximumContextTokens,
        public bool $sequenceContinuous,
        public bool $stateContinuous,
    ) {}

    public function percent(): int
    {
        return min(100, (int) floor(($this->canonicalChapters / 50) * 100));
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
