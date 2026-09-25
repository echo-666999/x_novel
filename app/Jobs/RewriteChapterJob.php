<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\ChapterRewriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RewriteChapterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 330;

    public array $backoff = [10, 30];

    public function __construct(public readonly int $chapterId, public readonly ?int $sceneId = null)
    {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    public function handle(ChapterRewriter $rewriter, ?AdvanceChapterPipelineAction $advance = null): void
    {
        $advance ??= app(AdvanceChapterPipelineAction::class);

        try {
            $artifact = $rewriter->rewrite($this->chapterId, $this->sceneId);
            if ($artifact === null) {
                $this->releaseGenerationDispatch();

                return;
            }
            $advance->handle($this->chapterId);
            $this->releaseGenerationDispatch();
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                if (! in_array($exception->errorCode, ['novel_paused', 'rewrite_exhausted'], true)) {
                    $rewriter->markTerminalFailure($this->chapterId);
                }
                $this->releaseGenerationDispatch();
                $this->fail($exception);

                return;
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->handleUnexpectedGenerationFailure($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
        if ($exception instanceof AiProviderException && in_array($exception->errorCode, ['novel_paused', 'rewrite_exhausted'], true)) {
            return;
        }
        app(ChapterRewriter::class)->markTerminalFailure($this->chapterId);
    }
}
