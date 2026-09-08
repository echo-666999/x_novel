<?php

namespace App\Data;

final readonly class ClosureDebtResult
{
    /** @param array<int, ClosureDebtItem> $items */
    public function __construct(public array $items) {}

    public function total(): int
    {
        return count($this->items);
    }

    public function critical(): int
    {
        return count(array_filter($this->items, fn (ClosureDebtItem $item): bool => $item->critical));
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn (ClosureDebtItem $item): array => $item->toArray(), $this->items);
    }
}
