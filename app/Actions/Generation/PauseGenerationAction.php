<?php

namespace App\Actions\Generation;

use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PauseGenerationAction
{
    public function handle(Novel $novel): Novel
    {
        return DB::transaction(function () use ($novel): Novel {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if (! in_array($lockedNovel->status, [NovelStatus::Generating, NovelStatus::Completing], true)) {
                throw ValidationException::withMessages(['novel' => '只有生成中或收束中的小说可以暂停。']);
            }

            $latestRun = $lockedNovel->generationRuns()->with(['chapter:id,sequence', 'scene:id,sequence'])->latest('id')->first();
            $settings = $lockedNovel->settings ?? [];
            $settings['pause'] = [
                'paused_at' => now()->toISOString(),
                'previous_status' => $lockedNovel->status->value,
                'stage' => $latestRun?->stage->value,
                'label' => $this->label($latestRun),
                'chapter_id' => $latestRun?->chapter_id,
                'scene_id' => $latestRun?->scene_id,
            ];
            $lockedNovel->update([
                'status' => NovelStatus::Paused,
                'settings' => $settings,
            ]);

            return $lockedNovel->refresh();
        });
    }

    private function label(?GenerationRun $run): string
    {
        if ($run?->stage === GenerationStage::SceneGeneration && $run->scene !== null) {
            return '场景 '.$run->scene->sequence;
        }

        return $run?->stage->getLabel() ?? '等待下一阶段';
    }
}
