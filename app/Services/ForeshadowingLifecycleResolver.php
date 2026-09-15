<?php

namespace App\Services;

use App\Enums\ForeshadowingStatus;
use App\Models\Foreshadowing;
use App\Models\Novel;

class ForeshadowingLifecycleResolver
{
    public function status(Foreshadowing $foreshadowing, Novel $novel): ForeshadowingStatus
    {
        $value = data_get($novel->canonicalStateVersion?->state, "foreshadowings.{$foreshadowing->getKey()}.status");

        if (is_string($value) && ($status = ForeshadowingStatus::tryFrom($value)) !== null) {
            return $status;
        }

        return $foreshadowing->status;
    }

    public function source(Foreshadowing $foreshadowing, Novel $novel): string
    {
        $value = data_get($novel->canonicalStateVersion?->state, "foreshadowings.{$foreshadowing->getKey()}.status");

        return is_string($value) && ForeshadowingStatus::tryFrom($value) !== null
            ? 'canonical_state'
            : 'domain_projection';
    }
}
