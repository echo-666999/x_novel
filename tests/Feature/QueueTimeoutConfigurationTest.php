<?php

use App\Jobs\AssembleChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateEmbeddingJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\GenerationRun;
use App\Services\GenerationRunLease;

function jobTimeout(string $jobClass): int
{
    return (int) (new ReflectionClass($jobClass))->getDefaultProperties()['timeout'];
}

test('provider call budgets fit inside their queue job timeouts', function () {
    $providerTimeout = (int) config('ai.providers.openai.timeout');
    $maximumCallsWithLengthRepair = 1 + (int) config('generation.max_length_repair_attempts');

    foreach ([PlanChapterJob::class, ExtractStoryEventsJob::class, ReviewChapterJob::class, GenerateEmbeddingJob::class] as $jobClass) {
        expect(jobTimeout($jobClass))->toBeGreaterThan($providerTimeout);
    }

    foreach ([GenerateSceneJob::class, AssembleChapterJob::class, RewriteChapterJob::class] as $jobClass) {
        expect(jobTimeout($jobClass))->toBeGreaterThan($providerTimeout * $maximumCallsWithLengthRepair);
    }
});

test('horizon allows long generation jobs to finish before redis retries them', function () {
    $longestJobTimeout = max(
        jobTimeout(GenerateSceneJob::class),
        jobTimeout(AssembleChapterJob::class),
        jobTimeout(RewriteChapterJob::class),
    );
    $workerTimeout = config('horizon.defaults.supervisor-1.timeout');
    $retryAfter = config('queue.connections.redis.retry_after');
    $stalledAfter = config('generation.stalled_run_after_seconds');

    expect($workerTimeout)->toBeGreaterThan($longestJobTimeout)
        ->and($retryAfter)->toBeGreaterThan($workerTimeout)
        ->and($stalledAfter)->toBeGreaterThan($retryAfter);
});

test('local horizon keeps exactly one baseline worker for each configured queue', function () {
    $supervisor = config('horizon.environments.local.supervisor-1');
    $queueCount = count(config('horizon.defaults.supervisor-1.queue'));

    expect(config('horizon.defaults.supervisor-1.minProcesses'))->toBe(1)
        ->and($supervisor['maxProcesses'])->toBe($queueCount);
});

test('generation run freshness uses the shared stalled threshold', function () {
    config()->set('generation.stalled_run_after_seconds', 300);
    $run = new GenerationRun;
    $run->updated_at = now()->subSeconds(180);
    $lease = app(GenerationRunLease::class);

    expect($lease->isFresh($run))->toBeTrue()
        ->and($lease->isExpired($run))->toBeFalse();

    config()->set('generation.stalled_run_after_seconds', 120);

    expect($lease->isFresh($run))->toBeFalse()
        ->and($lease->isExpired($run))->toBeTrue();
});
