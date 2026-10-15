<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Models\GenerationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
        $now = now();
        $run->update([
            'heartbeat_at' => $now,
            'lease_expires_at' => $now->copy()->addSeconds($this->runningSeconds()),
        ]);
    }

    public function release(GenerationRun $run): void
    {
        $run->update([
            'lease_expires_at' => null,
            'lease_token' => null,
            'reserved_cost' => 0,
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
