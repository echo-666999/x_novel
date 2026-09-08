<?php

namespace App\Data;

final readonly class ClosureDebtItem
{
    public function __construct(
        public string $category,
        public string $categoryLabel,
        public string $title,
        public string $reason,
        public bool $critical,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'category_label' => $this->categoryLabel,
            'title' => $this->title,
            'reason' => $this->reason,
            'severity' => $this->critical ? '关键' : '待处理',
            'critical' => $this->critical,
        ];
    }
}
