<?php

namespace App\Jobs\Concerns;

use App\Services\GenerationJobDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

trait PreventsDuplicateGeneration
{
    public function uniqueFor(): int
    {
        return (int) config('generation.pending_job_seconds', 900);
    }

    protected function releaseGenerationDispatch(): void
    {
        app(GenerationJobDispatcher::class)->release($this);
    }

    protected function dispatchGenerationJob(ShouldQueue&ShouldBeUnique $job): bool
    {
        return app(GenerationJobDispatcher::class)->dispatch($job);
    }
}
