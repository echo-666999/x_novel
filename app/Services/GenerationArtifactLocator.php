<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use Illuminate\Database\Eloquent\Builder;

/**
 * 只返回输入身份、直接来源和确定性校验状态同时匹配的不可变 Artifact。
 */
final class GenerationArtifactLocator
{
    /**
     * @param  ArtifactType|array<int, ArtifactType>  $types
     * @param  array<int, RunStatus>  $runStatuses
     */
    public function latestForChapter(
        int $chapterId,
        ArtifactType|array $types,
        ?int $sourceArtifactId = null,
        ?int $stateVersion = null,
        ?string $inputHash = null,
        array $runStatuses = [RunStatus::Succeeded, RunStatus::Failed],
    ): ?GenerationArtifact {
        $typeValues = collect(is_array($types) ? $types : [$types])
            ->map(fn (ArtifactType $type): string => $type->value)
            ->all();

        return GenerationArtifact::query()
            ->whereIn('type', $typeValues)
            ->where('validation_status', ArtifactValidationStatus::Validated)
            ->when($sourceArtifactId !== null, fn (Builder $query) => $query->where('source_artifact_id', $sourceArtifactId))
            ->when($inputHash !== null, fn (Builder $query) => $query->where('input_hash', $inputHash))
            ->whereHas('generationRun', function (Builder $query) use ($chapterId, $stateVersion, $inputHash, $runStatuses): void {
                $query->where('chapter_id', $chapterId)
                    ->whereNull('scene_id')
                    ->whereIn('status', $runStatuses)
                    ->when($stateVersion !== null, fn (Builder $run) => $run->where('state_version', $stateVersion))
                    ->when($inputHash !== null, fn (Builder $run) => $run->where('input_hash', $inputHash));
            })
            ->with('generationRun')
            ->latest('version')
            ->latest('id')
            ->first();
    }

    /** @param array<int, ArtifactType> $types */
    public function findValidated(int $artifactId, int $chapterId, array $types): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->whereKey($artifactId)
            ->whereIn('type', collect($types)->map(fn (ArtifactType $type): string => $type->value))
            ->where('validation_status', ArtifactValidationStatus::Validated)
            ->whereHas('generationRun', fn (Builder $query) => $query
                ->where('chapter_id', $chapterId)
                ->whereNull('scene_id')
                ->whereColumn('generation_runs.input_hash', 'generation_artifacts.input_hash'))
            ->with('generationRun')
            ->first();
    }
}
