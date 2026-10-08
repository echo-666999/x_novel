<?php

namespace App\Actions\Chapters;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\Review;
use App\Services\AutomaticRewriteCounter;
use App\Services\ChapterStageRouteResolver;
use App\Services\LegacyAdmissionRecoveryContract;
use App\Services\PlanCoverageJudgmentRepairer;
use App\Services\RewriteScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 从当前 Review 启动唯一合法的局部修复路径，避免页面直接猜测 Rewrite 目标。 */
final class StartChapterRewriteAction
{
    /** 注入恢复合同、Coverage 复核、局部范围解析和统一推进器。 */
    public function __construct(
        private readonly RecoverLegacyChapterPipelineAction $recoverLegacyPipeline,
        private readonly LegacyAdmissionRecoveryContract $legacyRecovery,
        private readonly PlanCoverageJudgmentRepairer $coverageJudgment,
        private readonly RewriteScopeResolver $rewriteScopeResolver,
        private readonly AutomaticRewriteCounter $rewriteCounter,
        private readonly ChapterStageRouteResolver $routeResolver,
        private readonly AdvanceChapterPipelineAction $advance,
    ) {}

    /**
     * 旧 Admission 先冻结完整恢复合同；普通章节则按当前来源决定 Judgment、Review 或局部 Rewrite。
     *
     * @return array{mode: string, stage: GenerationStage|null, scene_id: int|null, recovery_run_id: int|null}
     */
    public function handle(Chapter $chapter): array
    {
        $chapter = $this->chapter($chapter->getKey());
        $this->assertBaseState($chapter);

        $draft = $this->latestDraft($chapter);
        $review = $this->latestReviewForDraft($chapter, $draft);
        $this->assertRewriteReview($chapter, $review);

        if ($this->legacyRecovery->appliesTo($chapter)) {
            // Admission v1 不能临时读取当前路由；用户点击修复时必须先建立或复用完整恢复合同。
            $run = $this->recoverLegacyPipeline->handle($chapter);

            return [
                'mode' => 'legacy_recovery',
                'stage' => null,
                'scene_id' => null,
                'recovery_run_id' => $run->getKey(),
            ];
        }

        $coverageCurrent = $this->coverageJudgment->reviewCoversCurrentJudgments($review, $chapter, $draft);
        if (! $coverageCurrent) {
            return $this->resumeFromStaleCoverageReview($chapter, $draft);
        }

        $scope = $this->rewriteScopeResolver->resolveReview($chapter, $review);
        if (! $scope->isResolved() || $scope->sceneId === null) {
            throw ValidationException::withMessages([
                'rewrite' => '当前 Review 无法定位到可安全自动修改的单个 Scene；请按页面修复建议重建 Plan/Scene 或人工处理。',
            ]);
        }
        if ($this->rewriteCounter->countFor($chapter) >= (int) config('generation.max_rewrite_attempts', 2)) {
            throw ValidationException::withMessages(['rewrite' => '自动局部重写次数已耗尽，请改用人工修订。']);
        }
        if ($chapter->status === ChapterStatus::Blocked) {
            throw ValidationException::withMessages([
                'rewrite' => '当前章节因非 Coverage 原因阻塞；请先查看运行记录中的错误与推荐动作，不能直接重新请求 Rewrite。',
            ]);
        }

        $source = $chapter->scenes->firstWhere('id', $scope->sceneId)?->currentArtifact;
        if (! $source instanceof GenerationArtifact) {
            throw ValidationException::withMessages(['rewrite' => '当前 Review 指向的 Scene 缺少可验证正文。']);
        }
        // 页面派发前先验证冻结路由与恢复来源；Worker 仍会重复门禁以覆盖并发变化。
        $this->routeResolver->resolve($chapter, AiStage::Rewrite, $source);
        $stage = $this->advance->handle($chapter->getKey());
        if ($stage !== GenerationStage::Rewrite) {
            throw ValidationException::withMessages([
                'rewrite' => '统一推进器未选择局部 Rewrite；请刷新页面并检查最新 Review 与运行记录。',
            ]);
        }

        return [
            'mode' => 'rewrite',
            'stage' => $stage,
            'scene_id' => $scope->sceneId,
            'recovery_run_id' => null,
        ];
    }

    /** 旧 Coverage Review 只负责触发重新判定，不能直接成为正文 Rewrite 来源。 */
    private function resumeFromStaleCoverageReview(Chapter $chapter, GenerationArtifact $draft): array
    {
        $previousStatus = $chapter->status;

        DB::transaction(function () use ($chapter): void {
            $locked = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            if ($locked->status === ChapterStatus::Blocked) {
                // 只在已确认 Review 早于 Coverage Judgment 时解除旧阻塞，其他 Block 不得复用此入口。
                $locked->update(['status' => ChapterStatus::Generating]);
            }
        });

        try {
            // Reviewer 冻结路由同时供独立 Coverage Judgment 使用，派发前先验证合同存在。
            $this->routeResolver->resolve($chapter->fresh(['latestPlan', 'scenes.currentArtifact']), AiStage::Reviewer, $draft);
            $stage = $this->advance->handle($chapter->getKey());
            if (! in_array($stage, [GenerationStage::CoverageJudgment, GenerationStage::Review], true)) {
                throw ValidationException::withMessages([
                    'rewrite' => '旧 Coverage Review 已失效，但统一推进器没有找到可执行的 Coverage 复核或重新审校阶段。',
                ]);
            }
        } catch (Throwable $exception) {
            if ($previousStatus === ChapterStatus::Blocked) {
                Chapter::query()->whereKey($chapter->getKey())->update(['status' => ChapterStatus::Blocked]);
            }

            throw $exception;
        }

        return [
            'mode' => 'coverage_recheck',
            'stage' => $stage,
            'scene_id' => $this->coverageJudgment->pendingSceneId($chapter->fresh(['scenes.currentArtifact']), $draft),
            'recovery_run_id' => null,
        ];
    }

    /** 阻止暂停、正式章节和并发 Run 从页面重复进入修复流程。 */
    private function assertBaseState(Chapter $chapter): void
    {
        if ($chapter->novel->status === NovelStatus::Paused) {
            throw ValidationException::withMessages(['rewrite' => '小说已暂停，不能启动局部重写。']);
        }
        if (in_array($chapter->status, [ChapterStatus::Canonical, ChapterStatus::Void], true)) {
            throw ValidationException::withMessages(['rewrite' => '正式或已废弃章节不能启动局部重写。']);
        }
        if ($chapter->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
            throw ValidationException::withMessages(['rewrite' => '当前章节已有排队中或运行中的生成任务。']);
        }
    }

    /** 读取当前章节级草稿，Scene Artifact 不能冒充 Review 的完整正文来源。 */
    private function latestDraft(Chapter $chapter): GenerationArtifact
    {
        $draft = GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->whereNull('scene_id')
                ->whereIn('status', [RunStatus::Succeeded, RunStatus::Failed]))
            ->with('generationRun')
            ->latest('id')
            ->first();

        if (! $draft instanceof GenerationArtifact) {
            throw ValidationException::withMessages(['rewrite' => '当前章节缺少可审校的完整草稿。']);
        }

        return $draft;
    }

    /** 只读取与当前草稿绑定的最新 Review，避免旧结论驱动新正文。 */
    private function latestReviewForDraft(Chapter $chapter, GenerationArtifact $draft): ?Review
    {
        return Review::query()
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('state_version', $chapter->novel->canonicalStateVersion?->version)
                ->where('status', RunStatus::Succeeded))
            ->with(['artifact', 'generationRun'])
            ->latest('id')
            ->get()
            ->first(fn (Review $review): bool => (int) data_get($review->artifact?->data, 'source_artifact_id') === $draft->getKey());
    }

    /** 自动修复只接受 REWRITE；NEEDS_ATTENTION 必须继续停在人工判断。 */
    private function assertRewriteReview(Chapter $chapter, ?Review $review): void
    {
        if ($review?->decision !== ReviewDecision::Rewrite) {
            throw ValidationException::withMessages(['rewrite' => '当前草稿没有可自动执行的 REWRITE Review。']);
        }
        if ($chapter->latestPlan === null || $chapter->novel->canonicalStateVersion === null) {
            throw new AiProviderException('rewrite_context_incomplete', '局部重写缺少 Chapter Plan 或 Canonical State。', false);
        }
    }

    /** 加载修复预检所需的权威来源，避免沿用页面缓存中的旧状态。 */
    private function chapter(int $chapterId): Chapter
    {
        return Chapter::query()->with([
            'novel.canonicalStateVersion',
            'latestPlan',
            'scenes.currentArtifact.generationRun',
        ])->findOrFail($chapterId);
    }
}
