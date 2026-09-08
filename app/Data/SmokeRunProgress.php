<?php

namespace App\Data;

final readonly class SmokeRunProgress
{
    /** @param array<int, int> $missingSequences */
    public function __construct(
        public int $novelId,
        public string $novelTitle,
        public int $startSequence,
        public int $targetSequence,
        public int $canonicalChapters,
        public string $status,
        public float $cost,
        public bool $hasDuplicateCommit,
        public array $missingSequences,
        public bool $stateContinuous,
    ) {}

    public function percent(): int
    {
        return min(100, (int) floor(($this->canonicalChapters / 20) * 100));
    }

    public function sequenceHealthy(): bool
    {
        return ! $this->hasDuplicateCommit && $this->missingSequences === [];
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
