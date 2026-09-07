<?php

namespace App\Data;

use App\Models\Memory;

final readonly class MemorySearchResult
{
    public function __construct(
        public Memory $memory,
        public float $similarity,
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
            'source' => $this->memory->sourceLabel(),
            'source_chapter' => $this->memory->valid_from_chapter,
        ];
    }
}
