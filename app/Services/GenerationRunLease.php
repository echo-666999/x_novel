<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Models\GenerationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** 旧入口的租约工具；新版任务必须由 Coordinator 在任务锁下认领。 */
class GenerationRunLease
{
    public function isFresh(?GenerationRun $run): bool
    {
        if ($run === null) {
            return false;
        }

        return $run->lease_expires_at !== null
            ? $run->lease_expires_at->isFuture()
            : ($run->updated_at?->gt($this->cutoff()) ?? false);
    }

    public function isExpired(?GenerationRun $run): bool
    {
        return $run !== null && ! $this->isFresh($run);
    }

    public function cutoff(): Carbon
    {
        return now()->subSeconds((int) config('generation.stalled_run_after_seconds', 300));
    }

    public function queue(GenerationRun $run): GenerationRun
    {
        if ($run->pipeline_version === 'pipeline-v1') {
            throw new \LogicException('新版 Run 必须通过 GenerationRunCoordinator 管理执行权。');
        }
        $now = now();
        $run->update([
            'status' => RunStatus::Queued,
            'queued_at' => $run->queued_at ?? $now,
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => $now->copy()->addSeconds($this->queuedSeconds()),
            'heartbeat_at' => null,
        ]);

        return $run->refresh();
    }

    public function claim(GenerationRun $run): GenerationRun
    {
        if ($run->pipeline_version === 'pipeline-v1') {
            throw new \LogicException('新版 Run 必须通过 GenerationRunCoordinator 管理执行权。');
        }
        $now = now();
        $run->update([
            'status' => RunStatus::Running,
            'started_at' => $run->started_at ?? $now,
            'heartbeat_at' => $now,
            'lease_expires_at' => $now->copy()->addSeconds($this->runningSeconds()),
        ]);

        return $run->refresh();
    }

    public function heartbeat(GenerationRun $run): void
    {
        if ($run->pipeline_version === 'pipeline-v1') {
            throw new \LogicException('新版 Run 必须通过 GenerationRunCoordinator 管理执行权。');
        }
        $now = now();
        $run->update([
            'heartbeat_at' => $now,
            'lease_expires_at' => $now->copy()->addSeconds($this->runningSeconds()),
        ]);
    }

    public function release(GenerationRun $run): void
    {
        if ($run->pipeline_version === 'pipeline-v1') {
            throw new \LogicException('新版 Run 必须通过 Coordinator 核实执行权后释放。');
        }
        $run->update([
            'lease_expires_at' => null,
            'lease_token' => null,
            // 释放 Worker 租约不代表供应商未收费，不再清空金额占用。
        ]);
    }

    private function queuedSeconds(): int
    {
        return max(30, (int) config('generation.queued_run_lease_seconds', 300));
    }

    private function runningSeconds(): int
    {
        return max(30, (int) config('generation.stalled_run_after_seconds', 480));
    }
}
