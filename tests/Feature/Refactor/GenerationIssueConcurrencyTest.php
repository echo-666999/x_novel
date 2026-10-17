<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Enums\GenerationStage;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\UsageRecord;
use App\Services\GenerationIssueActions;
use App\Services\GenerationIssueService;
use App\Services\GenerationRunCoordinator;
use App\Support\IsolatedTestEnvironment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 两个真实 PostgreSQL 会话争用小说锁，验证活跃问题只有一个。 */
test('concurrent issue recorders serialize and keep one first evidence', function () {
    expect(DB::scalar('SELECT current_database()'))->toBe(IsolatedTestEnvironment::DATABASE);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '并发夹具：寻找失落信件。');
    $run = app(GenerationRunCoordinator::class)->enqueue($task->id, GenerationStage::Preflight, 'book', []);
    $run->update(['status' => 'failed', 'error_code' => 'provider_timeout', 'error_message' => '首次证据', 'error_retryable' => true]);
    config(['database.connections.issue_contender' => config('database.connections.pgsql')]);
    $first = DB::connection('pgsql');
    $second = DB::connection('issue_contender');
    $second->statement("SET lock_timeout='100ms'");
    $first->beginTransaction();
    try {
        $issue = app(GenerationIssueService::class)->record($run);
        DB::setDefaultConnection('issue_contender');
        expect(fn () => app(GenerationIssueService::class)->record(GenerationRun::findOrFail($run->id)))->toThrow(QueryException::class);
        $first->commit();
        $same = app(GenerationIssueService::class)->record(GenerationRun::findOrFail($run->id));
        expect($same->id)->toBe($issue->id);
        expect(GenerationIssue::count())->toBe(1);
        expect($same->evidence['occurrences'])->toHaveCount(1);
        expect($same->evidence['occurrences'][0]['message'])->toBe('首次证据');
    } finally {
        DB::setDefaultConnection('pgsql');
        if ($first->transactionLevel()) {
            $first->rollBack();
        }
        DB::purge('issue_contender');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});

/** 不使用测试事务，真实触发 afterCommit；验证“失败保留→对账补 Issue→同源成功关闭”。 */
test('committed lifecycle reconciles missing issue then resolves only after actual retry success', function () {
    expect(DB::scalar('SELECT current_database()'))->toBe(IsolatedTestEnvironment::DATABASE);
    Artisan::call('migrate:fresh', ['--force' => true]);
    try {
        $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '事务提交后的恢复测试。');
        $coordinator = app(GenerationRunCoordinator::class);
        $run = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', []);
        // 模拟进程在失败落库之后、问题记录之前退出。
        $run->update(['status' => 'failed', 'error_code' => 'provider_timeout', 'error_message' => '原始网络故障', 'error_retryable' => true]);
        app(GenerationIssueService::class)->reconcile();
        $issue = GenerationIssue::sole();
        $retry = app(GenerationIssueActions::class)->execute($issue->id, 'retry_transient', $issue->fingerprint, $task->fresh()->control_version, (string) Str::uuid(), dispatch: false);
        (new ExecuteGenerationRunJob($retry->id))->handle($coordinator);
        expect($issue->fresh()->status)->toBe('resolved');
        expect($issue->fresh()->resolved_by_run_id)->toBe($retry->id);
        expect($run->fresh()->error_message)->toBe('原始网络故障');
        expect($task->fresh()->status)->toBe('running');
        expect(UsageRecord::count())->toBe(0);
    } finally {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});
