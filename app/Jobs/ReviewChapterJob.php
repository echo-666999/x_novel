<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\ChapterReviewer;
use App\Services\GenerationFailurePolicy;
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

    public int $timeout = 330;

    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::Review);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::Review);
    }

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
            $policy = app(GenerationFailurePolicy::class);
            if ($policy->shouldQueueRetry($e, GenerationStage::Review)) {
                throw $e;
            }

            if ($policy->shouldMarkTerminal(GenerationStage::Review, $e)) {
                $reviewer->markTerminalFailure($this->chapterId);
            }
            $this->releaseGenerationDispatch();
            $this->fail($e);

            return;
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
        if (app(GenerationFailurePolicy::class)->shouldMarkTerminal(GenerationStage::Review, $e)) {
            app(AutoStopService::class)->stopForFailure($this->chapterId, $e);
            app(ChapterReviewer::class)->markTerminalFailure($this->chapterId);
        }
    }
}
