<?php

namespace App\Jobs;

use App\Jobs\Concerns\HandlesNovelOutlineStageFailures;
use App\Models\GenerationRun;
use App\Services\NovelOutlinePipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** 不调用 Provider，确定性组装完整 Skeleton。 */
class AssembleNovelOutlineSkeletonJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, HandlesNovelOutlineStageFailures, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 330;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $batchRunId)
    {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return "novel-outline:{$this->batchRunId}:skeleton-assembly";
    }

    protected function outlineFailureScope(): string
    {
        return NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE;
    }

    public function handle(NovelOutlinePipeline $pipeline): void
    {
        $batch = GenerationRun::query()->find($this->batchRunId);
        if ($batch === null) {
            return;
        }

        try {
            if ($pipeline->assembleSkeleton($batch) !== null) {
                $pipeline->dispatchNext($batch);
            }
        } catch (Throwable $exception) {
            $this->handleOutlineStageFailure($exception);
        }
    }
}
