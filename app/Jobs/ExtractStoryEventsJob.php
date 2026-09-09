<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\GenerationStageGate;
use App\Services\StatePatchBuilder;
use App\Services\StoryEventExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExtractStoryEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $chapterId, public readonly bool $regenerate = false, public readonly bool $continueRewrite = false)
    {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    public function handle(StoryEventExtractor $extractor): void
    {
        try {
            $artifact = $extractor->extract($this->chapterId, $this->regenerate);
            if ($artifact !== null && $this->continueRewrite) {
                app(GenerationStageGate::class)->dispatchForChapter($this->chapterId, function (): void {
                    app(StatePatchBuilder::class)->build($this->chapterId);
                    $this->dispatchGenerationJob(new ReviewChapterJob($this->chapterId, true));
                });
            }

            $this->releaseGenerationDispatch();
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                if ($exception->errorCode !== 'novel_paused') {
                    $extractor->markTerminalFailure($this->chapterId);
                }

                $this->releaseGenerationDispatch();
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (ValidationException $exception) {
            $extractor->markTerminalFailure($this->chapterId);
            $this->releaseGenerationDispatch();
            $this->fail($exception);

            return;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
        app(StoryEventExtractor::class)->markTerminalFailure($this->chapterId);
    }
}
