<?php

namespace App\Actions\Chapters;

use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OverrideChapterReviewAction
{
    public function execute(Chapter $chapter, string $reason, ?int $actorId = null): Review
    {
        $validated = Validator::make(compact('reason'), [
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($chapter, $validated, $actorId): Review {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());
            $source = Review::query()
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->with(['artifact', 'generationRun'])
                ->latest('id')
                ->first();
            if ($source?->decision !== ReviewDecision::NeedsAttention) {
                throw ValidationException::withMessages(['review' => '只有需要人工处理的最新 Review 可以人工通过。']);
            }
            if (collect($source->findings)->contains(fn (array $finding): bool => data_get($finding, 'severity') === 'hard')) {
                throw ValidationException::withMessages(['review' => '当前 Review 存在硬冲突，不能人工通过。']);
            }

            $draftId = (int) data_get($source->artifact->data, 'source_artifact_id');
            $draft = GenerationArtifact::query()->findOrFail($draftId);
            $stateVersion = $chapter->novel->canonicalStateVersion?->version;
            if ($stateVersion === null || $source->generationRun->state_version !== $stateVersion) {
                throw ValidationException::withMessages(['review' => 'Review 使用的故事状态版本已经过期，请先重新审校。']);
            }

            $key = 'review:manual-override:'.$chapter->getKey().':'.$source->getKey().':'.hash('sha256', $validated['reason']);
            $existing = GenerationRun::query()->where('idempotency_key', $key)->with('review')->first();
            if ($existing?->review !== null) {
                return $existing->review;
            }

            $attempt = (int) $chapter->generationRuns()->where('stage', GenerationStage::Review)->max('attempt') + 1;
            $version = (int) GenerationArtifact::query()->where('type', ArtifactType::ReviewResult)
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->max('version') + 1;
            $run = GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::Review,
                'status' => RunStatus::Succeeded,
                'attempt' => $attempt,
                'idempotency_key' => $key,
                'input_hash' => hash('sha256', $key),
                'state_version' => $stateVersion,
                'prompt_version' => 'manual-override-v1',
                'model_policy' => 'manual',
                'context_snapshot' => [
                    'manual_override' => true,
                    'source_review_id' => $source->getKey(),
                    'source_artifact_id' => $draft->getKey(),
                    'reason' => $validated['reason'],
                    'actor_id' => $actorId,
                ],
                'started_at' => now(),
                'finished_at' => now(),
            ]);
            $data = [
                'decision' => ReviewDecision::Pass->value,
                'recommended_decision' => $source->decision->value,
                'score' => (float) $source->score,
                'scores' => [
                    'continuity' => (float) $source->continuity_score,
                    'plan' => (float) $source->plan_score,
                    'character' => (float) $source->character_score,
                    'progress' => (float) $source->progress_score,
                    'repetition' => (float) $source->repetition_score,
                    'pacing' => (float) $source->pacing_score,
                    'style' => (float) $source->style_score,
                ],
                'findings' => [],
                'source_artifact_id' => $draft->getKey(),
                'manual_override' => true,
                'manual_override_reason' => $validated['reason'],
                'source_review_id' => $source->getKey(),
                'overridden_findings' => $source->findings,
                'actor_id' => $actorId,
            ];
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::ReviewResult,
                'version' => $version,
                'content' => $encoded,
                'data' => $data,
                'checksum' => hash('sha256', $encoded),
            ]);
            $review = $run->review()->create([
                'artifact_id' => $artifact->getKey(),
                'decision' => ReviewDecision::Pass,
                'score' => $source->score,
                'continuity_score' => $source->continuity_score,
                'plan_score' => $source->plan_score,
                'character_score' => $source->character_score,
                'progress_score' => $source->progress_score,
                'repetition_score' => $source->repetition_score,
                'pacing_score' => $source->pacing_score,
                'style_score' => $source->style_score,
                'findings' => [],
            ]);
            $chapter->update(['status' => ChapterStatus::Review]);

            Log::warning('章节审校已人工 Override 为通过。', [
                'chapter_id' => $chapter->getKey(),
                'source_review_id' => $source->getKey(),
                'review_id' => $review->getKey(),
                'artifact_id' => $artifact->getKey(),
                'reason' => $validated['reason'],
                'actor_id' => $actorId,
            ]);

            return $review;
        }, 3);
    }
}
