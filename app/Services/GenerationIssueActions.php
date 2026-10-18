<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Support\GenerationDiagnosticRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 页面、自动恢复与人工操作共用的动作白名单和 CAS 合同。 */
final class GenerationIssueActions
{
    /** 动作白名单包含设定、产物修复与局部改写；预览和执行共用服务器校验。 */
    public function preview(GenerationIssue $issue, string $action, string $instructions = ''): array
    {
        if (! in_array($action, ['retry_export', 'retry_transient', 'repair_foundation', 'repair_book', 'rebuild_book_context', 'diagnose', 'save_note', 'pause'], true)) {
            throw new \InvalidArgumentException('动作尚未实现或不在服务器白名单中。');
        }
        $run = app(GenerationIssueService::class)->assertCurrent($issue);
        $task = NovelGenerationTask::findOrFail($issue->generation_task_id);
        $reason = $action === 'retry_export' && ($issue->category !== 'export' || ! data_get($run->execution_contract, 'book_export') || $run->status !== RunStatus::Failed) ? '只有失败的正式导出支持重新导出。' : null;
        $requestContract = null;
        if (in_array($action, ['retry_export', 'retry_transient', 'repair_foundation', 'repair_book', 'rebuild_book_context', 'diagnose'], true)) {
            $reason ??= $this->blockedReason($issue, $task, $action);
            if (! $reason && in_array($action, ['diagnose', 'repair_foundation', 'repair_book'], true)) {
                $signature = app(GenerationInputIdentity::class)->hash([$issue->fingerprint, $action, $instructions, $task->policy_revision]);
                if (collect($issue->evidence['attempts'] ?? [])->contains('signature', $signature)) {
                    $reason = '相同来源、要求与策略已经处理过；没有进展，停止重复收费。';
                }
            }
            if (! $reason && in_array($action, ['diagnose', 'repair_foundation', 'repair_book'], true)) {
                try {
                    $requestContract = $action === 'repair_book' ? app(BookChapterRepair::class)->prepare($issue, $instructions) : app(GenerationRequestContract::class)->freeze(($action === 'repair_foundation' ? app(NovelFoundationContract::class)->repairRequest($issue, $instructions) : app(GenerationIssueDiagnostic::class)->request($issue, $instructions)));
                } catch (\Throwable $exception) {
                    $reason = app(GenerationDiagnosticRedactor::class)->clean($exception->getMessage());
                }
            }
        }

        if ($action === 'rebuild_book_context' && (! in_array($issue->category, ['capacity', 'configuration', 'summary'], true) || (! data_get($run->execution_contract, 'transition.completed_run_id') && ! data_get($run->execution_contract, 'transition.canonical_chapter_id')))) {
            $reason = '只有保留成功来源的容量交接失败支持重建；请先修复模型容量设置。';
        }

        return ['fingerprint' => $issue->fingerprint, 'issue_id' => $issue->id, 'action' => $action, 'source' => $issue->source_identity,
            'control_version' => $task->control_version, 'source_run_id' => $run->id,
            'available' => $reason === null, 'reason' => $reason, 'request_contract' => $requestContract,
            'impact' => match ($action) {
                'retry_export' => '仅重建冻结的正式正文导出，不调用模型，不修改正式内容。',
                'retry_transient' => '复用原输入与冻结合同新建一次执行；成功复验后才关闭问题。',
                'rebuild_book_context' => '使用当前已授权路由重建容量准入，保留正文；不重跑规划或写作。',
                'repair_book' => '只修失败产物或有证据的局部段落；正文改变后重新提取并独立审校，保留累计次数与费用。',
                'repair_foundation' => '读取原文和失败候选，仅修复设定错误；新建候选并复验，保留原始记录和累计费用。',
                'diagnose' => '调用已授权模型，生成只读建议；不修改正文、计划、事实或上限，问题保持未解决。',
                'pause' => '暂停整书后续工作；在途响应仍可保存，禁止正式提交。',
                default => '保存处理说明和操作记录；不代表复验通过，不自动继续。',
            }, 'cost' => in_array($action, ['diagnose', 'repair_foundation', 'repair_book'], true) || ($action === 'retry_transient' && $run->provider) ? '费用待确认，发送前检查所有已启用硬上限。' : '不调用模型。'];
    }

    /** 无进展签名按问题、动作、原输入、策略及修复要求计算，重复点击不刷新额度。 */
    private function blockedReason(GenerationIssue $issue, NovelGenerationTask $task, string $action): ?string
    {
        if ($task->status === 'paused') {
            return '任务已暂停，请先明确恢复任务，再发起处理。';
        }
        if (app(GenerationIssueService::class)->hasOtherBlockers($issue)) {
            return '该任务还有其他阻塞问题，请先核对全部问题。';
        }
        if (GenerationRun::where('novel_id', $issue->novel_id)->where('pipeline_version', 'pipeline-v1')->whereIn('status', ['queued', 'running'])->exists()) {
            return '已有执行正在排队或运行，不能重复启动。';
        }
        // 容量重建不消耗 AI 修复次数，但仍必须服从暂停与同书串行边界。
        if (in_array($action, ['rebuild_book_context', 'retry_export'], true)) {
            return null;
        }
        if ($issue->attempt_count >= (int) data_get($issue->repair_limit_snapshot, 'max_attempts', 2)
            || $issue->repair_elapsed_ms >= (int) data_get($issue->repair_limit_snapshot, 'max_elapsed_ms', 1800000)
            || $issue->deadline_at?->isPast()) {
            return '问题累计次数、执行时限或截止时间已耗尽；请使用人工路径，额度不会随重试重置。';
        }
        $source = GenerationRun::findOrFail($issue->first_run_id);
        if ($action === 'repair_foundation' && ($source->stage !== GenerationStage::FoundationBlueprint
            || ! data_get($source->execution_contract, 'book_workflow') || $source->status !== RunStatus::Failed
            || ! preg_match('/^(foundation_(schema|evidence|output)|intent_hard_constraint)/', $issue->error_code)
            || ! GenerationArtifact::where('generation_run_id', $source->id)->where('type', ArtifactType::FoundationBlueprint)->exists())) {
            return '只有已保存的新版设定校验失败候选可以修复；明确矛盾须由用户选择。';
        }
        if ($action === 'retry_transient' && (! data_get($issue->evidence, 'policy.retryable') || $source->status !== RunStatus::Failed)) {
            return '该问题不是允许同合同重试的临时故障；请按具体原因处理。';
        }
        // 只向已接通的阶段派发；未来领域动作必须在接入时补充验证器与测试。
        if ($action === 'retry_transient' && ! data_get($source->execution_contract, 'book_stage') && ! in_array($source->stage, [GenerationStage::Preflight, GenerationStage::FoundationBlueprint], true)) {
            return '该阶段的新链执行器尚未接通，暂不能从此入口重试。';
        }

        return null;
    }

    /** 用户可预览本地方案；提交时再次计算，不信任前端回传的预览结果或权限。 */
    public function execute(int $issueId, string $action, string $fingerprint, int $controlVersion, string $operationKey, string $instructions = '', ?int $actorId = null, bool $dispatch = true): GenerationRun
    {
        if (! Str::isUuid($operationKey) || mb_strlen($instructions) > 6000) {
            throw new \InvalidArgumentException('操作标识无效或说明超过 6000 字。');
        }
        $initial = GenerationIssue::findOrFail($issueId);
        $run = DB::transaction(function () use ($initial, $action, $fingerprint, $controlVersion, $operationKey, $instructions, $actorId): GenerationRun {
            Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($initial->generation_task_id)->lockForUpdate()->firstOrFail();
            $issue = GenerationIssue::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $payloadHash = app(GenerationInputIdentity::class)->hash([$issue->id, $action, $fingerprint, $controlVersion, $instructions, $actorId]);
            foreach (($issue->evidence['attempts'] ?? []) as $attemptRunId => $attempt) {
                if (($attempt['operation_key'] ?? null) === $operationKey) {
                    if (($attempt['operation_hash'] ?? null) !== $payloadHash) {
                        throw new \LogicException('同一操作标识不能用于不同内容。');
                    }

                    return GenerationRun::findOrFail($attemptRunId);
                }
            }
            foreach (($issue->evidence['operations'] ?? []) as $operation) {
                if (($operation['operation_key'] ?? null) === $operationKey) {
                    if (($operation['operation_hash'] ?? null) !== $payloadHash) {
                        throw new \LogicException('相同操作标识不能用于不同内容。');
                    }

                    return GenerationRun::findOrFail($operation['run_id']);
                }
            }
            $previous = GenerationRun::where('idempotency_key', 'issue-operation:'.$operationKey)->first();
            if ($previous) {
                if (data_get($previous->execution_contract, 'operation_hash') !== $payloadHash) {
                    throw new \LogicException('同一操作标识不能用于不同内容。');
                }

                return $previous;
            }
            if (! hash_equals($issue->fingerprint, $fingerprint) || $task->control_version !== $controlVersion) {
                throw new \LogicException('预览已过期；输入已保留，请刷新差异后重新提交。');
            }
            $preview = $this->preview($issue, $action, $instructions);
            if (! $preview['available']) {
                throw new \LogicException($preview['reason']);
            }
            $source = GenerationRun::findOrFail($issue->first_run_id);
            $evidence = $issue->evidence;
            if (in_array($action, ['pause', 'save_note'], true)) {
                if ($action === 'save_note' && blank($instructions)) {
                    throw new \LogicException('请填写具体处理说明；原问题不会因保存说明而关闭。');
                }
                $run = GenerationRun::create(['novel_id' => $issue->novel_id, 'generation_task_id' => $task->id, 'issue_id' => $issue->id,
                    'stage' => GenerationStage::ChapterRecovery, 'scope_type' => 'novel', 'scope_id' => $issue->novel_id,
                    'pipeline_version' => 'pipeline-v1', 'scope_key' => 'issue:'.$issue->id, 'logical_step_key' => 'issue-operation:'.$operationKey, 'idempotency_key' => 'issue-operation:'.$operationKey,
                    'input_hash' => $payloadHash, 'execution_contract' => ['operation_hash' => $payloadHash, 'issue_action' => $action],
                    'status' => RunStatus::Succeeded, 'attempt' => 1, 'started_at' => now(), 'finished_at' => now()]);
                $data = app(GenerationDiagnosticRedactor::class)->clean(['action' => $action, 'instructions' => $instructions, 'actor_id' => $actorId,
                    'source' => $issue->source_identity, 'verification' => '未复验，保留原问题']);
                $artifact = GenerationArtifact::create(['generation_run_id' => $run->id, 'novel_id' => $issue->novel_id,
                    'type' => ArtifactType::OperationRecord, 'version' => 1, 'data' => $data,
                    'checksum' => app(GenerationInputIdentity::class)->hash($data), 'validation_status' => 'validated']);
                $run->update(['result_artifact_id' => $artifact->id]);
                $evidence['operations'][] = ['run_id' => $run->id, 'artifact_id' => $artifact->id, 'action' => $action];
                $issue->update(['evidence' => $evidence, 'last_seen_at' => now()]);
                if ($action === 'pause') {
                    app(GenerationRunCoordinator::class)->control($task->id, $controlVersion, 'pause');
                }

                return $run;
            }
            $signature = app(GenerationInputIdentity::class)->hash([$issue->fingerprint, $action, $instructions, $task->policy_revision]);
            if (in_array($action, ['diagnose', 'repair_foundation', 'repair_book'], true) && collect($evidence['attempts'] ?? [])->contains('signature', $signature)) {
                throw new \LogicException('相同来源、要求与策略已经处理过；没有进展，停止重复收费。');
            }
            $resume = $task->pending_transition;
            $task->update(['status' => 'running']);
            if ($action === 'rebuild_book_context') {
                $run = app(BookChapterRepair::class)->rebuild($issue, $task);
                $evidence['operations'][] = ['action' => $action, 'run_id' => $run->id, 'operation_key' => $operationKey, 'operation_hash' => $payloadHash];
                $issue->update(['evidence' => $evidence]);

                return $run;
            }
            if ($action === 'repair_book') {
                $contract = $preview['request_contract'];
                $phase = $contract['book_stage'];
                if ($phase === 'rewrite') {
                    $contract['repair_chain_issue_id'] = $issue->id;
                }
                $run = app(GenerationRunCoordinator::class)->enqueue($task->id, $phase === 'rewrite' ? GenerationStage::Rewrite : $source->stage, 'issue:'.$issue->id.':'.$signature, $contract, $source->source_manifest ?? []);
                $run->update(['state_version' => $source->state_version]);
            } elseif (in_array($action, ['diagnose', 'repair_foundation'], true)) {
                $contract = [...$preview['request_contract'], 'issue_action' => $action, 'issue_id' => $issue->id,
                    'resume_transition' => $resume, 'repair_source_identity' => $action === 'repair_foundation' ? $issue->source_identity : null,
                    ...array_intersect_key($source->execution_contract ?? [], array_flip(['state_version_id', 'chapter_id', 'chapter_plan_id', 'book_workflow', 'intent_artifact_id']))];
                $run = app(GenerationRunCoordinator::class)->enqueue($task->id, ($action === 'repair_foundation' ? GenerationStage::FoundationBlueprint : GenerationStage::ContentRepair), 'issue:'.$issue->id.':'.$signature, $contract, $source->source_manifest ?? []);
            } else {
                $run = app(GenerationRunCoordinator::class)->enqueue($task->id, $source->stage, $source->scope_key, $source->execution_contract ?? [], $source->source_manifest ?? []);
            }
            // operation_hash 不进入原执行合同，保持同合同重试的 input_hash 不变。
            $metadata = $run->error_metadata ?? [];
            $metadata['issue_operation'] = ['key' => $operationKey, 'hash' => $payloadHash];
            $run->update(['issue_id' => $issue->id, 'chapter_id' => $issue->chapter_id, 'error_metadata' => $metadata]);
            $evidence['attempts'][(string) $run->id] = ['action' => $action, 'signature' => $signature, 'operation_key' => $operationKey,
                'operation_hash' => $payloadHash, 'actor_id' => $actorId, 'created_at' => now()->toISOString(), 'finished_at' => null];
            $issue->update(['attempt_count' => $issue->attempt_count + 1, 'latest_run_id' => $run->id, 'evidence' => $evidence,
                'status' => $action === 'diagnose' ? 'diagnosing' : 'repairing', 'last_seen_at' => now()]);

            return $run;
        });
        if ($dispatch && $run->status === RunStatus::Queued) {
            app(GenerationJobDispatcher::class)->dispatchRun($run->id);
        }

        return $run->fresh();
    }
}
