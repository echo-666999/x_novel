<?php

namespace App\Actions\Chapters;

use App\Enums\GenerationStage;
use App\Models\Chapter;

/**
 * Plan 语义变化时只清除本章下游当前指针，历史 Run/Artifact 保持可审计。
 */
class InvalidateChapterPlanDownstreamAction
{
    public function __construct(private readonly InvalidateChapterStageDownstreamAction $invalidation) {}

    public function execute(Chapter $chapter, ?int $fromSceneSequence = null): void
    {
        $this->invalidation->execute($chapter, GenerationStage::ChapterPlanning, $fromSceneSequence);
    }
}
