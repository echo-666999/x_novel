<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\NovelGenerationTask;
use App\Services\GenerationJobDispatcher;
use App\Services\GenerationRunCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** 每例显式建立独立任务，不能复用其他测试或旧小说记录。 */
function executionTask(): NovelGenerationTask
{
    return app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '一位船长寻找失落航线。');
}

test('duplicate delivery only claims one worker and produces one adopted result', function () {
    $task = executionTask();
    $coordinator = app(GenerationRunCoordinator::class);
    $run = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', ['schema' => 'v1']);
    expect($coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', ['schema' => 'v1'])->id)->toBe($run->id);
    $lease = $coordinator->claimExecution($run->id);
    expect($coordinator->claimExecution($run->id))->toBeNull();
    $first = $coordinator->completeExecution($run->id, $lease['token'], $lease['control_version'], ArtifactType::OperationRecord, ['result' => 'ok']);
    $again = $coordinator->completeExecution($run->id, $lease['token'], $lease['control_version'], ArtifactType::OperationRecord, ['result' => 'ok']);
    expect($again->id)->toBe($first->id);
    expect($run->fresh()->status)->toBe(RunStatus::Succeeded);
});

test('pause saves in flight result but prevents progression and new stages', function () {
    $task = executionTask();
    $coordinator = app(GenerationRunCoordinator::class);
    $run = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', []);
    $lease = $coordinator->claimExecution($run->id);
    $coordinator->control($task->id, 1, 'pause');
    $coordinator->completeExecution($run->id, $lease['token'], 1, ArtifactType::OperationRecord, ['result' => 'saved'], null, ['stage' => 'foundation_blueprint']);
    expect($task->fresh()->status)->toBe('paused');
    expect(data_get($task->fresh()->pending_transition, 'run_id'))->toBe($run->id);
    expect(fn () => $coordinator->enqueue($task->id, GenerationStage::FoundationBlueprint, 'book', []))->toThrow(LogicException::class);
    expect(fn () => $coordinator->control($task->id, 1, 'resume'))->toThrow(LogicException::class);
});

test('expired worker cannot overwrite a replacement or reset the attempt count', function () {
    $this->freezeTime();
    $task = executionTask();
    $coordinator = app(GenerationRunCoordinator::class);
    $run = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', []);
    $lease = $coordinator->claimExecution($run->id);
    $this->travel(451)->seconds();
    Queue::fake([ExecuteGenerationRunJob::class]);
    $counts = $coordinator->recoverTasks();
    expect($counts['retried'])->toBe(1);
    $replacement = GenerationRun::where('retry_of_run_id', $run->id)->firstOrFail();
    expect($replacement->attempt)->toBe(2);
    $late = $coordinator->completeExecution($run->id, $lease['token'], 1, ArtifactType::OperationRecord, ['late' => true]);
    expect($late->validation_status->value)->toBe('rejected');
    expect($run->fresh()->result_artifact_id)->toBeNull();
    Queue::assertNothingPushed();
});

test('persisted queued run can be redispatched without recreating the run', function () {
    Queue::fake([ExecuteGenerationRunJob::class]);
    $task = executionTask();
    $coordinator = app(GenerationRunCoordinator::class);
    $run = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', []);
    expect(app(GenerationJobDispatcher::class)->dispatchRun($run->id))->toBe('after_commit_pending');
    expect(app(GenerationJobDispatcher::class)->dispatchRun($run->id))->toBe('already_exists_or_blocked');
    // QueueFake 记录派发意图；事务未提交时不能宣称 Worker 已执行。
    Queue::assertPushed(ExecuteGenerationRunJob::class, fn ($job) => $job->runId === $run->id);
    expect(GenerationRun::where('logical_step_key', $run->logical_step_key)->count())->toBe(1);
});

test('resume adopts the saved successor without regenerating the completed request', function () {
    $task = executionTask();
    $coordinator = app(GenerationRunCoordinator::class);
    $run = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', []);
    $lease = $coordinator->claimExecution($run->id);
    $coordinator->control($task->id, 1, 'pause');
    $next = ['stage' => 'foundation_blueprint', 'scope' => 'book', 'contract' => []];
    $coordinator->completeExecution($run->id, $lease['token'], 1, ArtifactType::OperationRecord, ['saved' => true], next: $next);
    $resumed = $coordinator->control($task->id, 2, 'resume');
    expect($resumed->pending_transition)->toEqual($next);
    expect($run->fresh()->status)->toBe(RunStatus::Succeeded);
    $this->assertDatabaseCount('usage_records', 0);
});

test('changed source before claim becomes visible and does not call a provider', function () {
    $task = executionTask();
    $artifact = GenerationArtifact::findOrFail($task->intent_artifact_id);
    $coordinator = app(GenerationRunCoordinator::class);
    $run = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', [], [['artifact_id' => $artifact->id, 'checksum' => $artifact->checksum]]);
    // 直接SQL仅用于故障注入，应用层不可变Artifact仍禁止修改。
    DB::table('generation_artifacts')->where('id', $artifact->id)->update(['checksum' => str_repeat('a', 64)]);
    expect($coordinator->claimExecution($run->id))->toBeNull();
    expect($task->fresh()->status)->toBe('needs_attention');
    expect($run->fresh()->error_code)->toBe('source_changed');
    $this->assertDatabaseCount('usage_records', 0);
});

test('a task paused for days is not resumed or failed by execution lease recovery', function () {
    $this->freezeTime();
    $task = executionTask();
    $coordinator = app(GenerationRunCoordinator::class);
    $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', []);
    $coordinator->control($task->id, 1, 'pause');
    $this->travel(4)->days();
    Queue::fake();
    $coordinator->recoverTasks();
    expect($task->fresh()->status)->toBe('paused');
    Queue::assertNothingPushed();
});
