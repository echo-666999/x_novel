<?php

namespace App\Jobs\Concerns;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Enums\GenerationStage;
use App\Exceptions\ChapterPipelineProgressionException;
use App\Models\Chapter;
use App\Models\Scene;
use App\Services\AutoStopService;
use App\Services\GenerationFailurePolicy;
use App\Services\GenerationJobDispatcher;
use App\Services\GenerationProgressionFailureRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

trait PreventsDuplicateGeneration
{
    public function uniqueFor(): int
    {
        return (int) config('generation.pending_job_seconds', 900);
    }

    protected function releaseGenerationDispatch(): void
    {
        app(GenerationJobDispatcher::class)->release($this);
    }

    protected function stopWhenChapterWasDeleted(int $chapterId): bool
    {
        if (Chapter::query()->whereKey($chapterId)->exists()) {
            return false;
        }

        $this->releaseGenerationDispatch();

        return true;
    }

    protected function stopWhenSceneWasDeleted(int $sceneId): bool
    {
        if (Scene::query()->whereKey($sceneId)->exists()) {
            return false;
        }

        $this->releaseGenerationDispatch();

        return true;
    }

    protected function dispatchGenerationJob(ShouldQueue&ShouldBeUnique $job): bool
    {
        return app(GenerationJobDispatcher::class)->dispatch($job);
    }

    protected function handleUnexpectedGenerationFailure(Throwable $exception): void
    {
        $failure = app(GenerationFailurePolicy::class)->fromException($exception, 'generation_code_failure');

        if ($failure->retryable) {
            throw $exception;
        }

        $this->releaseGenerationDispatch();
        $this->fail($exception);
    }

    protected function advanceAfterSuccessfulStage(
        AdvanceChapterPipelineAction $advance,
        GenerationStage $sourceStage,
        int $chapterId,
        ?int $sceneId = null,
        ?int $generationRunId = null,
        ?string $regenerationBatchId = null,
    ): bool {
        $recorder = app(GenerationProgressionFailureRecorder::class);

        try {
            if ($regenerationBatchId === null) {
                $advance->handle($chapterId);
            } else {
                $advance->handle($chapterId, $regenerationBatchId);
            }
            $recorder->resolve($sourceStage, $chapterId, $sceneId, $generationRunId);

            return true;
        } catch (Throwable $exception) {
            $recorder->record($sourceStage, $chapterId, $exception, $sceneId, $generationRunId);

            // 队列任务必须失败并停止自动推进，但不能触发当前成功阶段的终态失败处理。
            $this->releaseGenerationDispatch();
            $progressionException = new ChapterPipelineProgressionException($sourceStage, $exception);
            app(AutoStopService::class)->stopForFailure($chapterId, $progressionException);
            $this->fail($progressionException);

            return false;
        }
    }
}
