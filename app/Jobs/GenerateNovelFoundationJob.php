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

/** 生成一个规划批次的 Foundation 候选 Artifact。 */
class GenerateNovelFoundationJob implements ShouldBeUnique, ShouldQueue
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

    /** 同一主批次最多允许一个 Foundation Job 在队列中。 */
    public function uniqueId(): string
    {
        return "novel-outline:{$this->batchRunId}:foundation";
    }

    protected function outlineFailureScope(): string
    {
        return NovelOutlinePipeline::FOUNDATION_SCOPE;
    }

    /** 成功后只派发下一个缺失阶段。 */
    public function handle(NovelOutlinePipeline $pipeline): void
    {
        $batch = GenerationRun::query()->find($this->batchRunId);

        if ($batch === null) {
            return;
        }

        try {
            if ($pipeline->generateFoundation($batch) !== null) {
                $pipeline->dispatchNext($batch);
            }
        } catch (Throwable $exception) {
            $this->handleOutlineStageFailure($exception);
        }
    }
}
