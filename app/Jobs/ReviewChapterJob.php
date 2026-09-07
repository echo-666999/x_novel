<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Services\ChapterReviewer;
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
            $reviewer->review($this->chapterId, $this->regenerate);
        } catch (AiProviderException $e) {
            if (! $e->retryable) {
                if ($e->errorCode !== 'novel_paused') {
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
        app(ChapterReviewer::class)->markTerminalFailure($this->chapterId);
    }
}
