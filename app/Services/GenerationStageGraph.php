<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use InvalidArgumentException;

/** 章节生成流水线的唯一声明式阶段图。 */
final class GenerationStageGraph
{
    /** @return array<int, GenerationStage> 返回当前阶段允许进入的直接后继阶段。 */
    public function next(GenerationStage $stage): array
    {
        return match ($stage) {
            GenerationStage::ChapterPlanning => [GenerationStage::SceneGeneration],
            // 恢复合同不重跑 Planner/Writer，只允许进入已有正文之后的合法节点。
            GenerationStage::ChapterRecovery => [
                GenerationStage::ChapterAssembly,
                GenerationStage::CoverageJudgment,
                GenerationStage::EventExtraction,
                GenerationStage::Review,
                GenerationStage::Rewrite,
            ],
            GenerationStage::SceneGeneration => [GenerationStage::SceneGeneration, GenerationStage::ChapterAssembly],
            GenerationStage::ChapterAssembly => [GenerationStage::CoverageJudgment, GenerationStage::EventExtraction],
            GenerationStage::CoverageJudgment => [GenerationStage::CoverageJudgment, GenerationStage::EventExtraction],
            GenerationStage::EventExtraction => [GenerationStage::Review],
            GenerationStage::Review => [GenerationStage::Rewrite, GenerationStage::Commit],
            GenerationStage::Rewrite => [GenerationStage::ChapterAssembly],
            GenerationStage::Commit => [GenerationStage::MemorySummary],
            GenerationStage::MemorySummary => [GenerationStage::Embedding],
            GenerationStage::Embedding, GenerationStage::EndingAudit => [],
        };
    }

    /** @return array<int, GenerationStage> 返回失效当前阶段后必须重建的全部下游阶段。 */
    public function downstream(GenerationStage $stage): array
    {
        return match ($stage) {
            GenerationStage::ChapterPlanning => [
                GenerationStage::SceneGeneration, GenerationStage::ChapterAssembly,
                GenerationStage::CoverageJudgment, GenerationStage::EventExtraction, GenerationStage::Review,
                GenerationStage::Rewrite, GenerationStage::Commit, GenerationStage::MemorySummary,
                GenerationStage::Embedding,
            ],
            GenerationStage::ChapterRecovery => [
                GenerationStage::ChapterAssembly, GenerationStage::CoverageJudgment,
                GenerationStage::EventExtraction, GenerationStage::Review,
                GenerationStage::Rewrite, GenerationStage::Commit,
                GenerationStage::MemorySummary, GenerationStage::Embedding,
            ],
            GenerationStage::SceneGeneration, GenerationStage::Rewrite => [
                GenerationStage::ChapterAssembly, GenerationStage::CoverageJudgment,
                GenerationStage::EventExtraction, GenerationStage::Review, GenerationStage::Commit,
                GenerationStage::MemorySummary, GenerationStage::Embedding,
            ],
            GenerationStage::ChapterAssembly => [
                GenerationStage::CoverageJudgment, GenerationStage::EventExtraction, GenerationStage::Review,
                GenerationStage::Rewrite, GenerationStage::Commit,
                GenerationStage::MemorySummary, GenerationStage::Embedding,
            ],
            GenerationStage::CoverageJudgment => [
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

    /** @return array<int, ArtifactType> 返回该阶段产生的不可变 Artifact 类型。 */
    public function artifactsProducedBy(GenerationStage $stage): array
    {
        return match ($stage) {
            GenerationStage::ChapterPlanning => [ArtifactType::ChapterPlan],
            GenerationStage::ChapterRecovery => [ArtifactType::Context],
            GenerationStage::SceneGeneration => [ArtifactType::SceneDraft, ArtifactType::Context],
            GenerationStage::ChapterAssembly => [ArtifactType::ChapterDraft],
            GenerationStage::EventExtraction => [ArtifactType::EventCandidate, ArtifactType::StatePatch, ArtifactType::Context],
            GenerationStage::CoverageJudgment => [ArtifactType::Context],
            GenerationStage::Review => [ArtifactType::ReviewResult],
            GenerationStage::Rewrite => [ArtifactType::RewriteDraft, ArtifactType::Context],
            GenerationStage::MemorySummary => [ArtifactType::Summary],
            GenerationStage::EndingAudit => [ArtifactType::EndingAudit],
            GenerationStage::Commit, GenerationStage::Embedding => [],
        };
    }

    /** 拒绝声明式阶段图之外的流程跳转。 */
    public function assertTransition(GenerationStage $from, GenerationStage $to): void
    {
        if (! in_array($to, $this->next($from), true)) {
            throw new InvalidArgumentException("Illegal generation stage transition: {$from->value} -> {$to->value}.");
        }
    }
}
