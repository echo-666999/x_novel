<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\ChapterReviewer;
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

    public int $timeout = 330;

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

    public function handle(ChapterReviewer $reviewer, ?AdvanceChapterPipelineAction $advance = null): void
    {
        $advance ??= app(AdvanceChapterPipelineAction::class);

        try {
            $review = $reviewer->review($this->chapterId, $this->regenerate, $this->operationId);

            if ($review !== null) {
                $advance->handle($this->chapterId);
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
        } catch (Throwable $e) {
            $this->handleUnexpectedGenerationFailure($e);
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
}
