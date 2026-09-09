<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Enums\SceneStatus;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Models\Scene;
use App\Services\AutoStopService;
use App\Services\SceneGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateSceneJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $sceneId,
        public readonly bool $regenerate = false,
        public readonly bool $cascade = false,
        public readonly ?string $regenerationBatchId = null,
    ) {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'scene:'.$this->sceneId;
    }

    public function handle(SceneGenerator $generator): void
    {
        try {
            $artifact = $generator->generate($this->sceneId, $this->regenerate, $this->regenerationBatchId);

            if ($this->cascade && $artifact !== null) {
                $this->dispatchNextScene();
            }

            $this->releaseGenerationDispatch();
        } catch (AiProviderException $exception) {
            if (! $exception->retryable) {
                if (! in_array($exception->errorCode, ['novel_paused', 'previous_scene_incomplete'], true)) {
                    $generator->markTerminalFailure($this->sceneId, $exception);
                }

                $this->releaseGenerationDispatch();
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    private function dispatchNextScene(): void
    {
        $scene = Scene::query()->findOrFail($this->sceneId);
        $nextSceneId = $scene->chapter->scenes()
            ->where('sequence', '>', $scene->sequence)
            ->where('status', SceneStatus::Planned)
            ->whereNull('current_artifact_id')
            ->orderBy('sequence')
            ->value('id');

        if ($nextSceneId !== null) {
            $job = new GenerateSceneJob(
                sceneId: (int) $nextSceneId,
                cascade: true,
                regenerationBatchId: $this->regenerationBatchId,
            );
            $job->afterCommit();
            $this->dispatchGenerationJob($job);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        $chapterId = Scene::query()->whereKey($this->sceneId)->value('chapter_id');
        if ($chapterId !== null) {
            app(AutoStopService::class)->stopForFailure((int) $chapterId, $exception);
        }
        app(SceneGenerator::class)->markTerminalFailure(
            $this->sceneId,
            $exception ?? new AiProviderException('scene_generation_failed', 'Scene 生成失败。', false),
        );
    }
}
