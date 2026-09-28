<?php

namespace App\Data;

final readonly class RewriteScopeDecision
{
    /** @param array<int, int> $findingIndexes */
    public function __construct(
        public string $scope,
        public ?int $sceneId,
        public string $reason,
        public array $findingIndexes = [],
    ) {}

    public function isResolved(): bool
    {
        return $this->scope === 'scene' && $this->sceneId !== null;
    }

    /** @return array{scope: string, scene_id: int|null, reason: string, finding_indexes: array<int, int>} */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'scene_id' => $this->sceneId,
            'reason' => $this->reason,
            'finding_indexes' => $this->findingIndexes,
        ];
    }
}
