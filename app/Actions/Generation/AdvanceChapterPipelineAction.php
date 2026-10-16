<?php

namespace App\Actions\Generation;

use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\AI\BudgetService;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\BudgetExceededException;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\ContentRepairType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\AdjudicatePlanCoverageJob;
use App\Jobs\AssembleChapterJob;
use App\Jobs\CommitChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\Review;
use App\Services\AutoStopService;
use App\Services\ContentSelfHealingService;
use App\Services\GenerationArtifactLocator;
use App\Services\GenerationJobDispatcher;
use App\Services\GenerationOutputCapacityGuard;
use App\Services\GenerationRunLease;
use App\Services\GenerationStageGate;
use App\Services\LegacyAdmissionRecoveryContract;
use App\Services\PlanAdmissionService;
use App\Services\PlanCoverageJudgmentRepairer;
use App\Services\RewriteScopeResolver;
use App\Services\StatePatchBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Validation\ValidationException;

/** 章节流水线唯一推进器，所有下一阶段选择都以 PostgreSQL 持久化状态为准。 */
class AdvanceChapterPipelineAction
{
    /** 注入阶段门禁、统一调度器和各确定性来源解析服务。 */
    public function __construct(
        private readonly GenerationStageGate $stageGate,
        private readonly GenerationJobDispatcher $dispatcher,
        private readonly GenerationRunLease $runLease,
        private readonly SyncScenesFromChapterPlanAction $syncScenes,
        private readonly StatePatchBuilder $statePatchBuilder,
        private readonly RewriteScopeResolver $rewriteScopeResolver,
        private readonly BudgetService $budgetService,
        private readonly AutoStopService $autoStop,
        private readonly PlanAdmissionService $planAdmission,
        private readonly PlanCoverageJudgmentRepairer $coverageJudgment,
        private readonly LegacyAdmissionRecoveryContract $legacyRecovery,
        private readonly ContentSelfHealingService $contentSelfHealing,
        private readonly GenerationArtifactLocator $artifacts,
    ) {}

    /** 按持久化来源链选择唯一下一阶段，Coverage 复核必须先于任何内容 Rewrite 决策。 */
    public function handle(int $chapterId, ?string $regenerationBatchId = null): ?GenerationStage
    {
        $nextStage = null;
        $nextJob = null;

        $this->stageGate->dispatchForChapter($chapterId, function () use ($chapterId, $regenerationBatchId, &$nextStage, &$nextJob): void {
            $chapter = $this->chapter($chapterId);

            if (! $this->canAdvance($chapter)) {
                return;
            }
            $activeRepair = $this->contentSelfHealing->activeForChapter($chapter);
            if ($activeRepair !== null) {
                $this->contentSelfHealing->assertWithinLimits($activeRepair);
            }

            $latestDraft = $this->latestChapterDraft($chapter);
            $draft = $this->currentDraft($chapter, $latestDraft);
            if ($draft !== null) {
                $pendingCoverageSceneId = $this->coverageJudgment->pendingSceneId($chapter, $draft);
                if ($pendingCoverageSceneId !== null) {
                    $nextStage = GenerationStage::CoverageJudgment;
                    $nextJob = new AdjudicatePlanCoverageJob(
                        chapterId: $chapter->getKey(),
                        draftArtifactId: $draft->getKey(),
                        sceneId: $pendingCoverageSceneId,
                    );

                    return;
                }
            }

            $review = $latestDraft === null ? null : $this->latestReviewForDraft($chapter, $latestDraft);
            if ($review !== null
                && $this->reviewStillCurrent($chapter, $latestDraft, $review)
                && $this->coverageJudgment->reviewCoversCurrentJudgments($review, $chapter, $latestDraft)) {
                [$nextStage, $nextJob] = $this->advanceReviewDecision($chapter, $latestDraft, $review);

                return;
            }

            if ($draft === null) {
                [$nextStage, $nextJob] = $this->nextBeforeDraft($chapter, $regenerationBatchId);

                return;
            }

            $candidate = $this->artifacts->latestForChapter(
                $chapter->getKey(),
                ArtifactType::EventCandidate,
                $draft->getKey(),
                $chapter->novel->canonicalStateVersion?->version,
            );
            if ($candidate === null) {
                $nextStage = GenerationStage::EventExtraction;
                // Worker 丢失后的统一推进仍要携带旧 Admission 恢复 Run，不能退回 Plan v1 读取容量。
                $nextJob = new ExtractStoryEventsJob(
                    chapterId: $chapter->getKey(),
                    recoveryRunId: $this->legacyEventRecoveryRunId($chapter, $draft),
                );

                return;
            }

            $patch = $this->artifacts->latestForChapter(
                $chapter->getKey(),
                ArtifactType::StatePatch,
                $candidate->getKey(),
                $chapter->novel->canonicalStateVersion?->version,
            );
            if ($patch === null
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

        $usesLegacyRecovery = $this->legacyRecovery->appliesTo($chapter);
        if (! $usesLegacyRecovery) {
            $this->planAdmission->admit($chapter->latestPlan);
        }

        if ($chapter->scenes->isEmpty()) {
            if ($usesLegacyRecovery) {
                throw ValidationException::withMessages(['scenes' => 'Admission v1 恢复合同不能补建或重新生成 Scene。']);
            }
            $this->syncScenes->execute($chapter);
            $chapter = $this->chapter($chapter->getKey());
        }

        if ($chapter->scenes->isEmpty()) {
            throw ValidationException::withMessages(['scenes' => '当前 Chapter Plan 没有可生成的 Scene。']);
        }

        $incomplete = $chapter->scenes->first(fn ($scene): bool => $scene->current_artifact_id === null
            || ! in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true));

        if ($incomplete !== null) {
            if ($usesLegacyRecovery) {
                throw ValidationException::withMessages([
                    'scenes' => 'Admission v1 恢复发现未完成 Scene；为避免重新请求 Writer，流程已停止。',
                ]);
            }
            $batchId = $regenerationBatchId ?? $this->regenerationBatchIdBefore($chapter, $incomplete->sequence);

            return [GenerationStage::SceneGeneration, new GenerateSceneJob(
                sceneId: $incomplete->getKey(),
                cascade: $batchId !== null,
                regenerationBatchId: $batchId,
            )];
        }

        if ($usesLegacyRecovery) {
            // 局部 Rewrite 后允许确定性重组，但每个 Scene 必须来自原冻结来源或本恢复合同。
            $this->legacyRecovery->assertCanAssembleCurrentScenes($chapter);
        }

        return [GenerationStage::ChapterAssembly, new AssembleChapterJob($chapter->getKey())];
    }

    private function latestChapterDraft(Chapter $chapter): ?GenerationArtifact
    {
        return $this->artifacts->latestForChapter(
            $chapter->getKey(),
            [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft],
        );
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
    private function advanceReviewDecision(Chapter $chapter, GenerationArtifact $draft, Review $review): array
    {
        if ($review->decision === ReviewDecision::Pass) {
            $this->contentSelfHealing->completeAfterReview($chapter);

            return $this->nextAfterPass($chapter, $draft, $review);
        }

        $repairAdvice = data_get($review->artifact?->data, 'repair_advice');
        if (is_array($repairAdvice) && ($repairAdvice['action'] ?? null) === 'rebuild_plan_or_scenes') {
            $findingCodes = collect($review->findings)
                ->filter(fn (mixed $finding): bool => is_array($finding))
                ->pluck('code')
                ->filter(fn (mixed $code): bool => is_string($code))
                ->values()
                ->all();
            $repairType = in_array('REWRITE_EXHAUSTED', $findingCodes, true)
                ? ContentRepairType::RewriteExhaustion
                : ContentRepairType::ChapterStructure;

            try {
                $repair = $this->contentSelfHealing->start(
                    $chapter,
                    $repairType,
                    ['review_id' => $review->getKey(), 'draft_artifact_id' => $draft->getKey()],
                    ['repair_advice' => $repairAdvice, 'finding_codes' => $findingCodes],
                );
            } catch (AiProviderException $exception) {
                $chapter->update(['status' => ChapterStatus::Blocked]);
                $this->autoStop->stopForFailure($chapter->getKey(), $exception);

                return [null, null];
            }

            return [GenerationStage::ChapterPlanning, new PlanChapterJob(
                chapterId: $chapter->getKey(),
                regenerate: true,
                contentRepairRunId: $repair->getKey(),
            )];
        }

        if ($review->decision !== ReviewDecision::Rewrite) {
            $activeRepair = $this->contentSelfHealing->activeForChapter($chapter);
            if ($activeRepair !== null) {
                $this->contentSelfHealing->fail(
                    $activeRepair,
                    'content_repair_review_not_passed',
                    '章节重建后的 Review 未通过，自动修复已结束。',
                    ['review_id' => $review->getKey(), 'decision' => $review->decision->value],
                );
            }

            return [null, null];
        }

        $scope = $this->rewriteScopeResolver->resolveReview($chapter, $review);
        if (! $scope->isResolved()) {
            $activeRepair = $this->contentSelfHealing->activeForChapter($chapter);
            if ($activeRepair !== null) {
                $this->contentSelfHealing->fail(
                    $activeRepair,
                    'content_repair_rewrite_scope_unresolved',
                    '章节重建后的 Review 无法确定安全的重写范围，自动修复已结束。',
                    ['review_id' => $review->getKey()],
                );
            }
            $this->autoStop->stop(
                $chapter->novel,
                'rewrite_scope_unresolved',
                '审校要求重写，但无法解析安全的自动重写范围。',
                '根据 Review Finding 重建 Plan 或受影响 Scene。',
            );

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
            $scope->sceneId,
        )];
    }

    /** @return array{?GenerationStage, (ShouldQueue&ShouldBeUnique)|null} */
    private function nextAfterPass(Chapter $chapter, GenerationArtifact $draft, Review $review): array
    {
        // Historical auto_commit values predate the restored setting and remain
        // inert until the operator explicitly saves the new control.
        if (data_get($chapter->novel->settings, 'auto_commit_configured') !== true
            || data_get($chapter->novel->settings, 'auto_commit') !== true) {
            return [null, null];
        }

        $expectedStateVersion = $chapter->novel->canonicalStateVersion?->version;
        if ($expectedStateVersion === null || $review->generationRun?->state_version !== $expectedStateVersion) {
            throw ValidationException::withMessages([
                'auto_commit' => 'PASS Review 的 State Version 已不是当前 Canonical Story State，不能静默跳过提交。',
            ]);
        }

        $candidate = $this->artifacts->latestForChapter(
            $chapter->getKey(),
            ArtifactType::EventCandidate,
            $draft->getKey(),
            $expectedStateVersion,
        );
        if ($candidate === null) {
            throw ValidationException::withMessages([
                'auto_commit' => 'PASS Review 缺少与当前 Draft 和 State Version 匹配的 Event Candidate。',
            ]);
        }

        $patch = $this->artifacts->latestForChapter(
            $chapter->getKey(),
            ArtifactType::StatePatch,
            $candidate->getKey(),
            $expectedStateVersion,
        );
        if ($patch === null
            || (int) data_get($patch->data, 'expected_state_version', -1) !== $expectedStateVersion) {
            throw ValidationException::withMessages([
                'auto_commit' => 'PASS Review 缺少与当前 Event Candidate 匹配的 State Patch。',
            ]);
        }

        return [GenerationStage::Commit, new CommitChapterJob($chapter->getKey(), $review->getKey())];
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
        return $this->artifacts->latestForChapter($chapter->getKey(), $type);
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

    private function legacyEventRecoveryRunId(Chapter $chapter, GenerationArtifact $draft): ?int
    {
        $run = $chapter->generationRuns()
            ->where('stage', GenerationStage::EventExtraction)
            ->latest('id')
            ->first();

        return data_get($run?->context_snapshot, 'generation_preferences.route_contract_source')
            === GenerationOutputCapacityGuard::LEGACY_EVENT_RECOVERY_CONTRACT
            && (int) data_get($run?->context_snapshot, 'recovery.source.draft_artifact_id') === $draft->getKey()
            ? $run?->getKey()
            : null;
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
            ->where('stage', '!=', GenerationStage::ContentRepair)
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
        if (! $this->dispatcher->dispatch($job) && ! $this->dispatcher->isPending($job)) {
            throw new \RuntimeException('下一生成阶段未能取得待执行标记，流程没有进入队列。');
        }
    }
}
