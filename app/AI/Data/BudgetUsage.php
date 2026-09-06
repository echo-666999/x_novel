<?php

namespace App\AI\Data;

final readonly class BudgetUsage
{
    public function __construct(
        public string $scope,
        public float $used,
        public ?float $limit,
    ) {}

    public function reached(): bool
    {
        return $this->limit !== null && $this->used >= $this->limit;
    }
}
