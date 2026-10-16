<?php

namespace App\Actions\Chapters;

use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\ReviewChapterJob;
use App\Models\Chapter;
use App\Models\Review;
use App\Services\ChapterRepairRecommendation;
use App\Services\GenerationArtifactLocator;
use App\Services\GenerationJobDispatcher;
use App\Services\GenerationOutputCapacityGuard;
use App\Services\ReviewEvidenceRepairer;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 将可安全重试的人工处理项交给既有生成链，绝不代替用户修改正式事实或裁定审校。 */
final class RequestAiChapterRepairAction
{
    public function __construct(
        private readonly ChapterRepairRecommendation $recommendations,
        private readonly RegenerateSceneSequenceAction $regenerateScenes,
        private readonly GenerationOutputCapacityGuard $capacityGuard,
        private readonly GenerationJobDispatcher $dispatcher,
        private readonly GenerationArtifactLocator $artifacts,
        private readonly ReviewEvidenceRepairer $reviewEvidenceRepairer,
    ) {}

    /** @return array{mode: string, scene_id: int|null, description: string}|null */
    public function preview(Chapter $chapter): ?array
    {
        $chapter = Chapter::query()->with(['novel', 'latestPlan'])->findOrFail($chapter->getKey());
        if (! in_array($chapter->novel->status, [NovelStatus::Generating, NovelStatus::Completing], true)
            || in_array($chapter->status, [ChapterStatus::Canonical, ChapterStatus::Void], true)
            || $chapter->latestPlan?->status !== PlanStatus::Ready
            || $this->dispatcher->isPending(new ReviewChapterJob($chapter->getKey()))
            || $chapter->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
            return null;
        }

        $rows = $this->recommendations->forChapter($chapter);
        if ($rows === [] || collect($rows)->contains(fn (array $row): bool => $row['recovery_key'] === 'canonical_fact')) {
            return null;
        }

        $planningBoundary = (int) $chapter->generationRuns()
            ->where('stage', GenerationStage::ChapterPlanning)
            ->where('status', RunStatus::Succeeded)
            ->latest('id')->value('id');
        $lastStageRun = $chapter->generationRuns()
            ->where('id', '>', $planningBoundary)
            ->where('stage', '!=', GenerationStage::ContentRepair)
            ->latest('id')->first();

        if ($lastStageRun?->status === RunStatus::Failed) {
            $errorCode = strtoupper((string) $lastStageRun->error_code);
            if (data_get($lastStageRun->error_metadata, 'category') === 'state_conflict'
                || collect(['LOCKED_FACT', 'CANONICAL', 'STATE_VERSION', 'KNOWLEDGE_CONFLICT'])
                    ->contains(fn (string $term): bool => str_contains($errorCode, $term))) {
                return null;
            }
            if ($lastStageRun->stage === GenerationStage::SceneGeneration && $lastStageRun->scene_id !== null) {
                return $this->scenePreview($chapter, (int) $lastStageRun->scene_id);
            }
            if ($lastStageRun->stage === GenerationStage::EventExtraction
                && $lastStageRun->error_code === 'event_validation_failed'
                && $chapter->scenes()->whereNull('current_artifact_id')->doesntExist()
                && $this->artifacts->latestForChapter($chapter->getKey(), ArtifactType::ChapterDraft) !== null) {
                return [
                    'mode' => 'event_extraction',
                    'scene_id' => null,
                    'description' => '重新请求 AI 提取当前章节草稿的故事事件；成功后继续状态补丁和审校。',
                ];
            }
            if ($lastStageRun->stage === GenerationStage::Review
                && $lastStageRun->error_code === 'review_evidence_output_invalid'
                && $this->reviewEvidenceRepairer->hasPendingCheckpoint(
                    $chapter,
                    $lastStageRun,
                    $this->artifacts->latestForChapter($chapter->getKey(), [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft]),
                )) {
                return [
                    'mode' => 'review_evidence',
                    'scene_id' => null,
                    'description' => '复用已保存的审校响应，只请求 AI 修复未逐字命中的证据，再继续审校；不会重新生成正文。',
                ];
            }

            return null;
        }

        $review = Review::query()
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('id', '>', $planningBoundary))
            ->latest('id')->first();
        if ($review?->decision !== ReviewDecision::NeedsAttention || $lastStageRun?->stage !== GenerationStage::Review) {
            return null;
        }

        $findings = collect($review->findings ?? []);
        if ($findings->isEmpty() || $findings->contains(fn (mixed $finding): bool => ! is_array($finding)
            || ($finding['auto_fixable'] ?? false) !== true
            || ($finding['requires_human_decision'] ?? false) === true
            || ($finding['severity'] ?? null) === 'hard')) {
            return null;
        }
        if (collect($rows)->contains(fn (array $row): bool => $row['recovery_key'] !== 'regenerate_scene'
            || $row['scene_id'] === null)) {
            return null;
        }

        $sceneIds = array_column($rows, 'scene_id');
        $scene = $chapter->scenes()->whereIn('id', $sceneIds)->orderBy('sequence')->first();

        return $scene === null ? null : $this->scenePreview($chapter, $scene->getKey());
    }

    /** @return array{mode: string, scene_id: int|null, affected_scenes: int|null} */
    public function handle(Chapter $chapter): array
    {
        $preview = $this->preview($chapter);
        if ($preview === null) {
            throw ValidationException::withMessages([
                'repair' => '当前问题不能安全地交给 AI 自动重试；请按修复建议处理计划、正式事实或需要人工裁定的 Finding。',
            ]);
        }

        if ($preview['mode'] === 'event_extraction') {
            $this->capacityGuard->assertEventExtractionDispatchable($chapter->fresh());
            if (! $this->dispatcher->dispatch(new ExtractStoryEventsJob($chapter->getKey(), true, true))) {
                throw ValidationException::withMessages(['repair' => '事件提取任务已在队列中，请等待当前任务完成。']);
            }

            return ['mode' => 'event_extraction', 'scene_id' => null, 'affected_scenes' => null];
        }

        if ($preview['mode'] === 'review_evidence') {
            $run = $chapter->generationRuns()
                ->where('stage', '!=', GenerationStage::ContentRepair)
                ->latest('id')->firstOrFail();
            $operationId = data_get($run->context_snapshot, 'operation_id');
            $job = new ReviewChapterJob(
                $chapter->getKey(),
                is_string($operationId) && $operationId !== '',
                is_string($operationId) && $operationId !== '' ? $operationId : null,
            );
            if (! $this->dispatcher->dispatch($job)) {
                throw ValidationException::withMessages(['repair' => '审校证据修复任务已在队列中，请等待当前任务完成。']);
            }

            return ['mode' => 'review_evidence', 'scene_id' => null, 'affected_scenes' => null];
        }

        $scene = $chapter->scenes()->findOrFail($preview['scene_id']);
        $job = new GenerateSceneJob($scene->getKey(), cascade: true);
        if (! $this->dispatcher->reserve($job)) {
            throw ValidationException::withMessages(['repair' => '场景生成任务已在队列中，请等待当前任务完成。']);
        }

        try {
            $count = $this->regenerateScenes->handle($scene);
        } catch (Throwable $exception) {
            $this->dispatcher->release($job);

            throw $exception;
        }

        return ['mode' => 'scene_regeneration', 'scene_id' => $scene->getKey(), 'affected_scenes' => $count];
    }

    /** @return array{mode: string, scene_id: int, description: string}|null */
    private function scenePreview(Chapter $chapter, int $sceneId): ?array
    {
        $scene = $chapter->scenes()->find($sceneId);
        if ($scene === null || $this->regenerateScenes->requiresNewPlanBeforeRetry($scene)) {
            return null;
        }

        return [
            'mode' => 'scene_regeneration',
            'scene_id' => $scene->getKey(),
            'description' => "从 Scene {$scene->sequence} 开始请求 AI 级联重建场景，并重新组装、提取事件和审校；历史产物保留。",
        ];
    }
}
