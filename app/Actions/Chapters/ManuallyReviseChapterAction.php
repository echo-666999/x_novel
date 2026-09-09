<?php

namespace App\Actions\Chapters;

use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Jobs\ExtractStoryEventsJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
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
    ) {}

    public function execute(Chapter $chapter, string $content, string $reason, ?int $actorId = null): GenerationArtifact
    {
        $validated = Validator::make(compact('content', 'reason'), [
            'content' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        [$artifact, $created] = DB::transaction(function () use ($chapter, $validated, $actorId): array {
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
            $source = GenerationArtifact::query()->findOrFail((int) data_get($review->artifact->data, 'source_artifact_id'));
            if (trim($validated['content']) === trim((string) $source->content)) {
                throw ValidationException::withMessages(['content' => '正文没有变化，请修改后再保存。']);
            }

            $inputHash = hash('sha256', json_encode([
                'source_artifact_id' => $source->getKey(),
                'source_review_id' => $review->getKey(),
                'content' => $validated['content'],
                'reason' => $validated['reason'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $key = 'rewrite:manual:'.$chapter->getKey().':'.$inputHash;
            $existing = GenerationRun::query()->where('idempotency_key', $key)->with('artifacts')->first();
            if ($existing?->artifacts->first() !== null) {
                return [$existing->artifacts->first(), false];
            }

            $attempt = (int) $chapter->generationRuns()->where('stage', GenerationStage::Rewrite)->max('attempt') + 1;
            $version = (int) GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->max('version') + 1;
            $run = GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scene_id' => null,
                'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::Rewrite,
                'status' => RunStatus::Succeeded,
                'attempt' => $attempt,
                'idempotency_key' => $key,
                'input_hash' => $inputHash,
                'state_version' => $chapter->novel->canonicalStateVersion?->version,
                'prompt_version' => 'manual-edit-v1',
                'model_policy' => 'manual',
                'context_snapshot' => [
                    'manual_edit' => true,
                    'source_artifact_id' => $source->getKey(),
                    'source_review_id' => $review->getKey(),
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
                    'scope' => 'chapter',
                    'manual_edit' => true,
                    'manual_reason' => $validated['reason'],
                    'actor_id' => $actorId,
                    'source_artifact_id' => $source->getKey(),
                    'source_review_id' => $review->getKey(),
                    'word_count' => $this->lengthPolicy->count($validated['content']),
                ],
                'checksum' => hash('sha256', trim($validated['content'])),
            ]);
            $chapter->update(['status' => ChapterStatus::Rewrite]);

            Log::notice('章节草稿已人工修改。', [
                'chapter_id' => $chapter->getKey(),
                'source_review_id' => $review->getKey(),
                'source_artifact_id' => $source->getKey(),
                'artifact_id' => $artifact->getKey(),
                'reason' => $validated['reason'],
                'actor_id' => $actorId,
            ]);

            return [$artifact, true];
        }, 3);

        if ($created) {
            $this->stageGate->dispatchForChapter(
                $chapter->getKey(),
                fn () => ExtractStoryEventsJob::dispatch($chapter->getKey(), true, true),
            );
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
