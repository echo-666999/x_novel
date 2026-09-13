<?php

namespace App\Actions\Generation;

use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\AI\BudgetService;
use App\AI\Exceptions\BudgetExceededException;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\AssembleChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\Review;
use App\Services\AutoStopService;
use App\Services\GenerationJobDispatcher;
use App\Services\GenerationRunLease;
use App\Services\GenerationStageGate;
use App\Services\RewriteScopeResolver;
use App\Services\StatePatchBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Validation\ValidationException;

class AdvanceChapterPipelineAction
{
    public function __construct(
        private readonly GenerationStageGate $stageGate,
        private readonly GenerationJobDispatcher $dispatcher,
        private readonly GenerationRunLease $runLease,
        private readonly SyncScenesFromChapterPlanAction $syncScenes,
        private readonly StatePatchBuilder $statePatchBuilder,
        private readonly RewriteScopeResolver $rewriteScopeResolver,
        private readonly BudgetService $budgetService,
        private readonly AutoStopService $autoStop,
    ) {}

    public function handle(int $chapterId, ?string $regenerationBatchId = null): ?GenerationStage
    {
        $nextStage = null;
        $nextJob = null;

        $this->stageGate->dispatchForChapter($chapterId, function () use ($chapterId, $regenerationBatchId, &$nextStage, &$nextJob): void {
            $chapter = $this->chapter($chapterId);

            if (! $this->canAdvance($chapter)) {
                return;
            }

            $latestDraft = $this->latestChapterDraft($chapter);
            $review = $latestDraft === null ? null : $this->latestReviewForDraft($chapter, $latestDraft);
            if ($review !== null && $this->reviewStillCurrent($chapter, $latestDraft, $review)) {
                [$nextStage, $nextJob] = $this->advanceReviewDecision($chapter, $review);

                return;
            }

            $draft = $this->currentDraft($chapter, $latestDraft);

            if ($draft === null) {
                [$nextStage, $nextJob] = $this->nextBeforeDraft($chapter, $regenerationBatchId);

                return;
            }

            $candidate = $this->latestArtifact($chapter, ArtifactType::EventCandidate);
            if ($candidate === null
                || (int) data_get($candidate->data, 'source_artifact_id') !== $draft->getKey()
                || $candidate->generationRun->state_version !== $chapter->novel->canonicalStateVersion?->version) {
                $nextStage = GenerationStage::EventExtraction;
                $nextJob = new ExtractStoryEventsJob($chapter->getKey());

                return;
            }

            $patch = $this->latestArtifact($chapter, ArtifactType::StatePatch);
            if ($patch === null
                || (int) data_get($patch->data, 'source_artifact_id') !== $candidate->getKey()
                || (int) data_get($patch->data, 'expected_state_version', -1) !== $chapter->novel->canonicalStateVersion?->version) {
                $this->statePatchBuilder->build($chapter->getKey());
            }

            $nextStage = GenerationStage::Review;
            $nextJob = new ReviewChapterJob($chapter->getKey());
        });

        if ($nextJob !== null && ! $this->hasFreshRun($chapterId)) {
            $this->dispatch($nextJob);
        }

        return $nextStage;
    }

    /** @return array{GenerationStage, ShouldQueue&ShouldBeUnique} */
    private function nextBeforeDraft(Chapter $chapter, ?string $regenerationBatchId): array
    {
        if ($chapter->latestPlan?->status !== PlanStatus::Ready) {
            return [GenerationStage::ChapterPlanning, new PlanChapterJob($chapter->getKey())];
        }

        if ($chapter->scenes->isEmpty()) {
            $this->syncScenes->execute($chapter);
            $chapter = $this->chapter($chapter->getKey());
        }

        if ($chapter->scenes->isEmpty()) {
            throw ValidationException::withMessages(['scenes' => '当前 Chapter Plan 没有可生成的 Scene。']);
        }

        $incomplete = $chapter->scenes->first(fn ($scene): bool => $scene->current_artifact_id === null
            || ! in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true));

        if ($incomplete !== null) {
            $batchId = $regenerationBatchId ?? $this->regenerationBatchIdBefore($chapter, $incomplete->sequence);

            return [GenerationStage::SceneGeneration, new GenerateSceneJob(
                sceneId: $incomplete->getKey(),
                cascade: $batchId !== null,
                regenerationBatchId: $batchId,
            )];
        }

        return [GenerationStage::ChapterAssembly, new AssembleChapterJob($chapter->getKey())];
    }

    private function latestChapterDraft(Chapter $chapter): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->whereNull('scene_id')
                ->whereIn('status', [RunStatus::Succeeded, RunStatus::Failed]))
            ->with('generationRun')
            ->latest('id')
            ->first();
    }

    private function currentDraft(Chapter $chapter, ?GenerationArtifact $draft = null): ?GenerationArtifact
    {
        $draft ??= $this->latestChapterDraft($chapter);

        if ($draft === null) {
            return null;
        }

        $scenes = $chapter->scenes;
        if ($scenes->isEmpty()) {
            return $draft;
        }

        if ($scenes->contains(fn ($scene): bool => $scene->current_artifact_id === null
            || ! in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true))) {
            return null;
        }

        return $this->draftMatchesCurrentScenes($chapter, $draft) ? $draft : null;
    }

    private function sourceAssemblyDraft(GenerationArtifact $draft): ?GenerationArtifact
    {
        $seen = [];

        while ($draft->type === ArtifactType::RewriteDraft) {
            if (isset($seen[$draft->getKey()])) {
                return null;
            }

            $seen[$draft->getKey()] = true;
            $sourceId = (int) data_get($draft->data, 'source_artifact_id');
            if ($sourceId < 1) {
                return null;
            }

            $draft = GenerationArtifact::query()->find($sourceId);
            if ($draft === null) {
                return null;
            }
        }

        return $draft->type === ArtifactType::ChapterDraft ? $draft : null;
    }

    private function latestReviewForDraft(Chapter $chapter, GenerationArtifact $draft): ?Review
    {
        $stateVersion = $chapter->novel->canonicalStateVersion?->version;

        return Review::query()
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('state_version', $stateVersion)
                ->where('status', RunStatus::Succeeded))
            ->with(['artifact', 'generationRun'])
            ->latest('id')
            ->get()
            ->first(fn (Review $review): bool => (int) data_get($review->artifact?->data, 'source_artifact_id') === $draft->getKey());
    }

    /** @return array{?GenerationStage, (ShouldQueue&ShouldBeUnique)|null} */
    private function advanceReviewDecision(Chapter $chapter, Review $review): array
    {
        if ($review->decision !== ReviewDecision::Rewrite) {
            return [null, null];
        }

        $scope = $this->rewriteScopeResolver->resolveReview($chapter, $review);
        if (! $scope->isResolved()) {
            return [null, null];
        }

        try {
            $this->budgetService->assertWithinChapterLimits($chapter);
        } catch (BudgetExceededException $exception) {
            $this->autoStop->stopForFailure($chapter->getKey(), $exception);

            return [null, null];
        }

        return [GenerationStage::Rewrite, new RewriteChapterJob(
            $chapter->getKey(),
            $scope->scope === 'scene' ? $scope->sceneId : null,
        )];
    }

    private function reviewStillCurrent(Chapter $chapter, GenerationArtifact $draft, Review $review): bool
    {
        $expectedStatus = match ($review->decision) {
            ReviewDecision::Rewrite => ChapterStatus::Rewrite,
            ReviewDecision::Block => ChapterStatus::Blocked,
            default => ChapterStatus::Review,
        };

        if ($chapter->status !== $expectedStatus) {
            return false;
        }

        return $this->draftMatchesCurrentScenes($chapter, $draft, allowIncompleteLegacyScenes: true);
    }

    private function draftMatchesCurrentScenes(Chapter $chapter, GenerationArtifact $draft, bool $allowIncompleteLegacyScenes = false): bool
    {
        $scenes = $chapter->scenes;
        if ($scenes->isEmpty()) {
            return true;
        }

        $assemblyDraft = $this->sourceAssemblyDraft($draft);
        $expectedChecksums = data_get($assemblyDraft?->data, 'ordered_scene_checksums');
        $complete = ! $scenes->contains(fn ($scene): bool => $scene->current_artifact_id === null
            || ! in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true));

        if (is_array($expectedChecksums)) {
            return $complete && $expectedChecksums === $scenes->pluck('currentArtifact.checksum')->all();
        }

        if (! $complete && ! $allowIncompleteLegacyScenes) {
            return false;
        }

        return ! $scenes->contains(fn ($scene): bool => $scene->current_artifact_id !== null
            && (int) $scene->current_artifact_id > $draft->getKey());
    }

    private function latestArtifact(Chapter $chapter, ArtifactType $type): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', $type)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->whereIn('status', [RunStatus::Succeeded, RunStatus::Failed]))
            ->with('generationRun')
            ->latest('version')
            ->latest('id')
            ->first();
    }

    private function regenerationBatchIdBefore(Chapter $chapter, int $sequence): ?string
    {
        $artifact = $chapter->scenes
            ->where('sequence', '<', $sequence)
            ->sortByDesc('sequence')
            ->first()?->currentArtifact;
        $batchId = data_get($artifact?->generationRun?->context_snapshot, 'regeneration_batch_id');

        return is_string($batchId) && $batchId !== '' ? $batchId : null;
    }

    private function canAdvance(Chapter $chapter): bool
    {
        if (! in_array($chapter->novel->status, [NovelStatus::Generating, NovelStatus::Completing], true)
            || in_array($chapter->status, [ChapterStatus::Blocked, ChapterStatus::Canonical, ChapterStatus::Void], true)) {
            return false;
        }

        $activeStatuses = [ChapterStatus::Planned, ChapterStatus::Generating, ChapterStatus::Review, ChapterStatus::Rewrite];

        return ! $chapter->novel->chapters()
            ->whereKeyNot($chapter->getKey())
            ->whereIn('status', $activeStatuses)
            ->exists()
            && ! $chapter->novel->generationRuns()
                ->where(fn ($query) => $query
                    ->whereNull('chapter_id')
                    ->orWhere('chapter_id', '!=', $chapter->getKey()))
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
                ->exists();
    }

    private function hasFreshRun(int $chapterId): bool
    {
        return Chapter::query()->findOrFail($chapterId)->generationRuns()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->get()
            ->contains(fn ($run): bool => $this->runLease->isFresh($run));
    }

    private function chapter(int $chapterId): Chapter
    {
        return Chapter::query()->with([
            'novel.canonicalStateVersion',
            'latestPlan',
            'scenes.currentArtifact.generationRun',
        ])->findOrFail($chapterId);
    }

    private function dispatch(ShouldQueue&ShouldBeUnique $job): void
    {
        $this->dispatcher->dispatch($job);
    }
}
