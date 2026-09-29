<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Exceptions\GenerationStageDeferredException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\ChapterRewriter;
use App\Services\GenerationFailurePolicy;
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

    public int $timeout = 330;

    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::Rewrite);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::Rewrite);
    }

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
        if ($this->stopWhenChapterWasDeleted($this->chapterId)) {
            return;
        }

        $advance ??= app(AdvanceChapterPipelineAction::class);

        try {
            $artifact = $rewriter->rewrite($this->chapterId, $this->sceneId, singleProviderCall: true);
            if ($artifact === null) {
                $this->releaseGenerationDispatch();

                return;
            }
            $advance->handle($this->chapterId);
            $this->releaseGenerationDispatch();
        } catch (GenerationStageDeferredException) {
            $this->releaseGenerationDispatch();
            $advance->handle($this->chapterId);

            return;
        } catch (AiProviderException $exception) {
            $policy = app(GenerationFailurePolicy::class);
            if ($policy->shouldQueueRetry($exception, GenerationStage::Rewrite)) {
                throw $exception;
            }
            if ($policy->shouldMarkTerminal(GenerationStage::Rewrite, $exception)) {
                $rewriter->markTerminalFailure($this->chapterId);
            }
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
        if (app(GenerationFailurePolicy::class)->shouldMarkTerminal(GenerationStage::Rewrite, $exception)) {
            app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
            app(ChapterRewriter::class)->markTerminalFailure($this->chapterId);
        }
    }
}
