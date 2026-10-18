<?php

namespace App\Services;

use App\Data\GenerationFailure;
use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\UsageRecord;
use App\Support\GenerationDiagnosticRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 统一逻辑步骤身份、执行权和任务控制；旧入口在清理批次退出。 */
final class GenerationRunCoordinator
{
    public function __construct(private readonly GenerationRunLease $lease) {}

    /**
     * @param  Builder<GenerationRun>  $runs
     * @return array{run: GenerationRun|null, reused: bool, attempt: int}
     */
    public function resolve(Builder $runs, string $inputHash, string $interruptedMessage): array
    {
        $active = (clone $runs)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
        if ($this->lease->isFresh($active)) {
            return ['run' => $active, 'reused' => true, 'attempt' => (int) $active->attempt];
        }

        if ($active !== null) {
            $active->update([
                'status' => RunStatus::Failed,
                'error_code' => 'worker_interrupted',
                'error_message' => $interruptedMessage,
                'error_retryable' => false,
                'error_metadata' => [
                    'category' => 'worker_lost',
                    'stage' => $active->stage->value,
                    'input_hash' => $active->input_hash,
                    'next_action' => '继续执行',
                ],
                'finished_at' => now(),
            ]);
        }

        $succeeded = (clone $runs)->where('input_hash', $inputHash)
            ->whereHas('artifacts')
            ->where('status', RunStatus::Succeeded)->latest('id')->first();
        if ($succeeded !== null) {
            return ['run' => $succeeded, 'reused' => true, 'attempt' => (int) $succeeded->attempt];
        }

        return [
            'run' => null,
            'reused' => false,
            'attempt' => ((int) (clone $runs)->max('attempt')) + 1,
        ];
    }

    /** 在小说/任务锁下建立具体尝试；重复操作复用同来源成功产物或未执行 Run。 */
    public function enqueue(int $taskId, GenerationStage $stage, string $scope, array $contract, array $sources = []): GenerationRun
    {
        $task = NovelGenerationTask::findOrFail($taskId);

        return DB::transaction(function () use ($task, $stage, $scope, $contract, $sources): GenerationRun {
            Novel::whereKey($task->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->assertRunnable($task);
            $this->assertSources($task, $sources, $contract);
            $hash = app(GenerationInputIdentity::class)->hash(['stage' => $stage->value, 'scope' => $scope, 'contract' => $contract, 'sources' => $sources]);
            $key = $task->novel_id.':'.$scope.':'.$stage->value.':'.$hash;
            $existing = GenerationRun::where('logical_step_key', $key)->whereIn('status', ['queued', 'running', 'succeeded'])->latest('id')->first();
            if ($existing) {
                return $existing;
            }
            // 同一本小说串行推进，不允许不同逻辑键绕过单任务执行顺序。
            if (GenerationRun::where('novel_id', $task->novel_id)->where('pipeline_version', 'pipeline-v1')->whereIn('status', ['queued', 'running'])->exists()) {
                throw new \LogicException('小说已有待执行或运行中的阶段，请先完成或处理当前尝试。');
            }
            $attempt = ((int) GenerationRun::where('logical_step_key', $key)->max('attempt')) + 1;
            if ($attempt > (int) config('generation.execution.max_attempts', 3)) {
                throw new \LogicException('同一输入的技术尝试已耗尽，必须明确处理问题。');
            }
            $previous = GenerationRun::where('logical_step_key', $key)->latest('id')->value('id');
            $run = GenerationRun::create(['novel_id' => $task->novel_id, 'generation_task_id' => $task->id,
                'stage' => $stage, 'scope_type' => 'novel', 'scope_id' => $task->novel_id, 'scope_key' => $scope,
                'pipeline_version' => 'pipeline-v1', 'logical_step_key' => $key, 'attempt' => $attempt,
                'idempotency_key' => hash('sha256', $key.':'.$attempt), 'input_hash' => $hash,
                'execution_contract' => $contract, 'source_manifest' => $sources, 'retry_of_run_id' => $previous, 'issue_id' => $previous ? GenerationRun::find($previous)?->issue_id : null,
                'status' => RunStatus::Queued, 'queued_at' => now(), 'next_attempt_at' => now(),
                'provider' => $contract['provider'] ?? null, 'model' => $contract['model'] ?? null,
                'prompt_version' => $contract['prompt_version'] ?? null, 'connection_id' => $contract['connection_id'] ?? null]);
            $task->update(['status' => 'running', 'current_phase' => $stage->value, 'started_at' => $task->started_at ?? now(),
                'pending_transition' => ['stage' => $stage->value, 'scope' => $scope, 'contract' => $contract, 'sources' => $sources, 'run_id' => $run->id]]);

            return $run;
        });
    }

    /** 认领与控制状态同锁；返回执行权及控制版本，重复投递不再执行。 */
    public function claimExecution(int $runId): ?array
    {
        $run = GenerationRun::findOrFail($runId);

        return DB::transaction(function () use ($run): ?array {
            Novel::whereKey($run->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($run->generation_task_id)->lockForUpdate()->firstOrFail();
            $run = GenerationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! in_array($task->status, ['queued', 'running'], true) || $run->status !== RunStatus::Queued || $run->next_attempt_at?->isFuture()) {
                return null;
            }
            try {
                $this->assertRunnable($task);
                $this->assertSources($task, $run->source_manifest, $run->execution_contract);
            } catch (\LogicException $exception) {
                // 排队期间来源变化必须留下可见失败，不能让队列异常后任务永远排队。
                $run->update(['status' => RunStatus::Failed, 'error_code' => 'source_changed', 'error_message' => $exception->getMessage(), 'error_retryable' => false, 'finished_at' => now()]);
                $task->update(['status' => 'needs_attention', 'stop_reason' => 'source_changed', 'stop_detail' => ['message' => $exception->getMessage(), 'run_id' => $run->id]]);

                DB::afterCommit(fn () => app(GenerationIssueService::class)->observe($run->fresh()));

                return null;
            }
            $token = (string) Str::uuid();
            $run->update(['status' => RunStatus::Running, 'lease_token' => $token, 'lease_expires_at' => now()->addSeconds((int) config('generation.execution.lease_seconds', 390)),
                'heartbeat_at' => now(), 'started_at' => now()]);

            return ['token' => $token, 'control_version' => $task->control_version];
        });
    }

    /** 保存完整响应；旧租约或旧来源只能留下隔离候选，暂停只阻止推进。 */
    public function completeExecution(int $runId, string $token, int $controlVersion, ArtifactType $type, array $data, ?string $content = null, ?array $next = null): GenerationArtifact
    {
        $original = GenerationRun::findOrFail($runId);

        return DB::transaction(function () use ($original, $token, $controlVersion, $type, $data, $content, $next) {
            Novel::whereKey($original->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($original->generation_task_id)->lockForUpdate()->firstOrFail();
            $run = GenerationRun::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($run->status === RunStatus::Succeeded && $run->result_artifact_id) {
                return GenerationArtifact::findOrFail($run->result_artifact_id);
            }
            $valid = $run->status === RunStatus::Running && hash_equals((string) $run->lease_token, $token) && $run->lease_expires_at?->isFuture();
            $sourceChanged = false;
            try {
                $this->assertSources($task, $run->source_manifest, $run->execution_contract);
                if (data_get($run->execution_contract, 'issue_action') === 'diagnose') {
                    app(GenerationIssueService::class)->assertCurrent(GenerationIssue::findOrFail($run->issue_id));
                }
            } catch (\LogicException) {
                $sourceChanged = true;
                $valid = false;
            }
            $valid = $valid && (int) Novel::findOrFail($task->novel_id)->current_generation_task_id === $task->id && ! in_array($task->status, ['cancelled', 'failed', 'completed'], true);
            $artifact = GenerationArtifact::create(['generation_run_id' => $run->id, 'novel_id' => $run->novel_id,
                'type' => $type, 'version' => ((int) $run->artifacts()->where('type', $type)->max('version')) + 1,
                'data' => $data, 'content' => $content, 'checksum' => app(GenerationInputIdentity::class)->hash(['data' => $data, 'content' => $content]),
                'source_manifest' => $run->source_manifest, 'validation_status' => $valid ? 'validated' : 'rejected']);
            if ($valid) {
                $run->update(['status' => RunStatus::Succeeded, 'result_artifact_id' => $artifact->id, 'checkpoint' => ['next' => $next], 'finished_at' => now(), 'lease_expires_at' => null, 'lease_token' => null]);
                if ($task->status === 'running' && $task->control_version === $controlVersion) {
                    $task->update(['pending_transition' => $next, 'last_progress_at' => now()]);
                }
            }

            if ($sourceChanged && $run->status === RunStatus::Running && hash_equals((string) $run->lease_token, $token)) {
                if (data_get($task->pending_transition, 'manual_draft_id') || (isset($run->execution_contract['chapter_plan_id']) && ! Chapter::whereKey($run->chapter_id)->where('current_plan_id', $run->execution_contract['chapter_plan_id'])->exists())) {
                    // 人工明确换稿或重建计划后，晚到结果只留历史，不阻塞新的复验链。
                    $run->update(['status' => RunStatus::Cancelled, 'error_code' => 'source_changed', 'error_message' => '来源已由人工更新，响应隔离保存。', 'finished_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
                } else {
                    $this->failExecution($run->id, $token, 'source_changed', '结果已隔离：正式状态或来源版本发生变化。', false);
                }
            }

            DB::afterCommit(fn () => app(GenerationIssueService::class)->observe($run->fresh()));

            return $artifact;
        });
    }

    /** 用户控制操作采用 CAS；暂停任务仍保留唯一身份，不由 Scheduler 恢复。 */
    public function control(int $taskId, int $expectedVersion, string $action): NovelGenerationTask
    {
        if (! in_array($action, ['pause', 'resume', 'cancel'], true)) {
            throw new \InvalidArgumentException('未知任务动作。');
        }
        $initial = NovelGenerationTask::findOrFail($taskId);

        return DB::transaction(function () use ($initial, $expectedVersion, $action) {
            Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            if ($task->control_version !== $expectedVersion || in_array($task->status, ['completed', 'cancelled', 'failed'], true)) {
                throw new \LogicException('任务版本已变化或已结束，请刷新后重试。');
            }
            if ($action === 'resume' && $task->status !== 'paused') {
                throw new \LogicException('只能恢复用户暂停任务；问题必须先复验。');
            }
            // 暂停期间完成的产物已保存；恢复只采用同源后继，不重新请求模型。
            if ($action === 'resume' && ($savedId = data_get($task->pending_transition, 'run_id'))) {
                $saved = GenerationRun::find($savedId);
                if ($saved?->status === RunStatus::Succeeded && $saved->generation_task_id === $task->id) {
                    $this->assertSources($task, $saved->source_manifest, $saved->execution_contract);
                    $task->pending_transition = data_get($saved->checkpoint, 'next');
                }
            }
            $task->update(['status' => match ($action) {
                'pause' => 'paused','resume' => 'running','cancel' => 'cancelled'
            },
                'control_version' => $expectedVersion + 1, 'stop_reason' => $action === 'resume' ? null : 'user_'.$action]);

            return $task;
        });
    }

    /** 失败不清除费用预留；只记录执行结论和可复核的恢复位置。 */
    public function failExecution(int $runId, string $token, string $code, string $message, bool $retryable, array $metadata = []): void
    {
        $run = GenerationRun::findOrFail($runId);
        DB::transaction(function () use ($run, $token, $code, $message, $retryable, $metadata): void {
            Novel::whereKey($run->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($run->generation_task_id)->lockForUpdate()->firstOrFail();
            $run = GenerationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== RunStatus::Running || ! hash_equals((string) $run->lease_token, $token)) {
                return;
            }
            // 新链保存结构化分类依据并脱敏；Schema/业务失败不能因供应商 retryable 标记而盲重试。
            $redactor = app(GenerationDiagnosticRedactor::class);
            $message = $redactor->clean($message);
            $metadata = $redactor->clean($metadata);
            $classification = app(GenerationIssueCatalog::class)->classify(new GenerationFailure($code, $message, $retryable, $metadata, ''), $run->stage);
            $retryable = $classification['retryable'];
            $run->update(['status' => RunStatus::Failed, 'error_code' => $code, 'error_message' => $message, 'error_metadata' => $metadata,
                'error_retryable' => $retryable, 'finished_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
            // Worker消失不等于未收费；发送标记之后统一保留未知结果与占用。
            if ($code === 'worker_lost') {
                UsageRecord::where('generation_run_id', $run->id)->where('status', 'in_flight')->update(['status' => 'unknown']);
                UsageRecord::where('generation_run_id', $run->id)->where('status', 'prepared')->update(['status' => 'not_sent', 'reserved_amount' => null]);
            }
            if ($classification['blocking'] && $task->status === 'running' && (! $retryable || $run->attempt >= (int) config('generation.execution.max_attempts', 3))) {
                $task->update(['status' => 'needs_attention', 'stop_reason' => $code, 'stop_detail' => [...($task->stop_detail ?? []), 'message' => $message, 'run_id' => $run->id]]);
            }
            DB::afterCommit(fn () => app(GenerationIssueService::class)->observe($run->fresh()));
        });
    }

    private function assertRunnable(NovelGenerationTask $task): void
    {
        if (! in_array($task->status, ['queued', 'running'], true) || (int) $task->novel->current_generation_task_id !== $task->id) {
            throw new \LogicException('当前任务已暂停、结束或不再拥有小说推进权。');
        }
    }

    /** JSON 来源也必须核实真实数据库归属和checksum，不能信任调用方。 */
    public function assertSources(NovelGenerationTask $task, array $sources, array $contract = []): void
    {
        $novel = Novel::findOrFail($task->novel_id);
        if (isset($contract['hard_context_hash']) && ! hash_equals($contract['hard_context_hash'], app(ContextBuilder::class)->bookSourceHash($novel))) {
            throw new \LogicException('设定或锁定事实已变化，请重建当前章计划，不能继续旧硬约束。');
        }
        if (array_key_exists('state_version_id', $contract) && $novel->canonical_state_version_id !== $contract['state_version_id']) {
            throw new \LogicException('正式状态版本已变化，请按当前状态重新建立输入。');
        }
        if (isset($contract['chapter_plan_id']) && ! Chapter::where('novel_id', $task->novel_id)->whereKey($contract['chapter_id'] ?? 0)->where('current_plan_id', $contract['chapter_plan_id'])->exists()) {
            throw new \LogicException('当前章节计划已经改变，旧来源不能继续。');
        }
        // 修改概述即使没有正式状态变化，也必须使旧设定请求失去采用权。
        if (isset($contract['intent_artifact_id']) && $task->intent_artifact_id !== $contract['intent_artifact_id']) {
            throw new \LogicException('创作意图已更新，旧来源不能继续。');
        }
        if (! empty($contract['goal_refs'])) {
            app(NovelPlanningService::class)->assertGoalSources($task, $contract['goal_refs']);
        }
        foreach ($sources as $source) {
            $artifact = GenerationArtifact::find($source['artifact_id'] ?? 0);
            if (! $artifact || $artifact->novel_id !== $task->novel_id || ! hash_equals($artifact->checksum, (string) ($source['checksum'] ?? '')) || $artifact->validation_status !== ArtifactValidationStatus::Validated) {
                throw new \LogicException('来源产物已失效、归属错误或哈希不匹配。');
            }
            if (isset($source['pointer']) && ! in_array($source['pointer'], ['current_draft_artifact_id', 'canonical_artifact_id', 'summary_artifact_id'], true)) {
                throw new \LogicException('来源指针不在白名单。');
            }
            if (isset($source['pointer']) && ! Chapter::where('novel_id', $task->novel_id)->whereKey($source['chapter_id'] ?? 0)->where($source['pointer'], $artifact->id)->exists()) {
                throw new \LogicException('正文来源已被新版本替换。');
            }
        }
    }

    /** 从数据库补派孤立执行；暂停只失效旧租约，不创建新的收费尝试。 */
    public function recoverTasks(): array
    {
        app(GenerationIssueService::class)->reconcile();
        $counts = ['expired' => 0, 'dispatched' => 0, 'retried' => 0, 'blocked' => 0];
        foreach (GenerationRun::where('pipeline_version', 'pipeline-v1')->where('status', 'running')->where('lease_expires_at', '<=', now())->get() as $run) {
            $this->failExecution($run->id, (string) $run->lease_token, 'worker_lost', 'Worker 执行租约已过期，响应及费用可能未知。', true);
            $counts['expired']++;
        }
        foreach (NovelGenerationTask::whereIn('status', ['queued', 'running'])->get() as $task) {
            try {
                $pending = $task->pending_transition;
                if (! is_array($pending)) {
                    continue;
                }
                if (($pending['stage'] ?? null) === 'book_control') {
                    app(NovelBookWorkflow::class)->advance($task->id);

                    continue;
                }
                if (empty($pending['run_id'])) {
                    $stage = GenerationStage::tryFrom($pending['stage'] ?? '');
                    if ($stage === null) {
                        continue;
                    }
                    $created = $this->enqueue($task->id, $stage, $pending['scope'] ?? 'book', $pending['contract'] ?? [], $pending['sources'] ?? []);
                    $pending['run_id'] = $created->id;
                }
                $run = GenerationRun::find($pending['run_id']);
                if (! $run || $run->generation_task_id !== $task->id) {
                    continue;
                }
                if ($run->status === RunStatus::Succeeded && data_get($run->checkpoint, 'next')) {
                    app(NovelBookWorkflow::class)->advance($task->id);

                    continue;
                }
                if ($run->status === RunStatus::Failed && $run->error_retryable && $run->attempt < (int) config('generation.execution.max_attempts', 3)) {
                    if (! $run->issue_id) {
                        app(GenerationIssueService::class)->observe($run);
                        $run->refresh();
                    }
                    $issue = GenerationIssue::findOrFail($run->issue_id);
                    $run = app(GenerationIssueActions::class)->execute($issue->id, 'retry_transient', $issue->fingerprint,
                        $task->fresh()->control_version, (string) Str::uuid(), dispatch: false);
                    $delays = config('generation.execution.backoff', [10, 30]);
                    $run->update(['next_attempt_at' => now()->addSeconds($delays[min(count($delays) - 1, max(0, $run->attempt - 2))])]);
                    $counts['retried']++;
                }
                if ($run->status === RunStatus::Queued && app(GenerationJobDispatcher::class)->dispatchRun($run->id) === 'enqueued') {
                    $counts['dispatched']++;
                }
            } catch (\LogicException $exception) {
                // 单任务合同损坏不阻止其他小说补偿；保持可见停点，避免无穷重试。
                NovelGenerationTask::whereKey($task->id)->whereIn('status', ['queued', 'running'])->update([
                    'status' => 'needs_attention', 'stop_reason' => 'recovery_contract_invalid',
                    'stop_detail' => ['message' => $exception->getMessage()],
                ]);
                $counts['blocked']++;
            }
        }

        return $counts;
    }
}
