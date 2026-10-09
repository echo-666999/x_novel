<?php

namespace App\Jobs;

use App\Actions\Generation\CheckNextAction;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Models\Chapter;
use App\Services\GenerationFailurePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** 自动续章只重试临时基础设施故障，来源不再当前时直接安全结束。 */
class ContinueAutoGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    /** 自动续章复用提交后阶段的统一最大尝试次数。 */
    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::MemorySummary);
    }

    /** 自动续章复用提交后阶段的统一退避间隔。 */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::MemorySummary);
    }

    public function __construct(
        public readonly int $chapterId,
        public readonly int $canonicalArtifactId,
        public readonly int $stateVersionId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "continue-generation:{$this->chapterId}:{$this->canonicalArtifactId}:{$this->stateVersionId}";
    }

    public function handle(CheckNextAction $checkNextAction): void
    {
        $chapter = Chapter::query()->with('novel')->find($this->chapterId);

        if ($chapter === null) {
            return;
        }
        $novel = $chapter->novel;

        if ($chapter->status !== ChapterStatus::Canonical
            || (int) $chapter->canonical_artifact_id !== $this->canonicalArtifactId
            || (int) $novel->canonical_state_version_id !== $this->stateVersionId
            || blank($chapter->summary)
            || $chapter->sequence !== $novel->current_chapter_sequence) {
            return;
        }

        try {
            $checkNextAction->handle($novel, $chapter->getKey());
        } catch (Throwable $exception) {
            if (app(GenerationFailurePolicy::class)->shouldQueueRetry($exception, GenerationStage::MemorySummary)) {
                throw $exception;
            }

            $this->fail($exception);
        }
    }
}
