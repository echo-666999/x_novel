<?php

namespace App\Jobs;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Models\GenerationRun;
use App\Services\GenerationRunCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
            // R01 只接通执行基础，后续创作阶段由对应批次接入，绝不返回伪造正文。
            if ($run->stage !== GenerationStage::Preflight) {
                throw new \LogicException('该创作阶段尚未接入新版合同，请完成相应实施批次。');
            }
            $coordinator->completeExecution($run->id, $lease['token'], $lease['control_version'], ArtifactType::OperationRecord,
                ['checks' => ['task_identity' => 'valid', 'sources' => 'valid'], 'scope' => 'execution_foundation']);
        } catch (Throwable $exception) {
            $coordinator->failExecution($run->id, $lease['token'], 'stage_execution_failed', $exception->getMessage(), false);
            report($exception);
        }
    }
}
