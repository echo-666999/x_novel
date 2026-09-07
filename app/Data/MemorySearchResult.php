<?php

namespace App\Data;

use App\Models\Memory;

final readonly class MemorySearchResult
{
    public function __construct(
        public Memory $memory,
        public float $similarity,
        public float $salienceScore = 0.0,
        public float $recencyScore = 0.0,
        public float $entityMatchScore = 0.0,
        public float $finalScore = 0.0,
        public bool $selected = false,
        public string $reason = '尚未排序',
        public int $estimatedTokens = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->memory->getKey(),
            'summary' => $this->memory->summary,
            'type' => $this->memory->type->getLabel(),
            'similarity' => $this->similarity,
            'salience' => (float) $this->memory->salience,
            'recency' => $this->recencyScore,
            'entity_match' => $this->entityMatchScore,
            'final_score' => $this->finalScore,
            'decision' => $this->selected ? '已选择' : '已拒绝',
            'selected' => $this->selected,
            'reason' => $this->reason,
            'estimated_tokens' => $this->estimatedTokens,
            'source' => $this->memory->sourceLabel(),
            'source_chapter' => $this->memory->valid_from_chapter,
        ];
    }
}
