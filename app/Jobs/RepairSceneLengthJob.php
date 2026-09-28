<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Models\Scene;
use App\Services\AutoStopService;
use App\Services\SceneLengthRepairer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RepairSceneLengthJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 330;

    public function __construct(
        public readonly int $sceneId,
        public readonly int $sourceArtifactId,
        public readonly string $mode,
        public readonly int $minimumWords,
        public readonly int $targetWords,
        public readonly int $maximumWords,
        public readonly string $assemblyHash,
    ) {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return "scene-length:{$this->sceneId}:{$this->assemblyHash}";
    }

    public function handle(SceneLengthRepairer $repairer, AdvanceChapterPipelineAction $advance): void
    {
        $artifact = $repairer->repair(
            $this->sceneId,
            $this->sourceArtifactId,
            $this->mode,
            $this->minimumWords,
            $this->targetWords,
            $this->maximumWords,
            $this->assemblyHash,
        );
        if ($artifact !== null) {
            $chapterId = Scene::query()->whereKey($this->sceneId)->value('chapter_id');
            if ($chapterId !== null) {
                $advance->handle((int) $chapterId);
            }
        }
        $this->releaseGenerationDispatch();
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        $chapterId = Scene::query()->whereKey($this->sceneId)->value('chapter_id');
        if ($chapterId !== null) {
            app(AutoStopService::class)->stopForFailure((int) $chapterId, $exception);
        }
    }
}
