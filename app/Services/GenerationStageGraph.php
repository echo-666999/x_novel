<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use InvalidArgumentException;

/** The single declarative graph for the chapter generation pipeline. */
final class GenerationStageGraph
{
    /** @return array<int, GenerationStage> */
    public function next(GenerationStage $stage): array
    {
        return match ($stage) {
            GenerationStage::ChapterPlanning => [GenerationStage::SceneGeneration],
            GenerationStage::SceneGeneration => [GenerationStage::SceneGeneration, GenerationStage::ChapterAssembly],
            GenerationStage::ChapterAssembly => [GenerationStage::EventExtraction],
            GenerationStage::EventExtraction => [GenerationStage::Review],
            GenerationStage::Review => [GenerationStage::Rewrite, GenerationStage::Commit],
            GenerationStage::Rewrite => [GenerationStage::ChapterAssembly],
            GenerationStage::Commit => [GenerationStage::MemorySummary],
            GenerationStage::MemorySummary => [GenerationStage::Embedding],
            GenerationStage::Embedding, GenerationStage::EndingAudit => [],
        };
    }

    /** @return array<int, GenerationStage> */
    public function downstream(GenerationStage $stage): array
    {
        return match ($stage) {
            GenerationStage::ChapterPlanning => [
                GenerationStage::SceneGeneration, GenerationStage::ChapterAssembly,
                GenerationStage::EventExtraction, GenerationStage::Review,
                GenerationStage::Rewrite, GenerationStage::Commit, GenerationStage::MemorySummary,
                GenerationStage::Embedding,
            ],
            GenerationStage::SceneGeneration, GenerationStage::Rewrite => [
                GenerationStage::ChapterAssembly, GenerationStage::EventExtraction,
                GenerationStage::Review, GenerationStage::Commit,
                GenerationStage::MemorySummary, GenerationStage::Embedding,
            ],
            GenerationStage::ChapterAssembly => [
                GenerationStage::EventExtraction, GenerationStage::Review,
                GenerationStage::Rewrite, GenerationStage::Commit,
                GenerationStage::MemorySummary, GenerationStage::Embedding,
            ],
            GenerationStage::EventExtraction => [
                GenerationStage::Review, GenerationStage::Rewrite, GenerationStage::Commit,
                GenerationStage::MemorySummary, GenerationStage::Embedding,
            ],
            GenerationStage::Review => [
                GenerationStage::Rewrite, GenerationStage::Commit,
                GenerationStage::MemorySummary, GenerationStage::Embedding,
            ],
            GenerationStage::Commit => [GenerationStage::MemorySummary, GenerationStage::Embedding],
            GenerationStage::MemorySummary => [GenerationStage::Embedding],
            GenerationStage::Embedding, GenerationStage::EndingAudit => [],
        };
    }

    /** @return array<int, ArtifactType> */
    public function artifactsProducedBy(GenerationStage $stage): array
    {
        return match ($stage) {
            GenerationStage::ChapterPlanning => [ArtifactType::ChapterPlan],
            GenerationStage::SceneGeneration => [ArtifactType::SceneDraft, ArtifactType::Context],
            GenerationStage::ChapterAssembly => [ArtifactType::ChapterDraft],
            GenerationStage::EventExtraction => [ArtifactType::EventCandidate, ArtifactType::StatePatch, ArtifactType::Context],
            GenerationStage::Review => [ArtifactType::ReviewResult],
            GenerationStage::Rewrite => [ArtifactType::RewriteDraft, ArtifactType::Context],
            GenerationStage::MemorySummary => [ArtifactType::Summary],
            GenerationStage::EndingAudit => [ArtifactType::EndingAudit],
            GenerationStage::Commit, GenerationStage::Embedding => [],
        };
    }

    public function assertTransition(GenerationStage $from, GenerationStage $to): void
    {
        if (! in_array($to, $this->next($from), true)) {
            throw new InvalidArgumentException("Illegal generation stage transition: {$from->value} -> {$to->value}.");
        }
    }
}
