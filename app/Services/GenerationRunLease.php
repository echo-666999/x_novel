<?php

namespace App\Services;

use App\Models\GenerationRun;
use Illuminate\Support\Carbon;

class GenerationRunLease
{
    public function isFresh(?GenerationRun $run): bool
    {
        return $run?->updated_at?->gt($this->cutoff()) ?? false;
    }

    public function isExpired(?GenerationRun $run): bool
    {
        return $run?->updated_at?->lte($this->cutoff()) ?? false;
    }

    public function cutoff(): Carbon
    {
        return now()->subSeconds((int) config('generation.stalled_run_after_seconds', 300));
    }
}
