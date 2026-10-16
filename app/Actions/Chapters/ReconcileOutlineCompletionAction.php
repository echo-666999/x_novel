<?php

namespace App\Actions\Chapters;

use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use App\Services\OutlineCompletionService;
use App\Services\StatePatchBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Resolve independent Outline judgments conservatively before Canonical Commit. */
final class ReconcileOutlineCompletionAction
{
    private const CONTRACT_VERSION = 'outline-completion-consensus-v2';

    public function __construct(
        private readonly OutlineCompletionService $outlineCompletion,
        private readonly StatePatchBuilder $statePatchBuilder,
    ) {}

    /** @return array<int, string> */
    public function conflicts(Chapter $chapter): array
    {
        if ($chapter->status !== ChapterStatus::Review || $chapter->canonical_artifact_id !== null
            || $chapter->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
            return [];
        }

        $review = $this->latestReview($chapter);
        if ($review?->decision !== ReviewDecision::Pass) {
            return [];
        }

        $candidate = $this->latestCandidate($chapter, (int) data_get($review->artifact?->data, 'source_artifact_id'));
        if ($candidate === null) {
            return [];
        }

        $extraction = data_get($candidate->data, 'outline_completion');
        $reviewData = $review->artifact->data;
        if (! is_array($extraction)) {
            return [];
        }

        $fields = [];
        foreach (['milestone_completion', 'beat_exit', 'handoff_readiness'] as $field) {
            $extracted = data_get($extraction, "{$field}.status");
            $reviewed = data_get($reviewData, "{$field}.status");
            if ($extracted !== $reviewed) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @return array<int, string> */
    public function preview(Chapter $chapter): array
    {
        $fields = $this->conflicts($chapter);
        if ($fields === []) {
            return [];
        }

        $review = $this->latestReview($chapter);
        $candidate = $this->latestCandidate($chapter, (int) data_get($review?->artifact?->data, 'source_artifact_id'));
        foreach ($fields as $field) {
            $extracted = data_get($candidate?->data, "outline_completion.{$field}.status");
            $reviewed = data_get($review?->artifact?->data, "{$field}.status");
            if (! in_array([$extracted, $reviewed], [
                ['fulfilled', 'not_met'],
                ['not_met', 'fulfilled'],
                ['ready', 'not_ready'],
                ['not_ready', 'ready'],
            ], true)) {
                return [];
            }
        }

        return $fields;
    }

    /** @return array{GenerationArtifact, GenerationArtifact, Review} Current aligned source chain. */
    public function handle(Chapter $chapter): array
    {
        return DB::transaction(function () use ($chapter): array {
            $novel = $chapter->novel()->lockForUpdate()->firstOrFail();
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $review = $this->latestReview($chapter);
            $draftId = (int) data_get($review?->artifact?->data, 'source_artifact_id');
            $candidate = $this->latestCandidate($chapter, $draftId);
            $patch = $candidate === null ? null : $this->latestPatch($chapter, $candidate);

            if ($candidate?->generationRun?->prompt_version === self::CONTRACT_VERSION
                && (int) data_get($candidate->generationRun->context_snapshot, 'source_review_id') === $review?->getKey()
                && $patch !== null) {
                return [$candidate, $patch, $review];
            }
            if ($review?->generationRun?->prompt_version === self::CONTRACT_VERSION
                && (int) data_get($review->generationRun->context_snapshot, 'source_candidate_artifact_id') === $candidate?->getKey()
                && $patch !== null) {
                return [$candidate, $patch, $review];
            }

            $fields = $this->preview($chapter);
            if ($fields === [] || $candidate === null || $patch === null || $review === null
                || $novel->canonicalStateVersion?->version !== $review->generationRun->state_version
                || $candidate->generationRun->state_version !== $review->generationRun->state_version) {
                throw ValidationException::withMessages(['outline_completion' => '当前章节没有可安全对齐的 PASS Review 与事件候选来源链。']);
            }

            $draft = GenerationArtifact::query()->findOrFail($draftId);
            $reviewData = $review->artifact->data;
            $validatedReview = $this->outlineCompletion->validateReview([
                'chapter_plan_completion' => data_get($reviewData, 'chapter_plan_completion'),
                'milestone_completion' => data_get($reviewData, 'milestone_completion'),
                'beat_exit' => data_get($reviewData, 'beat_exit'),
                'handoff_readiness' => data_get($reviewData, 'handoff_readiness'),
            ], $chapter, $draft);
            $storedExtraction = data_get($candidate->data, 'outline_completion');
            $validatedExtraction = $this->outlineCompletion->validateExtraction(collect($storedExtraction)
                ->only(['milestone_completion', 'beat_exit', 'handoff_readiness'])->all(), $chapter, $draft);
            if ($storedExtraction !== $validatedExtraction
                || data_get($reviewData, 'outline_completion_identity') !== $validatedReview['outline_completion_identity']
                || data_get($reviewData, 'outline_completion_contract_checksum') !== $validatedReview['outline_completion_contract_checksum']) {
                throw ValidationException::withMessages(['outline_completion' => 'Review 或 Event Candidate 的冻结 Outline 契约已变化。']);
            }

            $candidateDowngrades = [];
            $reviewDowngrades = [];
            $completion = [
                'milestone_completion' => $validatedExtraction['milestone_completion'],
                'beat_exit' => $validatedExtraction['beat_exit'],
                'handoff_readiness' => $validatedExtraction['handoff_readiness'],
                'identity' => $validatedExtraction['identity'],
                'contract_checksum' => $validatedExtraction['contract_checksum'],
            ];
            foreach ($fields as $field) {
                $extracted = $validatedExtraction[$field]['status'];
                if (in_array($extracted, ['fulfilled', 'ready'], true)) {
                    $completion[$field] = $validatedReview[$field];
                    $candidateDowngrades[] = $field;
                } else {
                    $reviewDowngrades[] = $field;
                }
            }
            $originalEvents = data_get($candidate->data, 'events');
            if (! is_array($originalEvents) || ! array_is_list($originalEvents)) {
                throw ValidationException::withMessages(['events' => 'Event Candidate Artifact 数据无效。']);
            }
            $completionTypes = [EventType::StoryArcBeatMilestoneCompleted->value, EventType::StoryArcBeatCompleted->value];
            $originalCompletionTypes = collect($originalEvents)->pluck('event_type')->filter(
                fn (mixed $type): bool => in_array($type, $completionTypes, true),
            )->all();
            $replacement = $this->outlineCompletion->eventCandidates($completion, $chapter, $draft);
            foreach ($replacement as $event) {
                if (! in_array($event->eventType->value, $originalCompletionTypes, true)) {
                    throw ValidationException::withMessages(['events' => 'Review 要求新增完成事件，不能用保守对齐代替事件提取。']);
                }
            }

            $events = [
                ...collect($originalEvents)->reject(fn (array $event): bool => in_array($event['event_type'] ?? null, $completionTypes, true))->values()->all(),
                ...array_map(fn (StoryEventCandidate $event): array => $event->toArray(), $replacement),
            ];
            $newCandidate = $candidate;
            $newPatch = $patch;
            if ($candidateDowngrades !== []) {
                $key = 'events:outline-completion-reconciliation:'.$chapter->getKey().':'.$candidate->getKey().':'.$review->getKey();
                $run = GenerationRun::query()->create([
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'scope_type' => 'chapter',
                    'scope_id' => $chapter->getKey(),
                    'stage' => GenerationStage::EventExtraction,
                    'status' => RunStatus::Succeeded,
                    'attempt' => (int) $chapter->generationRuns()->where('stage', GenerationStage::EventExtraction)->max('attempt') + 1,
                    'idempotency_key' => $key,
                    'input_hash' => hash('sha256', $key),
                    'state_version' => $review->generationRun->state_version,
                    'prompt_version' => self::CONTRACT_VERSION,
                    'model_policy' => 'deterministic',
                    'context_snapshot' => [
                        'source_candidate_artifact_id' => $candidate->getKey(),
                        'source_review_id' => $review->getKey(),
                        'source_artifact_id' => $draft->getKey(),
                        'downgraded_fields' => $candidateDowngrades,
                    ],
                    'started_at' => now(),
                    'finished_at' => now(),
                ]);
                $data = [
                    ...$candidate->data,
                    'outline_completion' => $completion,
                    'events' => $events,
                    'review_reconciliation' => [
                        'source_candidate_artifact_id' => $candidate->getKey(),
                        'source_review_id' => $review->getKey(),
                        'downgraded_fields' => $candidateDowngrades,
                    ],
                ];
                $version = (int) GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)
                    ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                    ->max('version') + 1;
                $newCandidate = $run->artifacts()->create([
                    'type' => ArtifactType::EventCandidate,
                    'version' => $version,
                    'content' => json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'data' => $data,
                    'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                ]);
                $newPatch = $this->statePatchBuilder->build($chapter->getKey());
            }

            $newReview = $review;
            if ($reviewDowngrades !== []) {
                $newReview = $this->createConservativeReview(
                    $chapter,
                    $review,
                    $newCandidate,
                    $validatedExtraction,
                    $reviewDowngrades,
                );
            }

            return [$newCandidate, $newPatch, $newReview];
        }, 3);
    }

    /** @param array<string, mixed> $extraction @param array<int, string> $fields */
    private function createConservativeReview(Chapter $chapter, Review $source, GenerationArtifact $candidate, array $extraction, array $fields): Review
    {
        $key = 'review:outline-completion-consensus:'.$chapter->getKey().':'.$source->getKey().':'.$candidate->getKey();
        $run = GenerationRun::query()->create([
            'novel_id' => $chapter->novel_id,
            'chapter_id' => $chapter->getKey(),
            'scope_type' => 'chapter',
            'scope_id' => $chapter->getKey(),
            'stage' => GenerationStage::Review,
            'status' => RunStatus::Succeeded,
            'attempt' => (int) $chapter->generationRuns()->where('stage', GenerationStage::Review)->max('attempt') + 1,
            'idempotency_key' => $key,
            'input_hash' => hash('sha256', $key),
            'state_version' => $source->generationRun->state_version,
            'prompt_version' => self::CONTRACT_VERSION,
            'model_policy' => 'deterministic',
            'context_snapshot' => [
                'source_review_id' => $source->getKey(),
                'source_candidate_artifact_id' => $candidate->getKey(),
                'source_artifact_id' => data_get($source->artifact->data, 'source_artifact_id'),
                'downgraded_fields' => $fields,
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $data = $source->artifact->data;
        foreach ($fields as $field) {
            $data[$field] = $extraction[$field];
        }
        $data['decision_basis'] = [
            ...($data['decision_basis'] ?? []),
            'outline_consensus' => [
                'source_review_id' => $source->getKey(),
                'source_candidate_artifact_id' => $candidate->getKey(),
                'downgraded_fields' => $fields,
            ],
        ];
        $version = (int) GenerationArtifact::query()->where('type', ArtifactType::ReviewResult)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->max('version') + 1;
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $artifact = $run->artifacts()->create([
            'type' => ArtifactType::ReviewResult,
            'version' => $version,
            'content' => $encoded,
            'data' => $data,
            'checksum' => hash('sha256', $encoded),
        ]);

        return $run->review()->create([
            'artifact_id' => $artifact->getKey(),
            'decision' => $source->decision,
            'score' => $source->score,
            'continuity_score' => $source->continuity_score,
            'plan_score' => $source->plan_score,
            'character_score' => $source->character_score,
            'progress_score' => $source->progress_score,
            'repetition_score' => $source->repetition_score,
            'pacing_score' => $source->pacing_score,
            'style_score' => $source->style_score,
            'findings' => $source->findings,
        ]);
    }

    private function latestReview(Chapter $chapter): ?Review
    {
        return Review::query()->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->with(['artifact', 'generationRun'])->latest('id')->first();
    }

    private function latestCandidate(Chapter $chapter, int $draftId): ?GenerationArtifact
    {
        return $draftId < 1 ? null : GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)
            ->where('source_artifact_id', $draftId)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->with('generationRun')->latest('version')->latest('id')->first();
    }

    private function latestPatch(Chapter $chapter, GenerationArtifact $candidate): ?GenerationArtifact
    {
        return GenerationArtifact::query()->where('type', ArtifactType::StatePatch)
            ->where('source_artifact_id', $candidate->getKey())
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->latest('version')->latest('id')->first();
    }
}
