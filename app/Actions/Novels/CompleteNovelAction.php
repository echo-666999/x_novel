<?php

namespace App\Actions\Novels;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Services\BookEnding;
use App\Services\EndingAuditService;
use App\Services\GenerationRunCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteNovelAction
{
    public function __construct(private readonly EndingAuditService $endingAudit) {}

    public function handle(Novel $novel): Novel
    {
        return DB::transaction(function () use ($novel): Novel {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if ($lockedNovel->current_generation_task_id) {
                $task = NovelGenerationTask::whereKey($lockedNovel->current_generation_task_id)->lockForUpdate()->firstOrFail();
                $artifact = GenerationArtifact::findOrFail(data_get($task->progress_snapshot, 'ending_audit_id'));
                if ($task->status !== 'running' || $artifact->type !== ArtifactType::EndingAudit || $artifact->validation_status->value !== 'validated'
                    || $artifact->generationRun->generation_task_id !== $task->id || $artifact->generationRun->status->value !== 'succeeded'
                    || $artifact->data['state_version_id'] !== $lockedNovel->canonical_state_version_id || $artifact->data['blueprint_id'] !== $lockedNovel->current_blueprint_id) {
                    throw new \LogicException('完结审计已过期或任务暂停，不能标记正文完结。');
                }
                app(GenerationRunCoordinator::class)->assertSources($task, $artifact->generationRun->source_manifest, $artifact->generationRun->execution_contract);
                if (app(BookEnding::class)->validate($artifact->generationRun, $artifact->data)['decision'] !== 'PASS') {
                    throw new \LogicException('关键完结债务尚未清偿。');
                }
                // 正文完结与整书任务完成分开；导出成功后才结束 Task。
                $lockedNovel->update(['status' => NovelStatus::Completed]);

                return $lockedNovel->fresh();
            }
            if ($lockedNovel->status === NovelStatus::Completed) {
                return $lockedNovel;
            }

            if ($lockedNovel->status !== NovelStatus::Completing) {
                throw ValidationException::withMessages([
                    'novel' => '只有收束中的小说可以完结。',
                ]);
            }

            $latestAudit = $lockedNovel->generationRuns()
                ->where('stage', GenerationStage::EndingAudit)
                ->latest('id')
                ->first()?->artifacts()
                ->where('type', ArtifactType::EndingAudit)
                ->latest('id')
                ->first();

            if (data_get($latestAudit?->data, 'decision') !== 'PASS') {
                throw ValidationException::withMessages([
                    'ending_audit' => '必须先完成并通过结局审计。',
                ]);
            }

            $audit = $this->endingAudit->audit($lockedNovel);

            if (data_get($audit->data, 'decision') !== 'PASS') {
                throw ValidationException::withMessages([
                    'ending_audit' => '结局审计未通过，请先处理所有 BLOCK 项。',
                ]);
            }

            $settings = $lockedNovel->settings ?? [];
            $settings['auto_generate'] = false;

            $lockedNovel->update([
                'status' => NovelStatus::Completed,
                'settings' => $settings,
            ]);

            return $lockedNovel->refresh();
        });
    }
}
