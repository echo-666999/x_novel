<?php

namespace App\Data;

final readonly class ProjectionHealth
{
    /**
     * @param  array<int, int>  $characterDriftIds
     * @param  array<int, int>  $worldEntityDriftIds
     * @param  array<int, int>  $foreshadowingDriftIds
     * @param  array<int, string>  $errors
     * @param  array<int, array<string, mixed>>  $characterStates
     * @param  array<int, array<string, mixed>>  $worldEntityStates
     * @param  array<int, string>  $foreshadowingStatuses
     */
    public function __construct(
        public int $stateVersion,
        public int $charactersChecked,
        public int $worldEntitiesChecked,
        public int $foreshadowingsChecked,
        public array $characterDriftIds,
        public array $worldEntityDriftIds,
        public array $foreshadowingDriftIds,
        public array $errors,
        public array $characterStates,
        public array $worldEntityStates,
        public array $foreshadowingStatuses,
    ) {}

    public function driftCount(): int
    {
        return count($this->characterDriftIds)
            + count($this->worldEntityDriftIds)
            + count($this->foreshadowingDriftIds);
    }

    public function checkedCount(): int
    {
        return $this->charactersChecked + $this->worldEntitiesChecked + $this->foreshadowingsChecked;
    }

    public function isHealthy(): bool
    {
        return $this->driftCount() === 0 && $this->errors === [];
    }
}
