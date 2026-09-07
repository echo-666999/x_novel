<?php

namespace App\Services;

use App\Enums\NovelStatus;
use App\Models\Chapter;
use App\Models\Novel;
use Closure;
use Illuminate\Support\Facades\DB;

class GenerationStageGate
{
    public function dispatchForChapter(int $chapterId, Closure $dispatch): bool
    {
        return DB::transaction(function () use ($chapterId, $dispatch): bool {
            $chapter = Chapter::query()->findOrFail($chapterId);
            $novel = Novel::query()->lockForUpdate()->findOrFail($chapter->novel_id);

            if ($novel->status === NovelStatus::Paused) {
                return false;
            }

            $dispatch();

            return true;
        });
    }
}
