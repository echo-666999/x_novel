<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Services\ChapterAssembler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AssembleChapterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $chapterId, public readonly bool $regenerate = false, public readonly bool $continueRewrite = false)
    {
        $this->onQueue('generation');
    }

    public function handle(ChapterAssembler $assembler): void
    {
        try {
            $artifact = $assembler->assemble($this->chapterId, $this->regenerate);
            if ($artifact !== null && $this->continueRewrite) {
                ExtractStoryEventsJob::dispatch($this->chapterId, true, true);
            }
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

                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ChapterAssembler::class)->markTerminalFailure($this->chapterId);
    }
}
