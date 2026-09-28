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

/** 不调用 Provider，确定性合并并持久化最终 Draft Outline。 */
class FinalizeNovelOutlineJob implements ShouldBeUnique, ShouldQueue
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

    /** 同一主批次最多允许一个 Finalize Job 在队列中。 */
    public function uniqueId(): string
    {
        return "novel-outline:{$this->batchRunId}:finalize";
    }

    /** 仅执行来源校验、合并和事务写入。 */
    public function handle(NovelOutlinePipeline $pipeline): void
    {
        try {
            $pipeline->finalize(GenerationRun::query()->findOrFail($this->batchRunId));
        } catch (Throwable $exception) {
            $this->handleOutlineStageFailure($exception);
        }
    }
}
