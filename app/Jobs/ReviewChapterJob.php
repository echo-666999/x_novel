<?php

namespace App\Jobs;

use App\AI\BudgetService;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\BudgetExceededException;
use App\Enums\ArtifactType;
use App\Enums\ReviewDecision;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Models\GenerationArtifact;
use App\Models\Review;
use App\Services\AutoStopService;
use App\Services\ChapterReviewer;
use App\Services\GenerationStageGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReviewChapterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public array $backoff = [10, 30];

    public readonly string $operationId;

    public function __construct(public readonly int $chapterId, public readonly bool $regenerate = false, ?string $operationId = null)
    {
        $this->operationId = $operationId ?? (string) Str::uuid();
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    public function handle(ChapterReviewer $reviewer): void
    {
        try {
            $review = $reviewer->review($this->chapterId, $this->regenerate, $this->operationId);

            if ($review?->decision === ReviewDecision::Rewrite) {
                $this->dispatchRewrite($review);
            } elseif ($review?->decision === ReviewDecision::Pass
                && (bool) data_get($review->generationRun->novel->settings, 'auto_commit', false)) {
                app(GenerationStageGate::class)->dispatchForChapter(
                    $this->chapterId,
                    fn () => CommitChapterJob::dispatch($this->chapterId, $review->getKey()),
                );
            }

            $this->releaseGenerationDispatch();
        } catch (AiProviderException $e) {
            if (! $e->retryable) {
                if (! in_array($e->errorCode, ['novel_paused', 'review_prerequisite_missing'], true)) {
                    $reviewer->markTerminalFailure($this->chapterId);
                }
                $this->releaseGenerationDispatch();
                $this->fail($e);

                return;
            }

            throw $e;
        } catch (ValidationException $e) {
            $reviewer->markTerminalFailure($this->chapterId);
            $this->releaseGenerationDispatch();
            $this->fail($e);

            return;
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->releaseGenerationDispatch();
        if ($e instanceof AiProviderException && in_array($e->errorCode, ['novel_paused', 'review_prerequisite_missing'], true)) {
            return;
        }

        app(AutoStopService::class)->stopForFailure($this->chapterId, $e);
        app(ChapterReviewer::class)->markTerminalFailure($this->chapterId);
    }

    private function dispatchRewrite(Review $review): void
    {
        $alreadyCompleted = GenerationArtifact::query()
            ->where('type', ArtifactType::RewriteDraft)
            ->where('data->source_review_id', $review->getKey())
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
            ->exists();

        if ($alreadyCompleted) {
            return;
        }

        try {
            app(BudgetService::class)->assertWithinChapterLimits($review->generationRun->chapter);
        } catch (BudgetExceededException $exception) {
            app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);

            return;
        }

        app(GenerationStageGate::class)->dispatchForChapter(
            $this->chapterId,
            fn () => $this->dispatchGenerationJob(new RewriteChapterJob($this->chapterId)),
        );
    }
}
