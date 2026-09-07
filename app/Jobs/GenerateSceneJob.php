<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Services\SceneGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateSceneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $sceneId, public readonly bool $regenerate = false)
    {
        $this->onQueue('generation');
    }

    public function handle(SceneGenerator $generator): void
    {
        try {
            $generator->generate($this->sceneId, $this->regenerate);
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                if (! in_array($exception->errorCode, ['novel_paused', 'previous_scene_incomplete'], true)) {
                    $generator->markTerminalFailure($this->sceneId, $exception);
                }

                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(SceneGenerator::class)->markTerminalFailure(
            $this->sceneId,
            $exception ?? new AiProviderException('scene_generation_failed', 'Scene 生成失败。', false),
        );
    }
}
