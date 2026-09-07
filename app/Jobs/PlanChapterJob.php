<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Services\ChapterPlanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;

class PlanChapterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $chapterId, public readonly bool $regenerate = false)
    {
        $this->onQueue('generation');
    }

    public function handle(ChapterPlanner $planner): void
    {
        try {
            $planner->generate($this->chapterId, $this->regenerate);
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (ValidationException $exception) {
            $this->fail($exception);
        }
    }
}
