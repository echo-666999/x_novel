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

/** 生成只包含 Volume 与 Arc 的全书 Structure。 */
class GenerateNovelOutlineStructureJob implements ShouldBeUnique, ShouldQueue
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
        return "novel-outline:{$this->batchRunId}:structure";
    }

    protected function outlineFailureScope(): string
    {
        return NovelOutlinePipeline::STRUCTURE_SCOPE;
    }

    public function handle(NovelOutlinePipeline $pipeline): void
    {
        $batch = GenerationRun::query()->find($this->batchRunId);
        if ($batch === null) {
            return;
        }

        try {
            if ($pipeline->generateStructure($batch) !== null) {
                $pipeline->dispatchNext($batch);
            }
        } catch (Throwable $exception) {
            $this->handleOutlineStageFailure($exception);
        }
    }
}
