<?php

namespace App\Actions\Chapters;

use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Services\GenerationStageGraph;

/** Clears only mutable current pointers; immutable Run/Artifact history remains audit evidence. */
final class InvalidateChapterStageDownstreamAction
{
    public function __construct(private readonly GenerationStageGraph $graph) {}

    public function execute(Chapter $chapter, GenerationStage $changedStage, ?int $fromSceneSequence = null): void
    {
        if ($chapter->status === ChapterStatus::Canonical) {
            return;
        }

        $downstream = $this->graph->downstream($changedStage);
        if (in_array(GenerationStage::SceneGeneration, $downstream, true)) {
            $chapter->scenes()
                ->when($fromSceneSequence !== null, fn ($query) => $query->where('sequence', '>=', $fromSceneSequence))
                ->update(['status' => SceneStatus::Planned->value, 'current_artifact_id' => null]);
        }

        if (in_array(GenerationStage::ChapterAssembly, $downstream, true)) {
            $chapter->update(['status' => ChapterStatus::Generating, 'canonical_artifact_id' => null]);
        }
    }
}
