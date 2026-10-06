<?php

namespace App\Actions\Novels;

use App\Enums\RunStatus;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Services\GenerationFailurePolicy;
use App\Services\NovelOutlinePipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResumeNovelOutlineGenerationAction
{
    public function __construct(
        private readonly NovelOutlinePipeline $pipeline,
        private readonly GenerationFailurePolicy $failurePolicy,
    ) {}

    /** failed 批次只能经领域检查恢复，不能直接重放 failed_jobs。 */
    public function handle(Novel $novel, GenerationRun $batch): GenerationRun
    {
        $resumed = DB::transaction(function () use ($novel, $batch): GenerationRun {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $lockedBatch = GenerationRun::query()->lockForUpdate()->findOrFail($batch->getKey());

            if ($lockedBatch->novel_id !== $lockedNovel->getKey()) {
                throw ValidationException::withMessages(['run' => 'Outline 主批次不属于当前小说。']);
            }
            if ($lockedBatch->status !== RunStatus::Failed) {
                throw ValidationException::withMessages(['run' => '只有 failed Outline 主批次可以继续生成。']);
            }
            if (! $this->failurePolicy->allowsFrozenResume($lockedBatch)) {
                // Resume 必须复用冻结路由；配置或预算类失败要修复配置后创建新批次。
                throw ValidationException::withMessages(['run' => '当前失败不能继续冻结批次，请修复配置后重新生成候选。']);
            }

            $this->pipeline->assertNovelPlanningAvailable($lockedNovel);
            $this->pipeline->assertResumeCompatible($lockedBatch);

            $otherActiveBatch = GenerationRun::query()
                ->where('novel_id', $lockedNovel->getKey())
                ->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)
                ->where('id', '!=', $lockedBatch->getKey())
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
                ->exists();
            if ($otherActiveBatch) {
                throw ValidationException::withMessages(['run' => '当前小说已有其他活动 Outline 主批次。']);
            }

            $activeChild = GenerationRun::query()
                ->where('novel_id', $lockedNovel->getKey())
                ->where('scope_id', $lockedBatch->getKey())
                ->where('scope_type', '!=', NovelOutlinePipeline::BATCH_SCOPE)
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
                ->exists();
            if ($activeChild) {
                throw ValidationException::withMessages(['run' => '当前批次仍有排队中或运行中的子阶段。']);
            }

            $context = $lockedBatch->context_snapshot ?? [];
            $context['recovery'] = [
                'resumed_at' => now()->toISOString(),
                'previous_error_code' => $lockedBatch->error_code,
                'previous_finished_at' => $lockedBatch->finished_at?->toISOString(),
            ];
            $lockedBatch->update([
                'status' => RunStatus::Running,
                'context_snapshot' => $context,
                'error_code' => null,
                'error_message' => null,
                'error_retryable' => null,
                'error_metadata' => null,
                'finished_at' => null,
                'started_at' => $lockedBatch->started_at ?? now(),
            ]);

            return $lockedBatch->refresh();
        }, 3);

        try {
            $this->pipeline->dispatchNext($resumed);
        } catch (Throwable $exception) {
            $this->pipeline->markBatchFailed(
                $resumed->getKey(),
                $exception,
                NovelOutlinePipeline::BATCH_SCOPE,
                null,
                false,
            );

            throw $exception;
        }

        return $resumed->refresh();
    }
}
