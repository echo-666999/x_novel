<?php

namespace App\Jobs;

use App\Enums\GenerationStage;
use App\Models\Chapter;
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

    public function handle(MemoryUpdater $memoryUpdater): void
    {
        if (! Chapter::query()->whereKey($this->chapterId)->exists()) {
            return;
        }

        try {
            $memoryUpdater->update($this->chapterId);
        } catch (Throwable $exception) {
            if (app(GenerationFailurePolicy::class)->shouldQueueRetry($exception, GenerationStage::MemorySummary)) {
                throw $exception;
            }

            $this->fail($exception);
        }
    }
}
