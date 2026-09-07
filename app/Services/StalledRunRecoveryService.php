<?php

namespace App\Services;

use App\Actions\Generation\PauseGenerationAction;
use App\Data\ResumePoint;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StalledRunRecoveryService
{
    public const ERROR_CODE = 'worker_lost';

    private const RECOVERABLE_STAGES = [
        GenerationStage::ChapterPlanning,
        GenerationStage::SceneGeneration,
        GenerationStage::ChapterAssembly,
        GenerationStage::EventExtraction,
        GenerationStage::Review,
        GenerationStage::Rewrite,
        GenerationStage::Commit,
    ];

    public function __construct(
        private readonly PauseGenerationAction $pauseGeneration,
        private readonly ResumeResolver $resumeResolver,
    ) {}

    public function markStalledRuns(): int
    {
        return GenerationRun::query()
            ->where('status', RunStatus::Running)
            ->whereIn('stage', self::RECOVERABLE_STAGES)
            ->where('updated_at', '<=', $this->cutoff())
            ->update([
                'status' => RunStatus::Failed,
                'error_code' => self::ERROR_CODE,
                'error_message' => 'Worker 心跳超时，Run 已标记为可恢复。',
                'finished_at' => now(),
            ]);
    }

    public function isStalled(GenerationRun $run): bool
    {
        return $run->status === RunStatus::Running
            && $run->updated_at !== null
            && $run->updated_at->lte($this->cutoff());
    }

    public function recover(GenerationRun $run): ResumePoint
    {
        $run = $this->claimWorkerLost($run);
        $novel = $run->novel()->firstOrFail();

        try {
            if ($novel->status !== NovelStatus::Paused) {
                $this->pauseGeneration->handle($novel);
            }

            $point = $this->resumeResolver->resume($novel->refresh());
        } catch (\Throwable $exception) {
            $this->releaseClaim($run);

            throw $exception;
        }

        $snapshot = $run->fresh()->context_snapshot ?? [];
        $snapshot['recovery']['resumed_at'] = now()->toISOString();
        $run->update(['context_snapshot' => $snapshot]);

        return $point;
    }

    private function claimWorkerLost(GenerationRun $run): GenerationRun
    {
        return DB::transaction(function () use ($run): GenerationRun {
            $lockedRun = GenerationRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if (data_get($lockedRun->context_snapshot, 'recovery.claimed_at') !== null) {
                throw ValidationException::withMessages(['run' => '该 Worker 丢失任务已经恢复或正在恢复。']);
            }

            if (GenerationRun::query()
                ->where('stage', $lockedRun->stage)
                ->where('scope_type', $lockedRun->scope_type)
                ->where('scope_id', $lockedRun->scope_id)
                ->where('id', '>', $lockedRun->getKey())
                ->exists()) {
                throw ValidationException::withMessages(['run' => '该范围已有更新的 Generation Run，不能恢复旧 Run。']);
            }

            if ($this->isStalled($lockedRun)) {
                $lockedRun->update([
                    'status' => RunStatus::Failed,
                    'error_code' => self::ERROR_CODE,
                    'error_message' => 'Worker 心跳超时，Run 已标记为可恢复。',
                    'finished_at' => now(),
                ]);

                $lockedRun->refresh();
            }

            if ($lockedRun->status !== RunStatus::Failed || $lockedRun->error_code !== self::ERROR_CODE) {
                throw ValidationException::withMessages([
                    'run' => '只有已停滞或标记为 Worker 丢失的 Run 可以恢复。',
                ]);
            }

            $snapshot = $lockedRun->context_snapshot ?? [];
            $snapshot['recovery']['claimed_at'] = now()->toISOString();
            $lockedRun->update(['context_snapshot' => $snapshot]);

            return $lockedRun;
        });
    }

    private function releaseClaim(GenerationRun $run): void
    {
        $snapshot = $run->fresh()->context_snapshot ?? [];
        unset($snapshot['recovery']['claimed_at']);
        if (($snapshot['recovery'] ?? []) === []) {
            unset($snapshot['recovery']);
        }
        $run->update(['context_snapshot' => $snapshot]);
    }

    private function cutoff(): Carbon
    {
        return now()->subSeconds((int) config('generation.stalled_run_after_seconds', 300));
    }
}
