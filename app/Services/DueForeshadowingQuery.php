<?php

namespace App\Services;

use App\Enums\ForeshadowingTimingStatus;
use App\Models\Foreshadowing;
use App\Models\Novel;

final readonly class DueForeshadowingQuery
{
    public function __construct(private ForeshadowingLifecycleResolver $lifecycle) {}

    /** @return array<int> */
    public function attentionIds(?Novel $novel = null): array
    {
        return Foreshadowing::query()
            ->select('foreshadowings.*')
            ->join('novels', 'novels.id', '=', 'foreshadowings.novel_id')
            ->with('novel.canonicalStateVersion')
            ->when($novel !== null, fn ($query) => $query->where('foreshadowings.novel_id', $novel->getKey()))
            ->get()
            ->filter(function (Foreshadowing $foreshadowing): bool {
                $timing = ForeshadowingTimingStatus::forTargetChapter(
                    $this->lifecycle->status($foreshadowing, $foreshadowing->novel),
                    $foreshadowing->due_from_chapter,
                    $foreshadowing->due_to_chapter,
                    Foreshadowing::nextChapterSequence($foreshadowing->novel->current_chapter_sequence),
                );

                return in_array($timing, [ForeshadowingTimingStatus::Due, ForeshadowingTimingStatus::Overdue], true);
            })
            ->modelKeys();
    }

    public function countForNovel(Novel $novel): int
    {
        return count($this->attentionIds($novel));
    }
}
