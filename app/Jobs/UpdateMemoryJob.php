<?php

namespace App\Jobs;

use App\Enums\GenerationStage;
use App\Models\Chapter;
use App\Services\AutoStopService;
use App\Services\GenerationFailurePolicy;
use App\Services\MemoryUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** 提交后记忆更新保持幂等，并只对临时基础设施故障执行退避重试。 */
class UpdateMemoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    /** 记忆更新复用提交后阶段的统一最大尝试次数。 */
    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::MemorySummary);
    }

    /** 记忆更新复用提交后阶段的统一退避间隔。 */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::MemorySummary);
    }

    public function __construct(public readonly int $chapterId)
    {
        $this->onQueue('default');
    }

    public function handle(MemoryUpdater $memoryUpdater, ?AutoStopService $autoStop = null): void
    {
        $autoStop ??= app(AutoStopService::class);
        $chapter = Chapter::query()->find($this->chapterId);
        if ($chapter === null) {
            return;
        }

        try {
            $memoryUpdater->update($this->chapterId);
            $autoStop->resolvePostCommitFailure($chapter->novel, 'memory');
        } catch (Throwable $exception) {
            if (app(GenerationFailurePolicy::class)->shouldQueueRetry($exception, GenerationStage::MemorySummary)) {
                throw $exception;
            }

            $autoStop->recordPostCommitFailure($chapter, 'memory', $exception);
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $chapter = Chapter::query()->find($this->chapterId);
        if ($chapter !== null) {
            app(AutoStopService::class)->recordPostCommitFailure($chapter, 'memory', $exception);
        }
    }
}
