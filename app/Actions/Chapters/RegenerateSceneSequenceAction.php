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
}
