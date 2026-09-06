<?php

namespace App\Actions\Chapters;

use App\Enums\SceneStatus;
use App\Models\Chapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncScenesFromChapterPlanAction
{
    public function execute(Chapter $chapter): int
    {
        return DB::transaction(function () use ($chapter): int {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $plan = $chapter->plans()->orderByDesc('version')->first();

            if ($plan === null) {
                throw ValidationException::withMessages([
                    'plan' => '请先建立 Chapter Plan。',
                ]);
            }

            $existingScenes = $chapter->scenes()->lockForUpdate()->get();
            $protectedScene = $existingScenes->first(fn ($scene): bool => $scene->status !== SceneStatus::Planned || $scene->current_artifact_id !== null
            );

            if ($protectedScene !== null) {
                throw ValidationException::withMessages([
                    'scenes' => "Scene {$protectedScene->sequence} 已进入生成流程，不能从 Plan 覆盖。",
                ]);
            }

            $scenePlans = array_values($plan->scene_plans ?? []);

            foreach ($scenePlans as $index => $scenePlan) {
                $chapter->scenes()->updateOrCreate(
                    ['sequence' => $index + 1],
                    [
                        'pov_character_id' => $scenePlan['pov_character_id'] ?? $plan->pov_character_id,
                        'location' => $scenePlan['location'] ?? null,
                        'time_anchor' => $scenePlan['time_anchor'] ?? $plan->time_anchor,
                        'goal' => $scenePlan['goal'],
                        'conflict' => $scenePlan['conflict'],
                        'turn' => $scenePlan['turn'],
                        'outcome' => $scenePlan['outcome'],
                        'status' => SceneStatus::Planned,
                        'current_artifact_id' => null,
                    ],
                );
            }

            $chapter->scenes()->where('sequence', '>', count($scenePlans))->delete();

            return count($scenePlans);
        });
    }
}
