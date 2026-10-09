<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Exceptions\GenerationStageDeferredException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\GenerationFailurePolicy;
use App\Services\StoryEventExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

/** deferred 续跑会在当前 Job 内派发同类任务，因此唯一锁必须在开始处理时释放。 */
class ExtractStoryEventsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $timeout = 330;

    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::EventExtraction);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::EventExtraction);
    }

    public function __construct(
        public readonly int $chapterId,
        public readonly bool $regenerate = false,
        public readonly bool $continueRewrite = false,
        public readonly ?int $recoveryRunId = null,
    ) {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    public function handle(StoryEventExtractor $extractor, ?AdvanceChapterPipelineAction $advance = null): void
    {
        if ($this->stopWhenChapterWasDeleted($this->chapterId)) {
            return;
        }

        $advance ??= app(AdvanceChapterPipelineAction::class);

        try {
            $artifact = $extractor->extract(
                $this->chapterId,
                $this->regenerate,
                singleProviderCall: true,
                recoveryRunId: $this->recoveryRunId,
            );
            if ($artifact !== null) {
                if (! $this->advanceAfterSuccessfulStage(
                    $advance,
                    GenerationStage::EventExtraction,
                    $this->chapterId,
                    generationRunId: $artifact->generation_run_id,
                )) {
                    return;
                }
            }

            $this->releaseGenerationDispatch();
        } catch (GenerationStageDeferredException) {
            $this->releaseGenerationDispatch();
            $advance->handle($this->chapterId);

            return;
        } catch (AiProviderException $exception) {
            $policy = app(GenerationFailurePolicy::class);
            if ($policy->shouldQueueRetry($exception, GenerationStage::EventExtraction)) {
                throw $exception;
            }

            if ($policy->shouldMarkTerminal(GenerationStage::EventExtraction, $exception)) {
                $extractor->markTerminalFailure($this->chapterId);
            }

            $this->releaseGenerationDispatch();
            $this->fail($exception);

            return;
        } catch (ValidationException $exception) {
            $extractor->markTerminalFailure($this->chapterId);
            $this->releaseGenerationDispatch();
            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            $this->handleUnexpectedGenerationFailure($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
        if (app(GenerationFailurePolicy::class)->shouldMarkTerminal(GenerationStage::EventExtraction, $exception)) {
            app(StoryEventExtractor::class)->markTerminalFailure($this->chapterId);
        }
    }
}
