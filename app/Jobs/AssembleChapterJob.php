<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\ChapterAssembler;
use App\Services\GenerationFailurePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AssembleChapterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $timeout = 330;

    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::ChapterAssembly);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::ChapterAssembly);
    }

    public function __construct(public readonly int $chapterId, public readonly bool $regenerate = false, public readonly bool $continueRewrite = false)
    {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    public function handle(ChapterAssembler $assembler, ?AdvanceChapterPipelineAction $advance = null): void
    {
        if ($this->stopWhenChapterWasDeleted($this->chapterId)) {
            return;
        }

        $advance ??= app(AdvanceChapterPipelineAction::class);

        try {
            $artifact = $assembler->assemble($this->chapterId, $this->regenerate);
            if ($artifact !== null) {
                $advance->handle($this->chapterId);
            }

            $this->releaseGenerationDispatch();
        } catch (AiProviderException $exception) {
            $policy = app(GenerationFailurePolicy::class);
            if ($policy->shouldQueueRetry($exception, GenerationStage::ChapterAssembly)) {
                throw $exception;
            }

            if ($policy->shouldMarkTerminal(GenerationStage::ChapterAssembly, $exception)) {
                $assembler->markTerminalFailure($this->chapterId);
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
        app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
        if (app(GenerationFailurePolicy::class)->shouldMarkTerminal(GenerationStage::ChapterAssembly, $exception)) {
            app(ChapterAssembler::class)->markTerminalFailure($this->chapterId);
        }
    }
}
