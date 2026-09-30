<?php

namespace App\Actions\Novels;

use App\Enums\RunStatus;
use App\Jobs\GenerateNovelOutlineJob;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Services\GenerationJobDispatcher;
use App\Services\NovelOutlinePipeline;
use Throwable;

class StartNovelOutlineGenerationAction
{
    public function __construct(
        private readonly NovelOutlinePipeline $pipeline,
        private readonly GenerationJobDispatcher $dispatcher,
    ) {}

    /** 先持久化批次，再投递保持旧参数合同的协调 Job。 */
    public function handle(Novel $novel, int $volumeCount): GenerationRun
    {
        $batch = $this->pipeline->prepareBatch($novel, $volumeCount);
        if ($batch->status !== RunStatus::Queued) {
            return $batch;
        }

        try {
            $this->dispatcher->dispatch(new GenerateNovelOutlineJob($novel->getKey(), $volumeCount));
        } catch (Throwable $exception) {
            $this->pipeline->markQueueDispatchFailed($batch, $exception);

            throw $exception;
        }

        return $batch->refresh();
    }
}
