<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Services\ChapterRewriter;
use App\Services\GenerationStageGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RewriteChapterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [10, 30];

    public function __construct(public readonly int $chapterId, public readonly ?int $sceneId = null)
    {
        $this->onQueue('generation');
    }

    public function handle(ChapterRewriter $rewriter): void
    {
        try {
            $artifact = $rewriter->rewrite($this->chapterId, $this->sceneId);
            if ($artifact === null) {
                return;
            }
            app(GenerationStageGate::class)->dispatchForChapter($this->chapterId, function (): void {
                if ($this->sceneId === null) {
                    ExtractStoryEventsJob::dispatch($this->chapterId, true, true);
                } else {
                    AssembleChapterJob::dispatch($this->chapterId, true, true);
                }
            });
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                if (! in_array($exception->errorCode, ['novel_paused', 'rewrite_exhausted'], true)) {
                    $rewriter->markTerminalFailure($this->chapterId);
                }
                $this->fail($exception);

                return;
            }
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof AiProviderException && in_array($exception->errorCode, ['novel_paused', 'rewrite_exhausted'], true)) {
            return;
        }
        app(ChapterRewriter::class)->markTerminalFailure($this->chapterId);
    }
}
