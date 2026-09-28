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

/** 基于 Foundation 生成 Volume、Arc、Beat 骨架。 */
class GenerateNovelOutlineSkeletonJob implements ShouldBeUnique, ShouldQueue
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

    /** 同一主批次最多允许一个 Skeleton Job 在队列中。 */
    public function uniqueId(): string
    {
        return "novel-outline:{$this->batchRunId}:skeleton";
    }

    /** 成功后继续到最早缺失的 Main Beat Detail。 */
    public function handle(NovelOutlinePipeline $pipeline): void
    {
        try {
            $batch = GenerationRun::query()->findOrFail($this->batchRunId);
            if ($pipeline->generateSkeleton($batch) !== null) {
                $pipeline->dispatchNext($batch);
            }
        } catch (Throwable $exception) {
            $this->handleOutlineStageFailure($exception);
        }
    }
}
