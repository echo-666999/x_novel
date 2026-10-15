<?php

namespace App\Jobs;

use App\Enums\GenerationStage;
use App\Models\Chapter;
use App\Models\Novel;
use App\Services\AutoStopService;
use App\Services\GenerationFailurePolicy;
use App\Services\ProjectionRebuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** 提交后投影刷新只重试临时基础设施故障，过期来源仍直接安全结束。 */
class RefreshNovelProjectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    /** 投影刷新复用提交后阶段的统一最大尝试次数。 */
    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::MemorySummary);
    }

    /** 投影刷新复用提交后阶段的统一退避间隔。 */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::MemorySummary);
    }

    public function __construct(
        public readonly int $novelId,
        public readonly int $stateVersionId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "novel:{$this->novelId}:state:{$this->stateVersionId}";
    }

    public function handle(ProjectionRebuilder $rebuilder, ?AutoStopService $autoStop = null): void
    {
        $autoStop ??= app(AutoStopService::class);
        $novel = Novel::query()->find($this->novelId);

        if ($novel === null) {
            return;
        }

        if ((int) $novel->canonical_state_version_id !== $this->stateVersionId) {
            return;
        }

        try {
            $rebuilder->rebuild($novel);
            $autoStop->resolvePostCommitFailure($novel, 'projection');
        } catch (Throwable $exception) {
            if (app(GenerationFailurePolicy::class)->shouldQueueRetry($exception, GenerationStage::MemorySummary)) {
                throw $exception;
            }

            $chapter = $this->sourceChapter($novel);
            if ($chapter !== null) {
                $autoStop->recordPostCommitFailure($chapter, 'projection', $exception);
            }
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $novel = Novel::query()->find($this->novelId);
        $chapter = $novel === null ? null : $this->sourceChapter($novel);
        if ($chapter !== null) {
            app(AutoStopService::class)->recordPostCommitFailure($chapter, 'projection', $exception);
        }
    }

    private function sourceChapter(Novel $novel): ?Chapter
    {
        return $novel->storyStateVersions()
            ->whereKey($this->stateVersionId)
            ->first()?->chapter;
    }
}
