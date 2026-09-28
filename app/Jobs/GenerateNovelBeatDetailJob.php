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

/** 一次只生成一个 Main Beat 的 Milestones 与出站 Handoff。 */
class GenerateNovelBeatDetailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, HandlesNovelOutlineStageFailures, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 330;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $batchRunId, public readonly string $beatKey)
    {
        $this->onQueue('generation');
    }

    /** 每个主批次与 Beat Key 形成独立队列幂等范围。 */
    public function uniqueId(): string
    {
        return "novel-outline:{$this->batchRunId}:beat:{$this->beatKey}";
    }

    /** 当前 Beat 成功后再选择下一个缺失阶段。 */
    public function handle(NovelOutlinePipeline $pipeline): void
    {
        try {
            $batch = GenerationRun::query()->findOrFail($this->batchRunId);
            if ($pipeline->generateBeatDetail($batch, $this->beatKey) !== null) {
                $pipeline->dispatchNext($batch);
            }
        } catch (Throwable $exception) {
            $this->handleOutlineStageFailure($exception);
        }
    }
}
