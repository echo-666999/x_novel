<?php

namespace App\Contracts;

use App\Data\StoryEventCandidate;

interface StoryEventApplier
{
    public function supports(string $eventType): bool;

    /** @return array<int, array{op: string, path: string, value?: mixed}> */
    public function operations(StoryEventCandidate $event): array;
}
