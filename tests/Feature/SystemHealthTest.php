<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ChapterStatus;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\Novel;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('system health exposes every mvp release checklist category without secrets', function () {
    config()->set('queue.default', 'redis');
    config()->set('app.locale', 'zh_CN');
    config()->set('ai.budget.daily_hard_limit', 10);

    $checks = app(SystemHealthService::class)->checks()->keyBy('key');

    expect($checks->keys()->all())->toBe([
        'database', 'redis', 'queue', 'horizon', 'scheduler', 'logs', 'config', 'rebuild',
        'cost_limit', 'emergency_stop', 'docs', 'database_backup', 'restore_test', 'queue_restart',
    ])->and($checks['database']->status)->toBe('healthy')
        ->and($checks['queue']->status)->toBe('healthy')
        ->and($checks['scheduler']->status)->toBe('healthy')
        ->and($checks['rebuild']->status)->toBe('healthy')
        ->and($checks['cost_limit']->status)->toBe('healthy')
        ->and($checks['database_backup']->status)->toBe('manual')
        ->and($checks->pluck('detail')->implode(' '))->not->toContain('sk-');
});

test('memory rebuild queues every canonical chapter once', function () {
    Queue::fake();
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $canonical = Chapter::factory()->count(3)->for($novel)->sequence(
        ['sequence' => 1, 'status' => ChapterStatus::Canonical],
        ['sequence' => 2, 'status' => ChapterStatus::Canonical],
        ['sequence' => 3, 'status' => ChapterStatus::Canonical],
    )->create();
    Chapter::factory()->for($novel)->create(['sequence' => 4, 'status' => ChapterStatus::Planned]);

    $exit = Artisan::call('memory:rebuild', ['novel' => $novel->getKey()]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('已排队 3 个 Canonical Chapter');
    Queue::assertPushed(UpdateMemoryJob::class, 3);
    foreach ($canonical as $chapter) {
        Queue::assertPushed(UpdateMemoryJob::class, fn (UpdateMemoryJob $job): bool => $job->chapterId === $chapter->getKey());
    }
});

test('horizon listens to both queues and release scheduler jobs are registered', function () {
    expect(config('horizon.defaults.supervisor-1.queue'))->toBe(['generation', 'default']);

    Artisan::call('schedule:list');
    expect(Artisan::output())
        ->toContain('generation:mark-stalled')
        ->toContain('horizon:snapshot');
});
