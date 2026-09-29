<?php

namespace App\Actions\Chapters;

use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\AssembleChapterJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use App\Models\Scene;
use App\Services\ChapterRepairRecommendation;
use App\Services\DraftLengthPolicy;
use App\Services\GenerationStageGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManuallyReviseChapterAction
{
    public function __construct(
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly GenerationStageGate $stageGate,
        private readonly ChapterRepairRecommendation $recommendation,
        private readonly RegenerateSceneSequenceAction $regenerateScenes,
    ) {}

    public function execute(Chapter $chapter, int $sceneId, string $content, string $reason, ?int $actorId = null): GenerationArtifact
    {
        $validated = Validator::make(compact('sceneId', 'content', 'reason'), [
            'sceneId' => ['required', 'integer'],
            'content' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        [$artifact, $created, $nextSceneId] = DB::transaction(function () use ($chapter, $validated, $actorId): array {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());
            if ($chapter->novel->status === NovelStatus::Paused) {
                throw ValidationException::withMessages(['chapter' => '小说已暂停，不能开始人工修订后的生成流程。']);
            }
            if ($chapter->status === ChapterStatus::Canonical) {
                throw ValidationException::withMessages(['chapter' => '正式章节不能通过草稿审校入口修改。']);
            }

            $review = $this->latestReview($chapter);
            if (! in_array($review->decision, [ReviewDecision::NeedsAttention, ReviewDecision::Block], true)) {
                throw ValidationException::withMessages(['review' => '只有需要人工处理或已阻塞的 Review 可以人工修改。']);
            }

            $scene = $chapter->scenes()->lockForUpdate()->find($validated['sceneId']);
            if ($scene === null || $scene->current_artifact_id === null) {
                throw ValidationException::withMessages(['sceneId' => '请选择当前章节中已有正文的 Scene。']);
            }
            $downstreamSceneIds = $chapter->scenes()
                ->where('sequence', '>', $scene->sequence)
                ->pluck('id');
            if ($downstreamSceneIds->isNotEmpty() && $chapter->generationRuns()
                ->whereIn('scene_id', $downstreamSceneIds)
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
                ->exists()) {
                throw ValidationException::withMessages(['sceneId' => '后续 Scene 正在生成，请等待当前任务完成后再人工修订。']);
            }

            $recommendations = collect($this->recommendation->forChapter($chapter));
            if ($recommendations->contains(fn (array $row): bool => in_array($row['recovery_key'], ['canonical_fact', 'edit_plan', 'restart_from_outline'], true))) {
                throw ValidationException::withMessages(['review' => '当前问题涉及 Plan、Milestone、Locked Fact 或 Canonical State，不能通过修改正文绕过。请按修复建议使用结构化入口。']);
            }
            if (! $recommendations->contains(fn (array $row): bool => $row['recovery_key'] === 'manual_scene_edit'
                && (int) $row['scene_id'] === $scene->getKey())) {
                throw ValidationException::withMessages(['sceneId' => '当前 Scene 没有可通过人工正文修订处理的局部文字 Finding。']);
            }

            $source = GenerationArtifact::query()->findOrFail($scene->current_artifact_id);
            if (trim($validated['content']) === trim((string) $source->content)) {
                throw ValidationException::withMessages(['content' => 'Scene 正文没有变化，请修改后再保存。']);
            }

            $inputHash = hash('sha256', json_encode([
                'source_artifact_id' => $source->getKey(),
                'source_review_id' => $review->getKey(),
                'scene_id' => $scene->getKey(),
                'content' => $validated['content'],
                'reason' => $validated['reason'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $key = 'rewrite:manual:'.$chapter->getKey().':'.$inputHash;
            $existing = GenerationRun::query()->where('idempotency_key', $key)->with('artifacts')->first();
            if ($existing?->artifacts->first() !== null) {
                return [$existing->artifacts->first(), false, null];
            }

            $attempt = (int) $chapter->generationRuns()->where('stage', GenerationStage::Rewrite)->max('attempt') + 1;
            $version = (int) GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->max('version') + 1;
            $run = GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scene_id' => $scene->getKey(),
                'scope_type' => 'scene',
                'scope_id' => $scene->getKey(),
                'stage' => GenerationStage::Rewrite,
                'status' => RunStatus::Succeeded,
                'attempt' => $attempt,
                'idempotency_key' => $key,
                'input_hash' => $inputHash,
                'state_version' => $chapter->novel->canonicalStateVersion?->version,
                'prompt_version' => 'manual-scene-edit-v1',
                'model_policy' => 'manual',
                'context_snapshot' => [
                    'manual_edit' => true,
                    'source_artifact_id' => $source->getKey(),
                    'source_review_id' => $review->getKey(),
                    'scene_id' => $scene->getKey(),
                    'reason' => $validated['reason'],
                    'actor_id' => $actorId,
                ],
                'started_at' => now(),
                'finished_at' => now(),
            ]);
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::RewriteDraft,
                'version' => $version,
                'content' => trim($validated['content']),
                'data' => [
                    'scope' => 'scene',
                    'manual_edit' => true,
                    'manual_reason' => $validated['reason'],
                    'actor_id' => $actorId,
                    'source_artifact_id' => $source->getKey(),
                    'source_review_id' => $review->getKey(),
                    'foreshadowing_coverage' => data_get($source->data, 'foreshadowing_coverage', []),
                    'plan_findings' => data_get($source->data, 'plan_findings', []),
                    'word_count' => $this->lengthPolicy->count($validated['content']),
                ],
                'checksum' => hash('sha256', trim($validated['content'])),
            ]);
            $scene->update([
                'status' => SceneStatus::Draft,
                'current_artifact_id' => $artifact->getKey(),
            ]);
            $chapter->update(['status' => ChapterStatus::Rewrite]);

            $nextSceneId = $chapter->scenes()
                ->where('sequence', '>', $scene->sequence)
                ->orderBy('sequence')
                ->value('id');

            Log::notice('Scene 正文已人工修改。', [
                'chapter_id' => $chapter->getKey(),
                'scene_id' => $scene->getKey(),
                'source_review_id' => $review->getKey(),
                'source_artifact_id' => $source->getKey(),
                'artifact_id' => $artifact->getKey(),
                'reason' => $validated['reason'],
                'actor_id' => $actorId,
            ]);

            return [$artifact, true, $nextSceneId === null ? null : (int) $nextSceneId];
        }, 3);

        if ($created) {
            if ($nextSceneId !== null) {
                $this->regenerateScenes->handle(Scene::query()->findOrFail($nextSceneId));
            } else {
                $this->stageGate->dispatchForChapter(
                    $chapter->getKey(),
                    fn () => AssembleChapterJob::dispatch($chapter->getKey(), true, true),
                );
            }
        }

        return $artifact;
    }

    private function latestReview(Chapter $chapter): Review
    {
        $review = Review::query()
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->with('artifact')
            ->latest('id')
            ->first();

        if ($review === null) {
            throw ValidationException::withMessages(['review' => '当前章节没有可处理的 Review。']);
        }

        return $review;
    }
}
