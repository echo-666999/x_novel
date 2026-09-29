<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Exceptions\GenerationStageDeferredException;
use App\Exceptions\SceneStageDeferredException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Models\Scene;
use App\Services\AutoStopService;
use App\Services\GenerationFailurePolicy;
use App\Services\SceneGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateSceneJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $timeout = 330;

    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::SceneGeneration);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::SceneGeneration);
    }

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

    public function handle(SceneGenerator $generator, ?AdvanceChapterPipelineAction $advance = null): void
    {
        if ($this->stopWhenSceneWasDeleted($this->sceneId)) {
            return;
        }

        $advance ??= app(AdvanceChapterPipelineAction::class);

        try {
            $artifact = $generator->generate($this->sceneId, $this->regenerate, $this->regenerationBatchId, singleProviderCall: true);

            if ($artifact !== null) {
                $chapterId = Scene::query()->whereKey($this->sceneId)->value('chapter_id');
                if ($chapterId !== null) {
                    $advance->handle((int) $chapterId, $this->regenerationBatchId);
                }
            }

            $this->releaseGenerationDispatch();
        } catch (SceneStageDeferredException|GenerationStageDeferredException) {
            $this->releaseGenerationDispatch();
            $chapterId = Scene::query()->whereKey($this->sceneId)->value('chapter_id');
            if ($chapterId !== null) {
                $advance->handle((int) $chapterId, $this->regenerationBatchId);
            }

            return;
        } catch (AiProviderException $exception) {
            $policy = app(GenerationFailurePolicy::class);
            if ($policy->shouldQueueRetry($exception, GenerationStage::SceneGeneration)) {
                throw $exception;
            }

            if ($policy->shouldMarkTerminal(GenerationStage::SceneGeneration, $exception)) {
                $generator->markTerminalFailure($this->sceneId, $exception);
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
        $chapterId = Scene::query()->whereKey($this->sceneId)->value('chapter_id');
        if ($chapterId !== null) {
            app(AutoStopService::class)->stopForFailure((int) $chapterId, $exception);
        }
        if (app(GenerationFailurePolicy::class)->shouldMarkTerminal(GenerationStage::SceneGeneration, $exception)) {
            app(SceneGenerator::class)->markTerminalFailure(
                $this->sceneId,
                $exception ?? new AiProviderException('scene_generation_failed', 'Scene 生成失败。', false),
            );
        }
    }
}
