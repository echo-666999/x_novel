<?php

namespace App\Actions\Chapters;

use App\Enums\ChapterStatus;
use App\Enums\SceneStatus;
use App\Models\Chapter;

/**
 * Plan 语义变化时只清除本章下游当前指针，历史 Run/Artifact 保持可审计。
 */
class InvalidateChapterPlanDownstreamAction
{
    public function execute(Chapter $chapter): void
    {
        if ($chapter->status === ChapterStatus::Canonical) {
            return;
        }

        $chapter->scenes()->update([
            'status' => SceneStatus::Planned->value,
            'current_artifact_id' => null,
        ]);
        $chapter->update([
            'status' => ChapterStatus::Generating,
            'canonical_artifact_id' => null,
        ]);
    }
}
