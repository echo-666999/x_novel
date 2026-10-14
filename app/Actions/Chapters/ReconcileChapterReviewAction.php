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
use App\Services\PlanningReviewAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReconcileChapterReviewAction
{
    /** 确定性归一化重复规划审计时写入 Run 的操作合同版本。 */
    private const OPERATION_CONTRACT_VERSION = 'planning-audit-reconciliation-v1';

    public function __construct(private readonly PlanningReviewAudit $planningReviewAudit) {}

    public function execute(Chapter $chapter, string $reason, ?int $actorId = null): Review
    {
        $reason = Validator::make(compact('reason'), [
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate()['reason'];

        return DB::transaction(function () use ($chapter, $reason, $actorId): Review {
            $chapter = Chapter::query()->lockForUpdate()->with(['novel.canonicalStateVersion', 'latestPlan', 'scenes'])->findOrFail($chapter->getKey());
            $source = Review::query()
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->with(['artifact', 'generationRun'])
                ->latest('id')
                ->firstOrFail();
            if ($source->decision !== ReviewDecision::NeedsAttention) {
                throw ValidationException::withMessages(['review' => '只有需要人工处理的最新 Review 可以执行确定性验收归一化。']);
            }

            $sourceData = $source->artifact->data ?? [];
            $draft = GenerationArtifact::query()->findOrFail((int) data_get($sourceData, 'source_artifact_id'));
            $normalized = $this->planningReviewAudit->validate($sourceData, $chapter, $draft);
            if (data_get($normalized, 'arc_beat_audits') === data_get($sourceData, 'arc_beat_audits')) {
                throw ValidationException::withMessages(['review' => '当前 Review 没有可确定性归一化的重复规划验收。']);
            }

            $preservedFindings = collect($source->findings)
                ->reject(fn (array $finding): bool => ($finding['source'] ?? null) === 'planning_contract_review')
                ->reject(fn (array $finding): bool => ($finding['code'] ?? null) === 'REWRITE_SCOPE_UNRESOLVED')
                ->values()
                ->all();
            $remainingFindings = [
                ...$preservedFindings,
                ...$this->planningReviewAudit->findings($normalized),
            ];
            $blocking = collect($remainingFindings)->contains(fn (array $finding): bool => in_array($finding['severity'] ?? null, ['error', 'hard', 'ambiguous'], true)
                || (bool) ($finding['requires_human_decision'] ?? false));
            if ($blocking || (float) $source->score < (float) config('generation.review_pass_score', 80)) {
                throw ValidationException::withMessages(['review' => '归一化后仍存在阻塞问题或评分未达 PASS，不能创建修复后的 PASS Review。']);
            }

            $stateVersion = $chapter->novel->canonicalStateVersion?->version;
            if ($stateVersion === null || $source->generationRun->state_version !== $stateVersion) {
                throw ValidationException::withMessages(['review' => 'Review 使用的故事状态版本已经过期。']);
            }

            $key = 'review:planning-audit-reconciliation:'.$chapter->getKey().':'.$source->getKey().':'.hash('sha256', json_encode(data_get($normalized, 'arc_beat_audits'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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
                'prompt_version' => self::OPERATION_CONTRACT_VERSION,
                'model_policy' => 'deterministic',
                'context_snapshot' => [
                    'source_review_id' => $source->getKey(),
                    'source_artifact_id' => $draft->getKey(),
                    'reason' => $reason,
                    'actor_id' => $actorId,
                ],
                'started_at' => now(),
                'finished_at' => now(),
            ]);
            $data = [
                ...$sourceData,
                ...$normalized,
                'decision' => ReviewDecision::Pass->value,
                'decision_basis' => [
                    'rule' => 'deterministic_planning_audit_reconciliation',
                    'source_review_id' => $source->getKey(),
                    'score' => (float) $source->score,
                    'pass_score' => (float) config('generation.review_pass_score', 80),
                ],
                'findings' => $remainingFindings,
                'rewrite_scope' => null,
                'repair_advice' => null,
                'planning_audit_reconciliation' => [
                    'source_review_id' => $source->getKey(),
                    'reason' => $reason,
                    'actor_id' => $actorId,
                ],
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
                'findings' => $remainingFindings,
            ]);
            $chapter->update(['status' => ChapterStatus::Review]);

            Log::warning('章节 Review 的重复规划验收已确定性归一化。', [
                'chapter_id' => $chapter->getKey(),
                'source_review_id' => $source->getKey(),
                'review_id' => $review->getKey(),
                'artifact_id' => $artifact->getKey(),
                'reason' => $reason,
                'actor_id' => $actorId,
            ]);

            return $review;
        }, 3);
    }
}
