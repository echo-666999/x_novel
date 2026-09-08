<?php

namespace App\Actions\Novels;

use App\Enums\NovelStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartNovelGenerationAction
{
    public function handle(Novel $novel): Novel
    {
        return DB::transaction(function () use ($novel): Novel {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            if (! in_array($locked->status, [NovelStatus::Draft, NovelStatus::Planning], true)) {
                throw ValidationException::withMessages(['novel' => '只有草稿或规划中的小说可以开始正文生成。']);
            }

            $missing = collect([
                '小说圣经' => ! $locked->currentBible()->exists(),
                '主要人物' => ! $locked->characters()->where('role', '主角')->exists(),
                '世界设定' => ! $locked->worldEntities()->exists(),
                '进行中的分卷' => ! $locked->volumes()->where('status', VolumeStatus::Active)->exists(),
                '推进中的故事线' => ! $locked->storyArcs()->where('status', StoryArcStatus::Active)->exists(),
                '初始故事状态' => $locked->canonical_state_version_id === null,
            ])->filter()->keys()->implode('、');

            if ($missing !== '') {
                throw ValidationException::withMessages(['novel' => '规划尚未就绪，缺少：'.$missing.'。']);
            }

            $locked->update(['status' => NovelStatus::Generating]);

            return $locked->refresh();
        });
    }
}
