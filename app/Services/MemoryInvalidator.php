<?php

namespace App\Services;

use App\Enums\MemoryStatus;
use App\Models\Chapter;
use App\Models\Memory;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MemoryInvalidator
{
    public function countForChapter(int $chapterId): int
    {
        $chapter = Chapter::query()->findOrFail($chapterId);

        return $this->queryForChapter($chapter)->count();
    }

    public function invalidateForChapter(int $chapterId): int
    {
        return DB::transaction(function () use ($chapterId): int {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapterId);

            return $this->queryForChapter($chapter)->update(['status' => MemoryStatus::Invalid]);
        });
    }

    private function queryForChapter(Chapter $chapter): Builder
    {
        $eventIds = StoryEvent::query()->where('novel_id', $chapter->novel_id)->where('chapter_id', $chapter->getKey())->pluck('id');
        $stateVersionIds = StoryStateVersion::query()->where('novel_id', $chapter->novel_id)->where('chapter_id', $chapter->getKey())->pluck('id');

        return Memory::query()
            ->where('novel_id', $chapter->novel_id)
            ->where('status', MemoryStatus::Active)
            ->where(function (Builder $sources) use ($chapter, $eventIds, $stateVersionIds): void {
                $sources->where(fn (Builder $source): Builder => $source->where('source_type', 'chapter')->where('source_id', $chapter->getKey()));

                if ($eventIds->isNotEmpty()) {
                    $sources->orWhere(fn (Builder $source): Builder => $source->where('source_type', 'story_event')->whereIn('source_id', $eventIds));
                }

                if ($stateVersionIds->isNotEmpty()) {
                    $sources->orWhere(fn (Builder $source): Builder => $source->where('source_type', 'story_state_version')->whereIn('source_id', $stateVersionIds));
                }
            });
    }
}
