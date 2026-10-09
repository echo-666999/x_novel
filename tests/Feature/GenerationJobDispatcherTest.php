<?php

use App\Jobs\AssembleChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\Chapter;
use App\Services\ChapterPlanner;
use App\Services\GenerationJobDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Queue::fake();
});

test('chapter generation jobs are unique for their stage and scope', function () {
    $jobs = [
        new PlanChapterJob(12),
        new GenerateSceneJob(34),
        new AssembleChapterJob(12),
        new ExtractStoryEventsJob(12),
        new ReviewChapterJob(12),
        new RewriteChapterJob(12),
    ];

    foreach ($jobs as $job) {
        expect($job)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->uniqueFor())->toBe((int) config('generation.pending_job_seconds'));
    }

    expect((new GenerateSceneJob(34, true, true))->uniqueId())->toBe((new GenerateSceneJob(34))->uniqueId())
        ->and((new ExtractStoryEventsJob(12, true, true))->uniqueId())->toBe((new ExtractStoryEventsJob(12))->uniqueId())
        ->and((new RewriteChapterJob(12, 34))->uniqueId())->toBe((new RewriteChapterJob(12))->uniqueId());
});

test('checkpoint jobs release the framework unique lock before dispatching a deferred continuation', function () {
    // 这三个 Job 会在 handle 内续派同类任务，必须在开始处理时释放 Laravel 唯一锁。
    expect(new GenerateSceneJob(34))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and(new ExtractStoryEventsJob(12))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and(new RewriteChapterJob(12, 34))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});

test('dispatcher records queue state immediately and rejects a duplicate dispatch', function () {
    $dispatcher = app(GenerationJobDispatcher::class);

    expect($dispatcher->dispatch(new AssembleChapterJob(12)))->toBeTrue()
        ->and($dispatcher->isPending(new AssembleChapterJob(12)))->toBeTrue()
        ->and($dispatcher->dispatch(new AssembleChapterJob(12, true)))->toBeFalse();

    Queue::assertPushed(AssembleChapterJob::class, 1);
});

test('a completed job releases its pending queue state', function () {
    $dispatcher = app(GenerationJobDispatcher::class);
    $chapter = Chapter::factory()->create();
    $job = new PlanChapterJob($chapter->getKey());
    $planner = Mockery::mock(ChapterPlanner::class);
    $planner->shouldReceive('generate')->once()->with($chapter->getKey(), false)->andReturnNull();

    expect($dispatcher->reserve($job))->toBeTrue()
        ->and($dispatcher->isPending($job))->toBeTrue();

    $job->handle($planner);

    expect($dispatcher->isPending($job))->toBeFalse();
});
