<?php

namespace App\Data;

final readonly class TokenAllocation
{
    /** @param array<string, int> $sections
     * @param  array<int, string>  $truncatedSections
     */
    public function __construct(
        public int $budget,
        public int $used,
        public int $remaining,
        public array $sections,
        public array $truncatedSections = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'budget' => $this->budget,
            'used' => $this->used,
            'remaining' => $this->remaining,
            'sections' => $this->sections,
        ];
    }
}
