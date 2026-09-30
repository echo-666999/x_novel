<?php

namespace App\Data;

final readonly class NovelOutlineStageProgress
{
    /**
     * @param  array<int, int>  $runIds
     * @param  array<int, int>  $artifactIds
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public int $completed,
        public ?int $total,
        public array $runIds = [],
        public array $artifactIds = [],
    ) {}

    public function hasKnownTotal(): bool
    {
        return $this->total !== null;
    }
}
