<?php

namespace App\Services;

use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\Actions\Generation\CheckNextAction;
use App\Actions\Generation\GenerateNextChapterAction;
use App\Data\ResumePoint;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\SceneStatus;
use App\Jobs\AssembleChapterJob;
use App\Jobs\CommitChapterJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
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
        private readonly CheckNextAction $checkNextAction,
        private readonly SyncScenesFromChapterPlanAction $syncScenes,
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
            return new ResumePoint('commit', '正式提交', $chapter->getKey(), reviewId: $review->getKey());
        }

        if ($review?->decision === ReviewDecision::Rewrite) {
            $sceneId = collect($review->findings)->pluck('scene_id')->first(fn (mixed $id): bool => is_numeric($id));

            return new ResumePoint('rewrite', $sceneId === null ? '整章重写' : '场景重写', $chapter->getKey(), $sceneId === null ? null : (int) $sceneId);
        }

        if (in_array($review?->decision, [ReviewDecision::NeedsAttention, ReviewDecision::Block], true)) {
            return new ResumePoint('blocked', '需要人工处理审校结果', $chapter->getKey(), canResume: false);
        }

        if ($draft !== null) {
            return new ResumePoint('review', '章节审校', $chapter->getKey());
        }

        $scenes = $chapter->scenes()->orderBy('sequence')->get();
        if ($scenes->isNotEmpty()) {
            $incomplete = $scenes->first(fn ($scene): bool => $scene->current_artifact_id === null
                || ! in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true));

            return $incomplete === null
                ? new ResumePoint('assemble', '章节组装', $chapter->getKey())
                : new ResumePoint('scene', '场景 '.$incomplete->sequence, $chapter->getKey(), $incomplete->getKey());
        }

        if ($chapter->plans()->where('status', PlanStatus::Ready)->exists()) {
            return new ResumePoint('scene', '场景 1', $chapter->getKey());
        }

        return new ResumePoint('plan', '章节规划', $chapter->getKey());
    }

    public function resume(Novel $novel): ResumePoint
    {
        return DB::transaction(function () use ($novel): ResumePoint {
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
            $this->dispatch($lockedNovel->refresh(), $point);

            return $point;
        });
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
        return Review::query()
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->with('artifact')
            ->latest('id')
            ->get()
            ->first(fn (Review $review): bool => (int) data_get($review->artifact?->data, 'source_artifact_id') === $draft->getKey());
    }

    private function latestDraft(Chapter $chapter): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->latest('id')
            ->first();
    }

    private function dispatch(Novel $novel, ResumePoint $point): void
    {
        match ($point->key) {
            'post_commit' => $this->checkNextAction->handle($novel, (int) $point->chapterId),
            'commit' => CommitChapterJob::dispatch((int) $point->chapterId, (int) $point->reviewId)->afterCommit(),
            'rewrite' => RewriteChapterJob::dispatch((int) $point->chapterId, $point->sceneId)->afterCommit(),
            'review' => ReviewChapterJob::dispatch((int) $point->chapterId)->afterCommit(),
            'assemble' => AssembleChapterJob::dispatch((int) $point->chapterId)->afterCommit(),
            'scene' => $this->dispatchScene($point),
            'plan' => $this->dispatchPlan($novel, $point),
            default => null,
        };
    }

    private function dispatchScene(ResumePoint $point): void
    {
        $sceneId = $point->sceneId;
        if ($sceneId === null) {
            $chapter = Chapter::query()->findOrFail($point->chapterId);
            $this->syncScenes->execute($chapter);
            $sceneId = $chapter->scenes()->orderBy('sequence')->value('id');
        }

        if ($sceneId === null) {
            throw ValidationException::withMessages(['resume' => 'Chapter Plan 没有可恢复的 Scene。']);
        }

        GenerateSceneJob::dispatch($sceneId)->afterCommit();
    }

    private function dispatchPlan(Novel $novel, ResumePoint $point): void
    {
        $chapter = $point->chapterId === null
            ? $this->generateNextChapter->handle($novel)
            : Chapter::query()->findOrFail($point->chapterId);

        PlanChapterJob::dispatch($chapter->getKey())->afterCommit();
    }
}
