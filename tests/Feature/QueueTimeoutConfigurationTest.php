<?php

use App\Jobs\AssembleChapterJob;
use App\Jobs\RewriteChapterJob;

test('horizon allows long generation jobs to finish before redis retries them', function () {
    $longestJobTimeout = max(
        (new ReflectionClass(AssembleChapterJob::class))->getDefaultProperties()['timeout'],
        (new ReflectionClass(RewriteChapterJob::class))->getDefaultProperties()['timeout'],
    );
    $workerTimeout = config('horizon.defaults.supervisor-1.timeout');
    $retryAfter = config('queue.connections.redis.retry_after');

    expect($workerTimeout)->toBeGreaterThan($longestJobTimeout)
        ->and($retryAfter)->toBeGreaterThan($workerTimeout);
});
