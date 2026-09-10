<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use Illuminate\Support\Collection;

class AutomaticRewriteCounter
{
    /** @return Collection<int, GenerationArtifact> */
    public function artifactsFor(Chapter $chapter): Collection
    {
        $planningRunId = (int) $chapter->generationRuns()
            ->where('stage', GenerationStage::ChapterPlanning)
            ->where('status', RunStatus::Succeeded)
            ->latest('id')
            ->value('id');

        return GenerationArtifact::query()
            ->where('type', ArtifactType::RewriteDraft)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('id', '>', $planningRunId))
            ->orderBy('id')
            ->get()
            ->reject(fn (GenerationArtifact $artifact): bool => (bool) data_get($artifact->data, 'manual_edit'))
            ->values();
    }

    public function countFor(Chapter $chapter): int
    {
        return $this->artifactsFor($chapter)->count();
    }
}
