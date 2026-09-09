<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\ChapterPlanner;
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

    public int $tries = 3;

    public int $timeout = 90;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $chapterId, public readonly bool $regenerate = false)
    {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    public function handle(ChapterPlanner $planner): void
    {
        try {
            $planner->generate($this->chapterId, $this->regenerate);
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                $this->releaseGenerationDispatch();
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (ValidationException $exception) {
            $this->releaseGenerationDispatch();
            $this->fail($exception);

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
