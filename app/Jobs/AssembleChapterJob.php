<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\ChapterAssembler;
use App\Services\GenerationStageGate;
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

    public int $tries = 3;

    public int $timeout = 180;

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

    public function handle(ChapterAssembler $assembler): void
    {
        try {
            $artifact = $assembler->assemble($this->chapterId, $this->regenerate);
            if ($artifact !== null && $this->continueRewrite) {
                app(GenerationStageGate::class)->dispatchForChapter(
                    $this->chapterId,
                    fn () => $this->dispatchGenerationJob(new ExtractStoryEventsJob($this->chapterId, true, true)),
                );
            }

            $this->releaseGenerationDispatch();
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                if (! in_array($exception->errorCode, [
                    'novel_paused',
                    'assembly_input_incomplete',
                    'assembly_scene_incomplete',
                    'assembly_context_incomplete',
                ], true)) {
                    $assembler->markTerminalFailure($this->chapterId);
                }

                $this->releaseGenerationDispatch();
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
        app(ChapterAssembler::class)->markTerminalFailure($this->chapterId);
    }
}
