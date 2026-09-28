<?php

namespace App\Services;

use App\Models\StoryArc;
use Illuminate\Validation\ValidationException;

class StoryArcBeatContract
{
    /** @return array<int, array{beat_key: string, beat_index: int, text: string}> */
    public function forArc(StoryArc $arc): array
    {
        $arc->loadMissing('sourceOutlineArc.beats');
        if ($arc->sourceOutlineArc === null) {
            throw ValidationException::withMessages(['story_arc' => 'Story Arc 缺少关系化 Outline 来源。']);
        }

        // 运行态 Arc 不再保存 Beat 副本，所有契约直接读取不可变 Outline 定义。
        return $arc->sourceOutlineArc->beats->map(fn ($beat): array => [
            'beat_key' => $beat->beat_key,
            'beat_index' => $beat->sequence,
            'text' => $beat->title,
        ])->all();
    }
}
