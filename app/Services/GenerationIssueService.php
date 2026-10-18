<?php

namespace App\Services;

use App\Data\GenerationFailure;
use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\UsageRecord;
use App\Support\GenerationDiagnosticRedactor;
use Illuminate\Support\Facades\DB;
use Throwable;

/** 问题身份与复验共享小说锁，保证去重、来源检查和任务恢复为同一事务。 */
final class GenerationIssueService
{
    public function identity(GenerationRun $run): array
    {
        return ['task_id' => $run->generation_task_id, 'chapter_id' => $run->chapter_id,
            'stage' => $run->stage->value, 'scope' => $run->scope_key, 'input_hash' => $run->input_hash,
            'sources' => $run->source_manifest ?? [],
            'contract_hash' => app(GenerationInputIdentity::class)->hash($run->execution_contract ?? [])];
    }

    public function record(GenerationRun $original, ?GenerationFailure $failure = null): GenerationIssue
    {
        $failure ??= app(GenerationFailurePolicy::class)->forRun($original);

        return DB::transaction(function () use ($original, $failure): GenerationIssue {
            Novel::whereKey($original->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($original->generation_task_id)->lockForUpdate()->firstOrFail();
            $run = GenerationRun::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($run->pipeline_version !== 'pipeline-v1') {
                throw new \LogicException('旧历史不导入新版问题中心。');
            }
            // 修复器出错仍更新原问题，绝不递归创建“修复修复器”。
            if ($run->issue_id) {
                $issue = GenerationIssue::findOrFail($run->issue_id);
                $this->finishAttempt($run);

                return $issue->refresh();
            }
            $policy = app(GenerationIssueCatalog::class)->classify($failure, $run->stage);
            $source = $this->identity($run);
            $fingerprint = app(GenerationInputIdentity::class)->hash(['novel' => $run->novel_id, 'source' => $source, 'category' => $policy['category'], 'rule' => $policy['rule_id']]);
            $issue = GenerationIssue::where('novel_id', $run->novel_id)->where('fingerprint', $fingerprint)->where('status', '!=', 'resolved')->first();
            $redactor = app(GenerationDiagnosticRedactor::class);
            $entry = $redactor->clean(['run_id' => $run->id, 'code' => $failure->code, 'message' => $failure->message,
                'metadata' => $failure->metadata, 'observed_at' => now()->toISOString()]);
            if (! $issue) {
                $issue = GenerationIssue::create(['novel_id' => $run->novel_id, 'generation_task_id' => $task->id,
                    'chapter_id' => $run->chapter_id, 'fingerprint' => $fingerprint, 'source_identity' => $source,
                    'category' => $policy['category'], 'error_code' => $failure->code, 'severity' => $policy['severity'],
                    'status' => $policy['retryable'] || ! $policy['blocking'] ? 'open' : 'awaiting_user',
                    'first_run_id' => $run->id, 'latest_run_id' => $run->id, 'user_message' => $policy['message'],
                    'evidence' => ['policy' => $policy, 'occurrences' => [$entry], 'attempts' => [], 'operations' => []],
                    'allowed_actions' => $policy['retryable'] ? ['retry_transient', 'diagnose', 'save_note', 'pause'] : ['diagnose', 'save_note', 'pause'],
                    'repair_limit_snapshot' => config('generation.issues'), 'first_seen_at' => now(), 'last_seen_at' => now()]);
            } else {
                $evidence = $issue->evidence;
                if (! collect($evidence['occurrences'])->contains('run_id', $run->id)) {
                    $evidence['occurrences'][] = $entry;
                }
                $issue->update(['evidence' => $evidence, 'latest_run_id' => $run->id, 'last_seen_at' => now()]);
            }
            $run->update(['issue_id' => $issue->id]);
            // 失败时已发生的费用归入原问题，不能只统计后续修复来绕过问题上限。
            UsageRecord::where('generation_run_id', $run->id)->where('novel_id', $issue->novel_id)->whereNull('issue_id')->update(['issue_id' => $issue->id]);
            // 金额未知与预警只提示；暂停与取消意图不能被问题记录覆盖。
            if ($policy['blocking'] && ! $policy['retryable'] && in_array($task->status, ['queued', 'running'], true)) {
                $task->update(['status' => 'needs_attention', 'stop_reason' => $failure->code]);
            }

            return $issue;
        });
    }

    /** 复用执行器的来源门禁，避免人工与 AI 各维护一份较弱校验。 */
    public function assertCurrent(GenerationIssue $issue): GenerationRun
    {
        $task = NovelGenerationTask::findOrFail($issue->generation_task_id);
        $run = GenerationRun::findOrFail($issue->first_run_id);
        if ($issue->status === 'resolved' || $run->novel_id !== $issue->novel_id || $run->generation_task_id !== $task->id
            || (int) $task->novel->current_generation_task_id !== $task->id || in_array($task->status, ['cancelled', 'completed', 'failed'], true)
            || ! $this->matchesVerificationSource($run, $issue)) {
            throw new \LogicException('问题已解决、任务已结束或来源身份不匹配，请刷新。');
        }
        app(GenerationRunCoordinator::class)->assertSources($task, $run->source_manifest ?? [], $run->execution_contract ?? []);

        return $run;
    }

    /** 成功 Run 必须同阶段、同输入且真实产物已验证；仅模型声称成功不算复验。 */
    /** 修复 Prompt 可改变，但原问题身份、当前意图和来源清单必须保持一致；不能放宽其他阶段门禁。 */
    private function matchesVerificationSource(GenerationRun $run, GenerationIssue $issue): bool
    {
        $identity = app(GenerationInputIdentity::class);
        if ($identity->hash($this->identity($run)) === $identity->hash($issue->source_identity)) {
            return true;
        }
        if ($run->stage !== GenerationStage::FoundationBlueprint || data_get($run->execution_contract, 'issue_action') !== 'repair_foundation'
            || data_get($issue->evidence, 'attempts.'.$run->id.'.action') !== 'repair_foundation'
            || $identity->hash(data_get($run->execution_contract, 'repair_source_identity', [])) !== $identity->hash($issue->source_identity)
            || $identity->hash($run->source_manifest) !== $identity->hash($issue->source_identity['sources'] ?? [])) {
            return false;
        }
        $task = NovelGenerationTask::findOrFail($run->generation_task_id);
        app(GenerationRunCoordinator::class)->assertSources($task, $run->source_manifest, $run->execution_contract);

        return $task->intent_artifact_id === data_get($run->execution_contract, 'intent_artifact_id');
    }

    public function verifySuccess(GenerationRun $candidate): bool
    {
        if (! $candidate->issue_id || data_get($candidate->execution_contract, 'issue_action') === 'diagnose') {
            return false;
        }

        return DB::transaction(function () use ($candidate): bool {
            Novel::whereKey($candidate->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($candidate->generation_task_id)->lockForUpdate()->firstOrFail();
            $issue = GenerationIssue::whereKey($candidate->issue_id)->lockForUpdate()->firstOrFail();
            if ($issue->status === 'resolved') {
                return $issue->resolved_by_run_id === $candidate->id;
            }
            $this->assertCurrent($issue);
            $run = $candidate->fresh();
            $artifact = GenerationArtifact::find($run->result_artifact_id);
            if ($run->status !== RunStatus::Succeeded || ! $artifact || $artifact->generation_run_id !== $run->id
                || $artifact->validation_status !== ArtifactValidationStatus::Validated
                || ! $this->verifiedStageResult($run, $artifact)
                || ! $this->matchesVerificationSource($run, $issue)) {
                return false;
            }
            $issue->update(['status' => 'resolved', 'resolved_by_run_id' => $run->id, 'resolved_at' => now(),
                'resolution_reason' => '同来源执行及产物复验通过。',
                'verification' => ['run_id' => $run->id, 'artifact_id' => $artifact->id, 'checksum' => $artifact->checksum, 'source_identity' => $issue->source_identity]]);
            if ($task->status === 'needs_attention' && ! $this->hasOtherBlockers($issue)) {
                $task->update(['status' => 'running', 'stop_reason' => null, 'pending_transition' => data_get($run->checkpoint, 'next')]);
            }

            return true;
        });
    }

    /** 每个创作阶段有独立成功标准，不能用 HTTP 成功替代业务通过。 */
    private function verifiedStageResult(GenerationRun $run, GenerationArtifact $artifact): bool
    {
        if ($run->stage === GenerationStage::Preflight) {
            return $artifact->type === ArtifactType::OperationRecord && data_get($artifact->data, 'checks.task_identity') === 'valid' && data_get($artifact->data, 'checks.sources') === 'valid';
        }
        if ($run->stage === GenerationStage::FoundationBlueprint && $artifact->type === ArtifactType::FoundationBlueprint) {
            $intent = GenerationArtifact::find(data_get($run->execution_contract, 'intent_artifact_id'));
            if (! $intent || ($artifact->data['conflicts'] ?? null) !== []) {
                return false;
            }
            try {
                app(NovelFoundationContract::class)->validate($artifact->data, $intent->data);

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    public function hasOtherBlockers(GenerationIssue $issue): bool
    {
        return GenerationIssue::where('generation_task_id', $issue->generation_task_id)->where('id', '!=', $issue->id)
            ->where('status', '!=', 'resolved')->whereIn('severity', ['error', 'critical'])->exists();
    }

    /** 完成/失败/硬退出累计一次实际执行时间，排队与人工暂停不计入。 */
    public function finishAttempt(GenerationRun $original): void
    {
        if (! $original->issue_id) {
            return;
        }
        DB::transaction(function () use ($original): void {
            Novel::whereKey($original->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($original->generation_task_id)->lockForUpdate()->firstOrFail();
            $issue = GenerationIssue::whereKey($original->issue_id)->lockForUpdate()->firstOrFail();
            $run = $original->fresh();
            if (! in_array($run->status, [RunStatus::Succeeded, RunStatus::Failed, RunStatus::Cancelled], true)) {
                return;
            }
            $evidence = $issue->evidence;
            $attempts = $evidence['attempts'] ?? [];
            $attempt = $attempts[(string) $run->id] ?? null;
            if ($attempt && empty($attempt['finished_at'])) {
                $elapsed = $run->started_at ? min((int) config('generation.execution.lease_seconds', 390) * 1000,
                    max(0, (int) $run->started_at->diffInMilliseconds($run->finished_at ?? now()))) : 0;
                $attempts[(string) $run->id] = [...$attempt, 'finished_at' => now()->toISOString(), 'elapsed_ms' => $elapsed,
                    'status' => $run->status->value, 'error' => app(GenerationDiagnosticRedactor::class)->clean($run->error_message),
                    'artifact_id' => $run->result_artifact_id];
                $evidence['attempts'] = $attempts;
                $issue->update(['repair_elapsed_ms' => $issue->repair_elapsed_ms + $elapsed, 'evidence' => $evidence]);
            }
            $diagnostic = data_get($run->execution_contract, 'issue_action') === 'diagnose';
            if ($attempt && $issue->status !== 'resolved') {
                $issue->update(['status' => 'awaiting_user', 'last_seen_at' => now()]);
            }
            if ($diagnostic && in_array($task->status, ['running', 'needs_attention'], true)
                && (int) data_get($task->pending_transition, 'run_id') === $run->id) {
                $task->update(['status' => data_get($issue->evidence, 'policy.blocking', true) ? 'needs_attention' : 'running',
                    'pending_transition' => data_get($run->execution_contract, 'resume_transition')]);
            }
        });
    }

    /** 原异常已经持久化后再补问题；记录失败不回滚原失败事实。 */
    public function observe(GenerationRun $run): void
    {
        if ($run->pipeline_version !== 'pipeline-v1' || ! $run->generation_task_id
            || in_array(data_get($run->execution_contract, 'issue_action'), ['save_note', 'pause'], true)) {
            return;
        }
        try {
            if ($run->status === RunStatus::Failed) {
                $this->record($run);
            } elseif ($run->status === RunStatus::Succeeded && $run->issue_id) {
                $this->finishAttempt($run);
                $this->verifySuccess($run);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Scheduler 对账补建中断记录，同时处理修复结束但状态尚未回写的窗口。 */
    public function reconcile(): void
    {
        GenerationRun::where('pipeline_version', 'pipeline-v1')->whereNotNull('generation_task_id')
            ->where(function ($query): void {
                $query->where(fn ($query) => $query->where('status', 'failed')->whereNull('issue_id'))
                    ->orWhereHas('issue', fn ($query) => $query->where('status', '!=', 'resolved'));
            })->orderBy('id')->chunkById(100, function ($runs): void {
                foreach ($runs as $run) {
                    $this->observe($run);
                }
            });
    }
}
