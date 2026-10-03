<?php

namespace App\Actions\Novels;

use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Services\NovelOutlinePipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetireLegacyNovelOutlineBatchAction
{
    public const ERROR_CODE = 'pipeline_contract_upgraded';

    /**
     * 旧批次只允许显式退役；历史 Run 与 Artifact 必须保留用于费用、失败和来源审计。
     */
    public function handle(GenerationRun $batch, string $reason): GenerationRun
    {
        return DB::transaction(function () use ($batch, $reason): GenerationRun {
            $candidate = GenerationRun::query()->findOrFail($batch->getKey());
            $novel = Novel::query()->lockForUpdate()->findOrFail($candidate->novel_id);
            $locked = GenerationRun::query()->lockForUpdate()->findOrFail($candidate->getKey());

            $this->assertLegacyBatch($locked);

            if ($locked->status === RunStatus::Cancelled && $locked->error_code === self::ERROR_CODE) {
                return $locked;
            }

            if (! in_array($locked->status, [RunStatus::Queued, RunStatus::Running, RunStatus::Failed], true)) {
                throw ValidationException::withMessages([
                    'run' => '只有 queued、running 或 failed 的旧 Outline 主批次可以退役。',
                ]);
            }

            $activeChildIds = GenerationRun::query()
                ->where('novel_id', $novel->getKey())
                ->where('scope_id', $locked->getKey())
                ->where('scope_type', '!=', NovelOutlinePipeline::BATCH_SCOPE)
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
                ->orderBy('id')
                ->pluck('id')
                ->all();
            if ($activeChildIds !== []) {
                throw ValidationException::withMessages([
                    'run' => '旧 Outline 主批次仍有活动子阶段，必须先停止 Worker 并确认请求已结束。',
                ]);
            }

            $childRuns = GenerationRun::query()
                ->where('novel_id', $novel->getKey())
                ->where('scope_id', $locked->getKey())
                ->where('scope_type', '!=', NovelOutlinePipeline::BATCH_SCOPE);
            $childRunIds = $childRuns->pluck('id');
            $artifactCount = GenerationArtifact::query()
                ->whereIn('generation_run_id', $childRunIds)
                ->count();
            $context = $locked->context_snapshot ?? [];
            $context['contract_upgrade'] = [
                'retired_at' => now()->toISOString(),
                'from_prompt_version' => $locked->prompt_version,
                'to_prompt_version' => NovelOutlinePipeline::BATCH_PROMPT_VERSION,
                'reason' => trim($reason),
            ];

            $locked->update([
                'status' => RunStatus::Cancelled,
                'context_snapshot' => $context,
                'error_code' => self::ERROR_CODE,
                'error_message' => '该 Outline 批次使用旧版 Pipeline 合同，已停止继续执行；请创建新版批次。',
                'error_retryable' => false,
                'error_metadata' => [
                    'category' => 'pipeline_contract',
                    'failed_scope' => NovelOutlinePipeline::BATCH_SCOPE,
                    'retired_from_status' => $locked->status->value,
                    'previous_error_code' => $locked->error_code,
                    'preserved_child_run_count' => $childRunIds->count(),
                    'preserved_artifact_count' => $artifactCount,
                    'reason' => trim($reason),
                ],
                'finished_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    private function assertLegacyBatch(GenerationRun $batch): void
    {
        if ($batch->scope_type !== NovelOutlinePipeline::BATCH_SCOPE
            || $batch->prompt_version !== NovelOutlinePipeline::LEGACY_BATCH_PROMPT_VERSION) {
            throw ValidationException::withMessages([
                'run' => '指定 Run 不是可退役的旧版 Outline 主批次。',
            ]);
        }
    }
}
