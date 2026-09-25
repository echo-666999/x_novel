<?php

namespace App\Jobs\Concerns;

use App\Services\GenerationFailurePolicy;
use App\Services\GenerationJobDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

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

    protected function handleUnexpectedGenerationFailure(Throwable $exception): void
    {
        $failure = app(GenerationFailurePolicy::class)->fromException($exception, 'generation_code_failure');

        if ($failure->retryable) {
            throw $exception;
        }

        $this->releaseGenerationDispatch();
        $this->fail($exception);
    }
}
