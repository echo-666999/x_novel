<?php

namespace App\Services;

use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GenerationProgressionFailureRecorder
{
    public const ERROR_CODE = 'pipeline_progression_failed';

    public function __construct(private readonly GenerationFailurePolicy $failurePolicy) {}

    public function record(
        GenerationStage $sourceStage,
        int $chapterId,
        Throwable $exception,
        ?int $sceneId = null,
        ?int $generationRunId = null,
    ): ?GenerationRun {
        return DB::transaction(function () use ($sourceStage, $chapterId, $exception, $sceneId, $generationRunId): ?GenerationRun {
            $run = $this->findSucceededRun($sourceStage, $chapterId, $sceneId, $generationRunId, true);
            if ($run === null) {
                return null;
            }

            $failure = $this->failurePolicy->fromException($exception, self::ERROR_CODE);
            $current = is_array($run->progression_failure) ? $run->progression_failure : [];
            $failedAt = now()->toISOString();

            // 阶段产物已经成功，不能把 Run 改成 failed；推进异常作为独立事实保存，供恢复和页面诊断。
            $run->update([
                'progression_failure' => [
                    'code' => self::ERROR_CODE,
                    'cause_code' => $failure->code === self::ERROR_CODE ? null : $failure->code,
                    'message' => $failure->message,
                    'exception_class' => $exception::class,
                    'retryable' => $failure->retryable,
                    'category' => data_get($failure->metadata, 'category', 'manual_attention'),
                    'recommended_action' => $failure->retryable
                        ? '重试流程推进'
                        : '修复推进条件后继续流水线',
                    'source_stage' => $sourceStage->value,
                    'first_failed_at' => data_get($current, 'first_failed_at', $failedAt),
                    'failed_at' => $failedAt,
                    'occurrences' => max(0, (int) data_get($current, 'occurrences', 0)) + 1,
                    'resolved_at' => null,
                ],
            ]);

            return $run->refresh();
        });
    }

    public function resolve(
        GenerationStage $sourceStage,
        int $chapterId,
        ?int $sceneId = null,
        ?int $generationRunId = null,
    ): ?GenerationRun {
        return DB::transaction(function () use ($sourceStage, $chapterId, $sceneId, $generationRunId): ?GenerationRun {
            $run = $this->findSucceededRun($sourceStage, $chapterId, $sceneId, $generationRunId, true);
            $failure = $run?->progression_failure;
            if ($run === null || ! is_array($failure) || filled(data_get($failure, 'resolved_at'))) {
                return $run;
            }

            // 保留历史原因，只标记本次推进已经恢复，避免页面继续把旧故障当作当前阻断。
            $run->update(['progression_failure' => [...$failure, 'resolved_at' => now()->toISOString()]]);

            return $run->refresh();
        });
    }

    private function findSucceededRun(
        GenerationStage $sourceStage,
        int $chapterId,
        ?int $sceneId,
        ?int $generationRunId,
        bool $lock,
    ): ?GenerationRun {
        $query = GenerationRun::query()
            ->where('chapter_id', $chapterId)
            ->where('stage', $sourceStage)
            ->where('status', RunStatus::Succeeded)
            ->when($sceneId !== null, fn ($query) => $query->where('scene_id', $sceneId))
            ->when($generationRunId !== null, fn ($query) => $query->whereKey($generationRunId))
            ->latest('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
