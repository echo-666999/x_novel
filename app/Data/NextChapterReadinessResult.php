<?php

namespace App\Data;

final readonly class NextChapterReadinessResult
{
    /** @param array<int, array{code: string, message: string, repair_action: string, related_type?: string, related_id?: int}> $blockers */
    public function __construct(public array $blockers, public ?array $outlineTarget = null) {}

    public function isReady(): bool
    {
        return $this->blockers === [];
    }

    public function firstCode(): ?string
    {
        return $this->blockers[0]['code'] ?? null;
    }
}
