<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Actions\Novels\InitializeNovelSystemAction;
use App\Support\IsolatedTestEnvironment;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 本文件需要独立提交给第二连接观察，不能使用包裹整个测试的事务 trait。
test('two postgres connections cannot create a second unfinished task', function () {
    expect(DB::scalar('SELECT current_database()'))->toBe(IsolatedTestEnvironment::DATABASE);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '在沙漠寻找失落城市。');
    $task->update(['status' => 'cancelled']);
    config(['database.connections.identity_contender' => config('database.connections.pgsql')]);
    $first = DB::connection();
    $second = DB::connection('identity_contender');
    $sql = "INSERT INTO x_novel_generation_tasks (novel_id,submission_key,intent_artifact_id,target_words,status) VALUES (?,?,?,100000,'paused')";
    $first->beginTransaction();
    try {
        $first->insert($sql, [$task->novel_id, (string) Str::uuid(), $task->intent_artifact_id]);
        $second->statement("SET lock_timeout='100ms'");
        expect(fn () => $second->insert($sql, [$task->novel_id, (string) Str::uuid(), $task->intent_artifact_id]))
            ->toThrow(QueryException::class);
        $first->commit();
        expect(fn () => $second->insert($sql, [$task->novel_id, (string) Str::uuid(), $task->intent_artifact_id]))
            ->toThrow(UniqueConstraintViolationException::class);
        expect($first->table('novel_generation_tasks')->where('status', 'paused')->count())->toBe(1);
    } finally {
        if ($first->transactionLevel()) {
            $first->rollBack();
        }
        DB::purge('identity_contender');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});

test('empty system initialization is repeatable without novels secrets or password replacement', function () {
    $action = app(InitializeNovelSystemAction::class);
    $user = $action->handle('测试操作者', 'r01@example.invalid', 'isolated-test-password');
    $passwordHash = $user->password;
    $again = $action->handle('第二次初始化', 'r01@example.invalid', 'another-test-password');
    expect($again->id)->toBe($user->id);
    expect($again->password)->toBe($passwordHash);
    $this->assertDatabaseCount('users', 1);
    foreach (['novels', 'chapters', 'memories', 'ai_provider_connections'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $user->delete();
});
