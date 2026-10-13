<?php

namespace App\Actions\Chapters;

use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\GenerateSceneJob;
use App\Models\Chapter;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegenerateSceneSequenceAction
{
    /**
     * 冻结预算已经耗尽时，同一 Plan 下重试只会重复同一失败。
     * 必须先创建新的 Plan Version，才能冻结当前 Route、Prompt 与请求预算。
     */
    public function requiresNewPlanBeforeRetry(Scene $scene): bool
    {
        $chapter = Chapter::query()->find($scene->chapter_id);
        if ($chapter === null) {
            return false;
        }

        $sceneIds = $chapter->scenes()
            ->where('sequence', '>=', $scene->sequence)
            ->pluck('id');

        return $this->hasCurrentPlanBudgetFailure($chapter, $sceneIds->all());
    }

    public function handle(Scene $scene): int
    {
        return DB::transaction(function () use ($scene): int {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($scene->chapter_id);

            if ($chapter->status === ChapterStatus::Canonical) {
                throw ValidationException::withMessages([
                    'scene' => '正式章节不能直接重新生成场景，请先回滚该章节。',
                ]);
            }

            $scenes = $chapter->scenes()
                ->where('sequence', '>=', $scene->sequence)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            if ($scenes->isEmpty()) {
                throw ValidationException::withMessages(['scene' => '没有找到需要重新生成的场景。']);
            }

            if ($this->hasCurrentPlanBudgetFailure($chapter, $scenes->modelKeys())) {
                throw ValidationException::withMessages([
                    'scene' => '当前 Chapter Plan 的冻结请求预算已经耗尽，直接重试仍会使用同一预算并再次失败。请先使用“编辑当前 Chapter Plan”原样保存，创建新的 Plan Version 后再生成。',
                ]);
            }

            $hasActiveRun = $chapter->generationRuns()
                ->where('stage', GenerationStage::SceneGeneration)
                ->whereIn('scene_id', $scenes->modelKeys())
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
                ->exists();

            if ($hasActiveRun) {
                throw ValidationException::withMessages(['scene' => '所选场景或后续场景正在生成，请等待当前任务完成。']);
            }

            Scene::query()->whereKey($scenes->modelKeys())->update([
                'status' => SceneStatus::Planned,
                'current_artifact_id' => null,
            ]);
            $chapter->update(['status' => ChapterStatus::Generating]);

            GenerateSceneJob::dispatch(
                sceneId: $scenes->first()->getKey(),
                cascade: true,
                regenerationBatchId: (string) Str::uuid(),
            )->afterCommit();

            return $scenes->count();
        });
    }

    /** @param array<int, int> $sceneIds */
    private function hasCurrentPlanBudgetFailure(Chapter $chapter, array $sceneIds): bool
    {
        $currentPlanId = $chapter->latestPlan()->value('id');
        if ($currentPlanId === null) {
            return false;
        }

        $failure = $chapter->generationRuns()
            ->where('stage', GenerationStage::SceneGeneration)
            ->whereIn('scene_id', $sceneIds)
            ->where('status', RunStatus::Failed)
            ->whereNotNull('error_code')
            ->latest('id')
            ->first(['error_code', 'context_snapshot']);

        if ($failure === null
            || (int) data_get($failure->context_snapshot, 'chapter_plan_id') !== (int) $currentPlanId) {
            return false;
        }

        $code = (string) $failure->error_code;

        return str_contains($code, '_reasoning_budget_exhausted')
            || str_contains($code, '_output_truncated')
            || str_contains($code, '_completion_budget_exhausted')
            || str_contains($code, '_output_budget_exhausted');
    }
}
