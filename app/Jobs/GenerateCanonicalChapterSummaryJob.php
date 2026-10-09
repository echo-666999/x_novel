<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Models\Chapter;
use App\Services\CanonicalChapterSummaryService;
use App\Services\GenerationFailurePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateCanonicalChapterSummaryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    /** 摘要生成复用提交后阶段的统一最大尝试次数。 */
    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::MemorySummary);
    }

    /** 摘要生成复用提交后阶段的统一退避间隔。 */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::MemorySummary);
    }

    public function __construct(
        public readonly int $chapterId,
        public readonly int $canonicalArtifactId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "chapter-summary:{$this->chapterId}:{$this->canonicalArtifactId}";
    }

    public function handle(CanonicalChapterSummaryService $summaries): void
    {
        $chapter = Chapter::query()->find($this->chapterId);

        if ($chapter === null) {
            return;
        }

        if ((int) $chapter->canonical_artifact_id !== $this->canonicalArtifactId) {
            return;
        }

        try {
            $summaries->generate($this->chapterId);
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (ValidationException $exception) {
            $this->fail($exception);
        } catch (Throwable $exception) {
            $failure = app(GenerationFailurePolicy::class)
                ->fromException($exception, 'summary_code_failure');

            if ($failure->retryable) {
                throw $exception;
            }

            $this->fail($exception);
        }
    }
}
