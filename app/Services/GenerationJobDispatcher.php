<?php

namespace App\Services;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class GenerationJobDispatcher
{
    public function dispatch(ShouldQueue&ShouldBeUnique $job): bool
    {
        if (! $this->reserve($job)) {
            return false;
        }

        try {
            $pendingDispatch = dispatch($job);
            unset($pendingDispatch);
        } catch (Throwable $exception) {
            $this->release($job);

            throw $exception;
        }

        return true;
    }

    public function reserve(ShouldQueue&ShouldBeUnique $job): bool
    {
        return Cache::add(
            $this->cacheKey($job),
            now()->toISOString(),
            (int) config('generation.pending_job_seconds', 900),
        );
    }

    public function isPending(ShouldQueue&ShouldBeUnique $job): bool
    {
        return Cache::has($this->cacheKey($job));
    }

    public function release(ShouldQueue&ShouldBeUnique $job): void
    {
        Cache::forget($this->cacheKey($job));
    }

    private function cacheKey(ShouldQueue&ShouldBeUnique $job): string
    {
        if (! method_exists($job, 'uniqueId')) {
            throw new InvalidArgumentException($job::class.' 必须定义 uniqueId()。');
        }

        return 'generation-job:pending:'.hash('sha256', $job::class.'|'.$job->uniqueId());
    }
}
