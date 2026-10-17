<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Models\GenerationRun;
use App\Models\NovelGenerationTask;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 创建合同不依赖旧Volume/Arc/Scene，重复请求与失败都不留下半初始化状态。
test('new task creation is atomic idempotent and independent of old planning tables', function () {
    $key = (string) Str::uuid();
    $task = app(CreateNovelGenerationTaskAction::class)->handle($key, '海边小镇的一名修钟人寻找失踪的父亲。');
    $same = app(CreateNovelGenerationTaskAction::class)->handle($key, '海边小镇的一名修钟人寻找失踪的父亲。');
    expect($same->id)->toBe($task->id);
    $this->assertDatabaseCount('novels', 1);
    $this->assertDatabaseCount('novel_generation_tasks', 1);
    foreach (['volumes', 'story_arcs', 'scenes', 'story_events', 'memories'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect(fn () => app(CreateNovelGenerationTaskAction::class)->handle($key, '不同概述'))->toThrow(LogicException::class);
    expect($task->novel->current_generation_task_id)->toBe($task->id);
});

test('paused and attention tasks retain the only active novel identity', function () {
    $task = NovelGenerationTask::factory()->create(['status' => 'paused']);
    expect(fn () => DB::transaction(fn () => NovelGenerationTask::factory()->create(['novel_id' => $task->novel_id])))
        ->toThrow(UniqueConstraintViolationException::class);
    $this->assertDatabaseCount('novel_generation_tasks', 1);
});

test('cross novel task and artifact references are rejected by postgres', function () {
    $first = NovelGenerationTask::factory()->create();
    $second = NovelGenerationTask::factory()->create();
    expect(fn () => DB::transaction(fn () => GenerationRun::factory()->create([
        'novel_id' => $second->novel_id, 'generation_task_id' => $first->id,
    ])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $second->update(['intent_artifact_id' => $first->intent_artifact_id])))
        ->toThrow(QueryException::class);
});

test('initialization rollback removes every dependent record', function () {
    try {
        DB::transaction(function (): void {
            app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '一个完整但尚未生成的故事。');
            throw new RuntimeException('模拟初始化提交前崩溃');
        });
    } catch (RuntimeException) {
        // 只观察原子效果，不修复或重试旧业务。
    }
    foreach (['novels', 'novel_generation_tasks', 'generation_runs', 'generation_artifacts'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});

test('new database metadata contains Chinese table and column comments', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $rows = DB::select("SELECT c.relname,a.attname,col_description(c.oid,a.attnum) AS comment FROM pg_class c JOIN pg_attribute a ON a.attrelid=c.oid WHERE c.relname IN ('x_novel_generation_tasks','x_generation_issues') AND a.attnum > 0 AND NOT a.attisdropped");
    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row->comment)->not->toBeNull()->not->toBe('');
    }
    expect(DB::scalar("SELECT obj_description('x_novel_generation_tasks'::regclass)"))->toContain('整书');
});
