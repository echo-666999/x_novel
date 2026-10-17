<?php

namespace Tests\Fixtures;

use App\Enums\ArtifactType;
use App\Services\GenerationRunCoordinator;
use App\Support\IsolatedTestEnvironment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** 专属子进程故障夹具；只允许隔离库，退出不能影响运行中的业务 Worker。 */
class ExitAfterClaimJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $runId, public bool $saveResult = false) {}

    public function handle(GenerationRunCoordinator $coordinator): void
    {
        if (DB::scalar('SELECT current_database()') !== IsolatedTestEnvironment::DATABASE || ! app()->environment('testing')) {
            throw new \LogicException('故障注入仅允许隔离测试。');
        }
        $lease = $coordinator->claimExecution($this->runId);
        if ($lease && $this->saveResult) {
            $coordinator->completeExecution($this->runId, $lease['token'], $lease['control_version'], ArtifactType::OperationRecord,
                ['fixture' => 'saved-before-exit'], next: ['stage' => 'preflight', 'scope' => 'after-checkpoint', 'contract' => []]);
        }
        exit(17);
    }
}
