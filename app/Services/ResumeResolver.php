<?php

namespace App\Services;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Actions\Generation\CheckNextAction;
use App\Actions\Generation\GenerateNextChapterAction;
use App\Data\ResumePoint;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResumeResolver
{
    public function __construct(
        private readonly GenerateNextChapterAction $generateNextChapter,
        private readonly AdvanceChapterPipelineAction $advanceChapterPipeline,
        private readonly CheckNextAction $checkNextAction,
        private readonly RewriteScopeResolver $rewriteScopeResolver,
    ) {}

    public function detect(Novel $novel): ResumePoint
    {
        $novel = Novel::query()->findOrFail($novel->getKey());

        if ($novel->status !== NovelStatus::Paused) {
            throw ValidationException::withMessages(['novel' => '只有已暂停的小说可以检测恢复点。']);
        }

        $chapter = $this->activeChapter($novel);

        if ($chapter === null) {
            $canonical = $novel->chapters()
                ->where('sequence', $novel->current_chapter_sequence)
                ->where('status', ChapterStatus::Canonical)
                ->first();

            return $canonical === null
                ? new ResumePoint('plan', '章节规划')
                : new ResumePoint('post_commit', '正式提交后的下一步检查', $canonical->getKey());
        }

        $draft = $this->latestDraft($chapter);
        $review = $draft === null ? null : $this->latestReview($chapter, $draft);

        if ($review?->decision === ReviewDecision::Pass) {
            return new ResumePoint('awaiting_commit', '等待提交正式章节', $chapter->getKey(), reviewId: $review->getKey());
        }

        if ($review?->decision === ReviewDecision::Rewrite) {
            $scopeDecision = $this->rewriteScopeResolver->resolveReview($chapter, $review);

            if (! $scopeDecision->isResolved()) {
                return new ResumePoint('blocked', '无法确定安全的重写范围，需要人工处理', $chapter->getKey(), canResume: false);
            }

            return new ResumePoint(
                'rewrite',
                $scopeDecision->scope === 'scene' ? '场景重写' : '整章重写',
                $chapter->getKey(),
                $scopeDecision->sceneId,
            );
        }

        if (in_array($review?->decision, [ReviewDecision::NeedsAttention, ReviewDecision::Block], true)) {
            return new ResumePoint('blocked', '需要人工处理审校结果', $chapter->getKey(), canResume: false);
        }

        if ($draft !== null) {
            $candidate = $this->currentEventCandidate($chapter, $draft);
            if ($candidate === null) {
                return new ResumePoint('event_extraction', '故事事件提取', $chapter->getKey());
            }

            if ($this->currentStatePatch($chapter, $candidate) === null) {
                return new ResumePoint('review_preparation', '状态补丁与章节审校', $chapter->getKey());
            }

            return new ResumePoint('review', '章节审校', $chapter->getKey());
        }

        if (! $chapter->plans()->where('status', PlanStatus::Ready)->exists()) {
            return new ResumePoint('plan', '章节规划', $chapter->getKey());
        }

        $scenes = $chapter->scenes()->orderBy('sequence')->get();
        if ($scenes->isNotEmpty()) {
            $incomplete = $scenes->first(fn ($scene): bool => $scene->current_artifact_id === null
                || ! in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true));

            return $incomplete === null
                ? new ResumePoint('assemble', '章节组装', $chapter->getKey())
                : new ResumePoint('scene', '场景 '.$incomplete->sequence, $chapter->getKey(), $incomplete->getKey());
        }

        return new ResumePoint('scene', '场景 1', $chapter->getKey());
    }

    public function resume(Novel $novel): ResumePoint
    {
        $point = DB::transaction(function () use ($novel): ResumePoint {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $point = $this->detect($lockedNovel);

            if (! $point->canResume) {
                throw ValidationException::withMessages(['resume' => '当前恢复点需要先人工处理，不能自动继续。']);
            }

            $previousStatus = data_get($lockedNovel->settings, 'pause.previous_status');
            $status = NovelStatus::tryFrom((string) $previousStatus);
            if (! in_array($status, [NovelStatus::Generating, NovelStatus::Completing], true)) {
                $status = NovelStatus::Generating;
            }

            $settings = $lockedNovel->settings ?? [];
            $settings['pause']['resumed_at'] = now()->toISOString();
            if (data_get($settings, 'auto_stop.code') === 'user_pause') {
                unset($settings['auto_stop']);
            }
            $lockedNovel->update(['status' => $status, 'settings' => $settings]);

            return $point;
        });

        $this->advance($novel->fresh(), $point);

        return $point;
    }

    private function activeChapter(Novel $novel): ?Chapter
    {
        $nextSequence = ($novel->current_chapter_sequence ?? 0) + 1;

        return $novel->chapters()
            ->where('sequence', $nextSequence)
            ->where('status', '!=', ChapterStatus::Canonical)
            ->first();
    }

    private function latestReview(Chapter $chapter, GenerationArtifact $draft): ?Review
    {
        $stateVersion = $chapter->novel->canonicalStateVersion?->version;

        return Review::query()
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('state_version', $stateVersion)
                ->where('status', RunStatus::Succeeded))
            ->with('artifact')
            ->latest('id')
            ->get()
            ->first(fn (Review $review): bool => (int) data_get($review->artifact?->data, 'source_artifact_id') === $draft->getKey());
    }

    private function latestDraft(Chapter $chapter): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->whereNull('scene_id')
                ->whereIn('status', [RunStatus::Succeeded, RunStatus::Failed]))
            ->latest('id')
            ->first();
    }

    private function currentEventCandidate(Chapter $chapter, GenerationArtifact $draft): ?GenerationArtifact
    {
        $stateVersion = $chapter->novel->canonicalStateVersion?->version;

        return GenerationArtifact::query()
            ->where('type', ArtifactType::EventCandidate)
            ->where('data->source_artifact_id', $draft->getKey())
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('state_version', $stateVersion)
                ->whereIn('status', [RunStatus::Succeeded, RunStatus::Failed]))
            ->latest('id')
            ->first();
    }

    private function currentStatePatch(Chapter $chapter, GenerationArtifact $candidate): ?GenerationArtifact
    {
        $stateVersion = $chapter->novel->canonicalStateVersion?->version;

        return GenerationArtifact::query()
            ->where('type', ArtifactType::StatePatch)
            ->where('data->source_artifact_id', $candidate->getKey())
            ->where('data->expected_state_version', $stateVersion)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->whereIn('status', [RunStatus::Succeeded, RunStatus::Failed]))
            ->latest('id')
            ->first();
    }

    private function advance(Novel $novel, ResumePoint $point): void
    {
        if ($point->key === 'post_commit') {
            $this->checkNextAction->handle($novel, (int) $point->chapterId);

            return;
        }

        if ($point->key === 'awaiting_commit') {
            return;
        }

        $chapterId = $point->chapterId;
        if ($chapterId === null) {
            $chapterId = $this->generateNextChapter->handle($novel)->getKey();
        }

        $this->advanceChapterPipeline->handle((int) $chapterId);
    }
}
