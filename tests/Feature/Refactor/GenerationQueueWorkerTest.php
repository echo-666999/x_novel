<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\GenerationRun;
use App\Services\GenerationRunCoordinator;
use App\Support\IsolatedTestEnvironment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ExitAfterClaimJob;

// 真实 Redis 消费只使用专属前缀与队列，不碰开发 generation 队列。
test('real redis worker consumes duplicate deliveries with one persisted result', function () {
    expect(DB::scalar('SELECT current_database()'))->toBe(IsolatedTestEnvironment::DATABASE);
    expect(config('database.redis.options.prefix'))->toBe(IsolatedTestEnvironment::REDIS_PREFIX);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '沙漠旅人守护一口古井。');
    $run = app(GenerationRunCoordinator::class)->enqueue($task->id, GenerationStage::Preflight, 'book', []);
    for ($i = 0; $i < 2; $i++) {
        Queue::connection('redis')->push((new ExecuteGenerationRunJob($run->id))->onQueue('xnovel-refactor-test'), '', 'xnovel-refactor-test');
    }
    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'xnovel-refactor-test', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
    expect($run->fresh()->status)->toBe(RunStatus::Succeeded);
    expect($run->artifacts()->count())->toBe(1);
    expect(Queue::connection('redis')->size('xnovel-refactor-test'))->toBe(0);
    $this->assertDatabaseCount('usage_records', 0);
    Artisan::call('migrate:fresh', ['--force' => true]);
});

test('worker process exit after claim or saved result is recovered from durable intent', function (bool $saveResult) {
    expect(DB::scalar('SELECT current_database()'))->toBe(IsolatedTestEnvironment::DATABASE);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $queue = 'xnovel-refactor-crash-test';
    $isolatedQueue = Queue::connection('redis');
    $isolatedQueue->clear($queue);
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '故障夹具里的灯塔');
    $coordinator = app(GenerationRunCoordinator::class);
    $run = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', []);
    Queue::connection('redis')->push((new ExitAfterClaimJob($run->id, $saveResult))->onQueue($queue), '', $queue);
    // 子进程显式隔离数据库及Redis前缀；不依赖当前.env里的运行数据库名称。
    $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--once', '--tries=1'], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => IsolatedTestEnvironment::DATABASE, 'DB_URL' => '',
        'REDIS_URL' => '', 'REDIS_PREFIX' => IsolatedTestEnvironment::REDIS_PREFIX, 'QUEUE_CONNECTION' => 'redis', 'CACHE_STORE' => 'array',
    ]);
    try {
        $worker->setTimeout(20)->run();
        expect($worker->getExitCode())->toBe(17);
        expect($run->fresh()->status)->toBe($saveResult ? RunStatus::Succeeded : RunStatus::Running);
        if (! $saveResult) {
            $run->update(['lease_expires_at' => now()->subMinute()]);
        }
        Queue::fake([ExecuteGenerationRunJob::class]);
        $coordinator->recoverTasks();
        $successor = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
        expect($successor->id)->not->toBe($run->id);
        expect($successor->attempt)->toBe($saveResult ? 1 : 2);
        expect($run->artifacts()->count())->toBe($saveResult ? 1 : 0);
        $this->assertDatabaseCount('usage_records', 0);
    } finally {
        // 仅清除夹具专属队列，包含退出后留下的reserved项。
        $isolatedQueue->clear($queue);
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
})->with([false, true]);
