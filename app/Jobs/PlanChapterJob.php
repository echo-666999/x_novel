<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Exceptions\GenerationPreflightException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\ChapterPlanner;
use App\Services\GenerationFailurePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlanChapterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $timeout = 330;

    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::ChapterPlanning);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::ChapterPlanning);
    }

    public function __construct(public readonly int $chapterId, public readonly bool $regenerate = false)
    {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    public function handle(ChapterPlanner $planner, ?AdvanceChapterPipelineAction $advance = null): void
    {
        if ($this->stopWhenChapterWasDeleted($this->chapterId)) {
            return;
        }

        $advance ??= app(AdvanceChapterPipelineAction::class);

        try {
            $plan = $planner->generate($this->chapterId, $this->regenerate);
            if ($plan !== null) {
                if (! $this->advanceAfterSuccessfulStage(
                    $advance,
                    GenerationStage::ChapterPlanning,
                    $this->chapterId,
                )) {
                    return;
                }
            }
        } catch (AiProviderException $exception) {
            if (app(GenerationFailurePolicy::class)->shouldQueueRetry($exception, GenerationStage::ChapterPlanning)) {
                throw $exception;
            }

            $this->releaseGenerationDispatch();
            $this->fail($exception);

            return;
        } catch (GenerationPreflightException|ValidationException $exception) {
            $this->releaseGenerationDispatch();
            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            $this->handleUnexpectedGenerationFailure($exception);

            return;
        }

        $this->releaseGenerationDispatch();
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
    }
}
