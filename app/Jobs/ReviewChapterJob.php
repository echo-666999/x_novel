<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Enums\ReviewDecision;
use App\Services\AutoStopService;
use App\Services\ChapterReviewer;
use App\Services\GenerationStageGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReviewChapterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public array $backoff = [10, 30];

    public function __construct(public readonly int $chapterId, public readonly bool $regenerate = false)
    {
        $this->onQueue('generation');
    }

    public function handle(ChapterReviewer $reviewer): void
    {
        try {
            $review = $reviewer->review($this->chapterId, $this->regenerate);

            if ($review?->decision === ReviewDecision::Pass
                && (bool) data_get($review->generationRun->novel->settings, 'auto_commit', false)) {
                app(GenerationStageGate::class)->dispatchForChapter(
                    $this->chapterId,
                    fn () => CommitChapterJob::dispatch($this->chapterId, $review->getKey()),
                );
            }
        } catch (AiProviderException $e) {
            if (! $e->retryable) {
                if (! in_array($e->errorCode, ['novel_paused', 'review_prerequisite_missing'], true)) {
                    $reviewer->markTerminalFailure($this->chapterId);
                } $this->fail($e);

                return;
            } throw $e;
        } catch (ValidationException $e) {
            $reviewer->markTerminalFailure($this->chapterId);
            $this->fail($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($e instanceof AiProviderException && in_array($e->errorCode, ['novel_paused', 'review_prerequisite_missing'], true)) {
            return;
        }

        app(AutoStopService::class)->stopForFailure($this->chapterId, $e);
        app(ChapterReviewer::class)->markTerminalFailure($this->chapterId);
    }
}
