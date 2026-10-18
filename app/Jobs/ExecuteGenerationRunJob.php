<?php

namespace App\Jobs;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Models\GenerationRun;
use App\Services\GenerationFailurePolicy;
use App\Services\GenerationIssueDiagnostic;
use App\Services\GenerationRunCoordinator;
use App\Services\NovelBookWorkflow;
use App\Services\NovelFoundationContract;
use App\Support\GenerationDiagnosticRedactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** 新链 Job 只接收持久 Run 身份；重投不产生第二次认领或模型请求。 */
class ExecuteGenerationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 330;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('generation');
    }

    public function handle(GenerationRunCoordinator $coordinator): void
    {
        $lease = $coordinator->claimExecution($this->runId);
        if ($lease === null) {
            return;
        }
        $run = GenerationRun::findOrFail($this->runId);
        try {
            // 读取实际生效配置，防止.env覆盖让Worker尚未退出就重投同一请求。
            $workerTimeout = (int) config('horizon.defaults.supervisor-1.timeout', 350);
            $leaseSeconds = (int) config('generation.execution.lease_seconds', 390);
            $retryAfter = (int) config('queue.connections.redis.retry_after', 480);
            if ($workerTimeout < $this->timeout || $leaseSeconds <= $workerTimeout || $retryAfter <= $leaseSeconds) {
                throw new \LogicException('执行时限配置冲突：要求 Job timeout ≤ Horizon timeout < Run lease < Redis retry_after，请检查生效配置。');
            }
            // R02 只读诊断复用同一租约、请求费用和来源边界，不执行模型建议。
            if (data_get($run->execution_contract, 'issue_action') === 'diagnose') {
                app(GenerationIssueDiagnostic::class)->execute($run, $lease);

                return;
            }
            // 新书入口使用同一执行租约；成功后推进持久后继，崩溃时由扫描补偿。
            if (data_get($run->execution_contract, 'book_workflow') === 1) {
                if ($run->stage === GenerationStage::Preflight) {
                    app(NovelBookWorkflow::class)->preflight($run, $lease);
                } elseif ($run->stage === GenerationStage::FoundationBlueprint) {
                    app(NovelFoundationContract::class)->execute($run, $lease);
                } else {
                    throw new \LogicException('新版创作阶段尚未接入。');
                }
                app(NovelBookWorkflow::class)->advance($run->generation_task_id);

                return;
            }
            // R01 只接通执行基础，后续创作阶段由对应批次接入，绝不返回伪造正文。
            if ($run->stage !== GenerationStage::Preflight) {
                throw new \LogicException('该创作阶段尚未接入新版合同，请完成相应实施批次。');
            }
            $coordinator->completeExecution($run->id, $lease['token'], $lease['control_version'], ArtifactType::OperationRecord,
                ['checks' => ['task_identity' => 'valid', 'sources' => 'valid'], 'scope' => 'execution_foundation']);
        } catch (Throwable $exception) {
            $failure = app(GenerationFailurePolicy::class)->fromException($exception, 'stage_execution_failed');
            $coordinator->failExecution($run->id, $lease['token'], $failure->code, $failure->message, $failure->retryable, $failure->metadata);
            // 原异常位置可追踪，但不把包含请求凭据的原始异常文本直接输出日志。
            Log::error('新链阶段执行失败', [
                'run_id' => $run->id, 'exception_class' => $exception::class, 'file' => $exception->getFile(), 'line' => $exception->getLine(),
                'error_code' => $failure->code, 'message' => app(GenerationDiagnosticRedactor::class)->clean($failure->message),
            ]);
        }
    }
}
