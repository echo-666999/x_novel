<?php

namespace App\Services;

use App\Data\StoryEventCandidate;
use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 统一 Review、Event Extraction 与 Canonical Commit 使用的 Outline 完成契约。
 */
class OutlineCompletionService
{
    public function __construct(private readonly OutlineHandoffContract $handoffContract) {}

    /** @return array<string, mixed> */
    public static function reviewSchema(): array
    {
        return [
            'chapter_plan_completion' => self::singleAuditSchema(['fulfilled', 'not_met', 'contradicted']),
            'milestone_completion' => self::criteriaGroupSchema(),
            'beat_exit' => self::criteriaGroupSchema(),
            'handoff_readiness' => self::handoffSchema(),
        ];
    }

    /** @return array<string, mixed> */
    public static function extractionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['milestone_completion', 'beat_exit', 'handoff_readiness'],
            'properties' => [
                'milestone_completion' => self::criteriaGroupSchema(),
                'beat_exit' => self::criteriaGroupSchema(),
                'handoff_readiness' => self::handoffSchema(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function contract(Chapter $chapter): array
    {
        $chapter = Chapter::query()->with([
            'novel.canonicalStateVersion',
            'latestPlan.primaryOutlineBeat.milestones',
            'latestPlan.primaryOutlineBeat.handoffNextBeat',
            'latestPlan.primaryOutlineMilestone',
        ])->findOrFail($chapter->getKey());
        $plan = $chapter->latestPlan;
        $beat = $plan?->primaryOutlineBeat;
        $milestone = $plan?->primaryOutlineMilestone;
        if ($plan === null || $beat === null || $milestone === null
            || $chapter->novel->current_outline_id !== $plan->novel_outline_id
            || $plan->novel_outline_id !== $beat->novel_outline_id
            || $plan->novel_outline_id !== $milestone->novel_outline_id
            || $beat->novel_outline_arc_id !== $plan->primary_outline_arc_id
            || $milestone->novel_outline_beat_id !== $beat->getKey()) {
            throw ValidationException::withMessages([
                'outline_completion' => 'Chapter Plan 缺少同一 Outline 下的 Primary Beat/Milestone 完整父链。',
            ]);
        }

        $runtimeArc = $chapter->novel->storyArcs()
            ->where('source_outline_arc_id', $plan->primary_outline_arc_id)
            ->first();
        if ($runtimeArc === null || $runtimeArc->volume_id !== $chapter->volume_id) {
            throw ValidationException::withMessages([
                'outline_completion' => 'Primary Outline Arc 缺少当前 Chapter Volume 中的运行态 Story Arc。',
            ]);
        }

        $milestoneIds = $beat->milestones->modelKeys();
        $completedMilestoneIds = $chapter->novel->storyEvents()
            ->where('event_type', EventType::StoryArcBeatMilestoneCompleted->value)
            ->where('status', StoryEventStatus::Active->value)
            ->where('subject_type', 'story_arc')
            ->where('subject_id', (string) $runtimeArc->getKey())
            ->where('novel_outline_id', $plan->novel_outline_id)
            ->where('novel_outline_arc_id', $plan->primary_outline_arc_id)
            ->where('novel_outline_beat_id', $plan->primary_outline_beat_id)
            ->pluck('novel_outline_milestone_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $handoffChecks = $this->handoffChecks($beat);
        $identity = [
            'novel_outline_id' => $plan->novel_outline_id,
            'novel_outline_arc_id' => $plan->primary_outline_arc_id,
            'novel_outline_beat_id' => $plan->primary_outline_beat_id,
            'novel_outline_milestone_id' => $plan->primary_outline_milestone_id,
            'story_arc_id' => $runtimeArc->getKey(),
            'beat_key' => $beat->beat_key,
            'milestone_key' => $milestone->milestone_key,
        ];
        $contract = [
            'identity' => $identity,
            'milestone_criteria' => array_values($milestone->acceptance_criteria ?? []),
            'beat_exit_criteria' => array_values($beat->acceptance_criteria ?? []),
            'handoff_next_beat_id' => $beat->handoff_next_beat_id,
            'handoff_next_beat_key' => $beat->handoffNextBeat?->beat_key,
            'handoff_checks' => $handoffChecks,
            'milestone_ids' => $milestoneIds,
            'completed_milestone_ids' => $completedMilestoneIds,
            'is_final_milestone' => end($milestoneIds) === $milestone->getKey(),
        ];
        $contract['checksum'] = hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $contract;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function validateReview(array $payload, Chapter $chapter, GenerationArtifact $draft): array
    {
        $contract = $this->contract($chapter);
        $payload['chapter_plan_completion'] = $this->validateSingleAudit(
            $payload['chapter_plan_completion'] ?? null,
            $chapter,
            $draft,
            'chapter_plan_completion',
        );
        $payload['milestone_completion'] = $this->validateCriteriaGroup(
            $payload['milestone_completion'] ?? null,
            $contract['milestone_criteria'],
            $chapter,
            $draft,
            'milestone_completion',
        );
        $payload['beat_exit'] = $this->validateCriteriaGroup(
            $payload['beat_exit'] ?? null,
            $contract['beat_exit_criteria'],
            $chapter,
            $draft,
            'beat_exit',
        );
        $payload['handoff_readiness'] = $this->validateHandoff(
            $payload['handoff_readiness'] ?? null,
            $contract,
            $chapter,
            $draft,
        );
        $payload['outline_completion_identity'] = $contract['identity'];
        $payload['outline_completion_contract_checksum'] = $contract['checksum'];

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function validateExtraction(array $payload, Chapter $chapter, GenerationArtifact $draft): array
    {
        if (! $this->hasExactKeys($payload, ['milestone_completion', 'beat_exit', 'handoff_readiness'])) {
            throw ValidationException::withMessages([
                'outline_completion' => 'Event Extraction 必须完整返回 Milestone、Beat Exit 与 Handoff Readiness。',
            ]);
        }
        $contract = $this->contract($chapter);

        return [
            'milestone_completion' => $this->validateCriteriaGroup(
                $payload['milestone_completion'],
                $contract['milestone_criteria'],
                $chapter,
                $draft,
                'outline_completion.milestone_completion',
            ),
            'beat_exit' => $this->validateCriteriaGroup(
                $payload['beat_exit'],
                $contract['beat_exit_criteria'],
                $chapter,
                $draft,
                'outline_completion.beat_exit',
            ),
            'handoff_readiness' => $this->validateHandoff(
                $payload['handoff_readiness'],
                $contract,
                $chapter,
                $draft,
            ),
            'identity' => $contract['identity'],
            'contract_checksum' => $contract['checksum'],
        ];
    }

    /**
     * @param  array<string, mixed>  $completion
     * @return array<int, StoryEventCandidate>
     */
    public function eventCandidates(array $completion, Chapter $chapter, GenerationArtifact $draft): array
    {
        $contract = $this->contract($chapter);
        if (($completion['contract_checksum'] ?? null) !== $contract['checksum']
            || ($completion['identity'] ?? null) !== $contract['identity']) {
            throw ValidationException::withMessages([
                'outline_completion' => 'Completion Candidate 的冻结身份或契约 Checksum 已变化。',
            ]);
        }

        $events = [];
        $milestoneCompleted = data_get($completion, 'milestone_completion.status') === 'fulfilled';
        if ($milestoneCompleted) {
            $events[] = $this->candidate(
                EventType::StoryArcBeatMilestoneCompleted,
                $contract,
                $draft,
                $completion['milestone_completion']['criteria'],
                ['milestone_completion' => $completion['milestone_completion']],
            );
        }

        $completedAfterCandidate = collect($contract['completed_milestone_ids'])
            ->when($milestoneCompleted, fn (Collection $ids): Collection => $ids->push($contract['identity']['novel_outline_milestone_id']))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $allMilestonesCompleted = $completedAfterCandidate === collect($contract['milestone_ids'])
            ->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $handoffReady = $contract['handoff_next_beat_id'] === null
            ? data_get($completion, 'handoff_readiness.status') === 'not_applicable'
            : data_get($completion, 'handoff_readiness.status') === 'ready';
        $beatCompleted = $milestoneCompleted
            && $contract['is_final_milestone']
            && $allMilestonesCompleted
            && data_get($completion, 'beat_exit.status') === 'fulfilled'
            && $handoffReady;

        if ($beatCompleted) {
            $audits = [
                ...$completion['milestone_completion']['criteria'],
                ...$completion['beat_exit']['criteria'],
                ...collect($completion['handoff_readiness']['checks'])
                    ->filter(fn (array $audit): bool => is_string($audit['evidence'] ?? null) && filled($audit['evidence']))
                    ->values()->all(),
            ];
            $events[] = $this->candidate(
                EventType::StoryArcBeatCompleted,
                $contract,
                $draft,
                $audits,
                [
                    'beat_exit' => $completion['beat_exit'],
                    'handoff_readiness' => $completion['handoff_readiness'],
                    'handoff_contract' => $this->handoffContract->forBeat(
                        $chapter->latestPlan->primaryOutlineBeat,
                    ),
                ],
            );
        }

        return $events;
    }

    /**
     * @param  array<int, StoryEventCandidate>  $events
     * @return array<string, mixed>
     */
    public function validateCommit(Chapter $chapter, array $reviewData, array $candidateData, array $events): array
    {
        $contract = $this->contract($chapter);
        $draft = GenerationArtifact::query()
            ->whereKey((int) data_get($reviewData, 'source_artifact_id'))
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->first();
        if ($draft === null) {
            throw ValidationException::withMessages(['review' => 'PASS Review 的来源正文不属于当前 Chapter。']);
        }
        $reviewCompletion = $this->validateReview([
            'chapter_plan_completion' => data_get($reviewData, 'chapter_plan_completion'),
            'milestone_completion' => data_get($reviewData, 'milestone_completion'),
            'beat_exit' => data_get($reviewData, 'beat_exit'),
            'handoff_readiness' => data_get($reviewData, 'handoff_readiness'),
        ], $chapter, $draft);
        if (data_get($reviewCompletion, 'chapter_plan_completion.status') !== 'fulfilled') {
            throw ValidationException::withMessages(['review' => 'PASS Review 必须明确 Chapter Plan 已完成。']);
        }
        if (data_get($reviewData, 'outline_completion_identity') !== $reviewCompletion['outline_completion_identity']
            || data_get($reviewData, 'outline_completion_contract_checksum') !== $reviewCompletion['outline_completion_contract_checksum']) {
            throw ValidationException::withMessages(['review' => 'PASS Review 的 Outline Completion 身份或契约已过期。']);
        }

        $storedExtraction = data_get($candidateData, 'outline_completion');
        if (! is_array($storedExtraction)
            || ! $this->hasExactKeys($storedExtraction, ['milestone_completion', 'beat_exit', 'handoff_readiness', 'identity', 'contract_checksum'])) {
            throw ValidationException::withMessages(['events' => 'Event Candidate Artifact 缺少完整的 Outline Completion 审计。']);
        }
        $validatedExtraction = $this->validateExtraction([
            'milestone_completion' => $storedExtraction['milestone_completion'],
            'beat_exit' => $storedExtraction['beat_exit'],
            'handoff_readiness' => $storedExtraction['handoff_readiness'],
        ], $chapter, $draft);
        if ($storedExtraction !== $validatedExtraction) {
            throw ValidationException::withMessages(['events' => 'Event Candidate Artifact 的 Outline Completion 身份或契约已过期。']);
        }
        foreach (['milestone_completion', 'beat_exit', 'handoff_readiness'] as $field) {
            if (data_get($reviewCompletion, "{$field}.status") !== data_get($validatedExtraction, "{$field}.status")) {
                throw ValidationException::withMessages([
                    'outline_completion' => "Review 与 Event Extraction 的 {$field} 结论不一致。",
                ]);
            }
        }

        $milestoneEvents = collect($events)->where('eventType', EventType::StoryArcBeatMilestoneCompleted)->values();
        $beatEvents = collect($events)->where('eventType', EventType::StoryArcBeatCompleted)->values();
        if ($milestoneEvents->count() > 1 || $beatEvents->count() > 1) {
            throw ValidationException::withMessages(['events' => '同一 Chapter 不能生成重复 Milestone/Beat Completion Candidate。']);
        }
        $reviewMilestoneCompleted = data_get($reviewCompletion, 'milestone_completion.status') === 'fulfilled';
        $completedAfterReview = collect($contract['completed_milestone_ids'])
            ->when($reviewMilestoneCompleted, fn (Collection $ids): Collection => $ids->push($contract['identity']['novel_outline_milestone_id']))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $allMilestonesCompleted = $completedAfterReview === collect($contract['milestone_ids'])
            ->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $reviewBeatCompleted = $reviewMilestoneCompleted
            && $allMilestonesCompleted
            && data_get($reviewCompletion, 'beat_exit.status') === 'fulfilled'
            && ($contract['handoff_next_beat_id'] === null
                ? data_get($reviewCompletion, 'handoff_readiness.status') === 'not_applicable'
                : data_get($reviewCompletion, 'handoff_readiness.status') === 'ready')
            && $contract['is_final_milestone'];
        if ($reviewMilestoneCompleted !== $milestoneEvents->isNotEmpty()
            || $reviewBeatCompleted !== $beatEvents->isNotEmpty()) {
            throw ValidationException::withMessages([
                'outline_completion' => 'Review 与 Event Candidate 对 Milestone/Beat 完成结论不一致。',
            ]);
        }

        foreach ($milestoneEvents->concat($beatEvents) as $event) {
            $this->assertAuthoritativeEvent($event, $contract);
            $this->assertCurrentEvidence($event, $chapter, $draft);
        }
        $expectedCompletionEvents = collect($this->eventCandidates($validatedExtraction, $chapter, $draft))
            ->map(fn (StoryEventCandidate $event): array => $event->toArray())
            ->values()
            ->all();
        $actualCompletionEvents = collect($events)
            ->filter(fn (StoryEventCandidate $event): bool => in_array($event->eventType, [
                EventType::StoryArcBeatMilestoneCompleted,
                EventType::StoryArcBeatCompleted,
            ], true))
            ->map(fn (StoryEventCandidate $event): array => $event->toArray())
            ->values()
            ->all();
        if ($actualCompletionEvents !== $expectedCompletionEvents) {
            throw ValidationException::withMessages(['events' => 'Completion Candidate 与 Laravel 重新计算的权威结果不一致。']);
        }

        return [
            'chapter_plan_completion' => $reviewCompletion['chapter_plan_completion'],
            'milestone_completion' => $reviewCompletion['milestone_completion'],
            'beat_exit' => $reviewCompletion['beat_exit'],
            'handoff_readiness' => $reviewCompletion['handoff_readiness'],
            'identity' => $contract['identity'],
            'contract_checksum' => $contract['checksum'],
        ];
    }

    /** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
    public function findings(array $payload): array
    {
        $findings = [];
        if (data_get($payload, 'chapter_plan_completion.status') !== 'fulfilled') {
            $findings[] = $this->finding(
                'CHAPTER_PLAN_NOT_COMPLETED',
                'plan',
                data_get($payload, 'chapter_plan_completion.scene_id'),
                '本章尚未完成 Chapter Plan 的章节职责。',
                data_get($payload, 'chapter_plan_completion.evidence'),
            );
        }
        if (data_get($payload, 'milestone_completion.status') === 'contradicted') {
            $findings[] = $this->finding(
                'MILESTONE_CONTRADICTED',
                'progress',
                null,
                '正文与当前 Milestone 验收条件发生冲突。',
                $this->firstEvidence(data_get($payload, 'milestone_completion.criteria', [])),
            );
        }

        return $findings;
    }

    /** @return array<string, mixed> */
    private static function singleAuditSchema(array $statuses): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'evidence', 'scene_id'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => $statuses],
                'evidence' => ['type' => ['string', 'null']],
                'scene_id' => ['type' => ['integer', 'null']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function criteriaGroupSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'criteria'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['fulfilled', 'not_met', 'contradicted']],
                'criteria' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['criterion', 'status', 'evidence', 'scene_id'],
                        'properties' => [
                            'criterion' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['fulfilled', 'not_met', 'contradicted']],
                            'evidence' => ['type' => ['string', 'null']],
                            'scene_id' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function handoffSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'checks'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ready', 'not_ready', 'not_applicable']],
                'checks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'requirement', 'status', 'evidence', 'scene_id'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'requirement' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['fulfilled', 'not_met', 'contradicted', 'not_applicable']],
                            'evidence' => ['type' => ['string', 'null']],
                            'scene_id' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validateSingleAudit(mixed $audit, Chapter $chapter, GenerationArtifact $draft, string $field): array
    {
        if (! is_array($audit)
            || ! $this->hasExactKeys($audit, ['status', 'evidence', 'scene_id'])
            || ! in_array($audit['status'] ?? null, ['fulfilled', 'not_met', 'contradicted'], true)) {
            throw ValidationException::withMessages([$field => '完成审计结构无效。']);
        }
        $this->validateAuditEvidence($audit, $chapter, $draft, $field);

        return $audit;
    }

    /** @param array<int, string> $criteria @return array<string, mixed> */
    private function validateCriteriaGroup(mixed $group, array $criteria, Chapter $chapter, GenerationArtifact $draft, string $field): array
    {
        if (! is_array($group)
            || ! $this->hasExactKeys($group, ['status', 'criteria'])
            || ! in_array($group['status'] ?? null, ['fulfilled', 'not_met', 'contradicted'], true)
            || ! is_array($group['criteria'] ?? null)) {
            throw ValidationException::withMessages([$field => '条件完成审计结构无效。']);
        }
        if (collect($group['criteria'])->pluck('criterion')->values()->all() !== array_values($criteria)) {
            throw ValidationException::withMessages([$field => '完成条件必须按冻结契约的身份和顺序完整返回。']);
        }
        foreach ($group['criteria'] as $audit) {
            if (! is_array($audit)
                || ! $this->hasExactKeys($audit, ['criterion', 'status', 'evidence', 'scene_id'])
                || ! in_array($audit['status'] ?? null, ['fulfilled', 'not_met', 'contradicted'], true)) {
                throw ValidationException::withMessages([$field => '条件完成审计项结构无效。']);
            }
            $this->validateAuditEvidence($audit, $chapter, $draft, $field);
        }
        $expectedStatus = collect($group['criteria'])->contains(fn (array $audit): bool => $audit['status'] === 'contradicted')
            ? 'contradicted'
            : (collect($group['criteria'])->every(fn (array $audit): bool => $audit['status'] === 'fulfilled') ? 'fulfilled' : 'not_met');
        if ($group['status'] !== $expectedStatus) {
            throw ValidationException::withMessages([$field => '汇总状态与逐项条件状态不一致。']);
        }

        return $group;
    }

    /** @param array<string, mixed> $contract @return array<string, mixed> */
    private function validateHandoff(mixed $handoff, array $contract, Chapter $chapter, GenerationArtifact $draft): array
    {
        if (! is_array($handoff)
            || ! $this->hasExactKeys($handoff, ['status', 'checks'])
            || ! in_array($handoff['status'] ?? null, ['ready', 'not_ready', 'not_applicable'], true)
            || ! is_array($handoff['checks'] ?? null)) {
            throw ValidationException::withMessages(['handoff_readiness' => 'Handoff Readiness 结构无效。']);
        }
        if ($contract['handoff_next_beat_id'] === null) {
            if ($handoff['status'] !== 'not_applicable' || $handoff['checks'] !== []) {
                throw ValidationException::withMessages(['handoff_readiness' => '最终 Beat 的 Handoff 必须标记为 not_applicable。']);
            }

            return $handoff;
        }
        $expectedIdentity = collect($contract['handoff_checks'])->map(
            fn (array $check): array => [$check['key'], $check['requirement']],
        )->all();
        $actualIdentity = collect($handoff['checks'])->map(
            fn (array $check): array => [$check['key'] ?? null, $check['requirement'] ?? null],
        )->all();
        if ($actualIdentity !== $expectedIdentity) {
            throw ValidationException::withMessages(['handoff_readiness' => 'Handoff 检查必须按冻结契约的身份和顺序完整返回。']);
        }
        foreach ($handoff['checks'] as $audit) {
            if (! is_array($audit)
                || ! $this->hasExactKeys($audit, ['key', 'requirement', 'status', 'evidence', 'scene_id'])
                || ! in_array($audit['status'] ?? null, ['fulfilled', 'not_met', 'contradicted', 'not_applicable'], true)) {
                throw ValidationException::withMessages(['handoff_readiness' => 'Handoff 检查项结构无效。']);
            }
            $this->validateAuditEvidence($audit, $chapter, $draft, 'handoff_readiness');
        }
        $expectedStatus = collect($handoff['checks'])->every(fn (array $audit): bool => $audit['status'] === 'fulfilled')
            ? 'ready'
            : 'not_ready';
        if ($handoff['status'] !== $expectedStatus) {
            throw ValidationException::withMessages(['handoff_readiness' => 'Handoff 汇总状态与逐项检查不一致。']);
        }

        return $handoff;
    }

    /** @param array<string, mixed> $audit */
    private function validateAuditEvidence(array $audit, Chapter $chapter, GenerationArtifact $draft, string $field): void
    {
        $requiresEvidence = in_array($audit['status'], ['fulfilled', 'contradicted'], true);
        $evidence = $audit['evidence'] ?? null;
        $sceneId = $audit['scene_id'] ?? null;
        if ($requiresEvidence && (! is_string($evidence) || blank($evidence) || ! str_contains((string) $draft->content, $evidence))) {
            throw ValidationException::withMessages([$field => '已满足或冲突的条件必须提供当前正文逐字证据。']);
        }
        if (! $requiresEvidence && $evidence !== null) {
            throw ValidationException::withMessages([$field => '未满足条件的 evidence 必须为 null。']);
        }
        if ($sceneId !== null) {
            $scene = $chapter->scenes->firstWhere('id', $sceneId);
            if ($scene === null || ($requiresEvidence && ! str_contains((string) $scene->currentArtifact?->content, (string) $evidence))) {
                throw ValidationException::withMessages([$field => '完成证据的 Scene 不属于当前章节或逐字证据不在该 Scene 当前 Artifact 中。']);
            }
        } elseif ($requiresEvidence && $field !== 'chapter_plan_completion') {
            throw ValidationException::withMessages([$field => '完成条件的逐字证据必须绑定当前章节 Scene。']);
        }
    }

    /** @return array<int, array{key: string, requirement: string}> */
    private function handoffChecks($beat): array
    {
        if ($beat->handoff_next_beat_id === null) {
            return [];
        }
        $checks = [];
        foreach (['exit_result' => $beat->handoff_exit_result, 'next_trigger' => $beat->handoff_next_trigger] as $key => $requirement) {
            if (filled($requirement)) {
                $checks[] = ['key' => $key, 'requirement' => $requirement];
            }
        }
        foreach (['required_transition' => $beat->handoff_required_transition, 'forbidden_jump' => $beat->handoff_forbidden_jump] as $prefix => $requirements) {
            foreach (array_values($requirements ?? []) as $index => $requirement) {
                $checks[] = ['key' => "{$prefix}:{$index}", 'requirement' => $requirement];
            }
        }

        return $checks;
    }

    /** @param array<int, array<string, mixed>> $audits @param array<string, mixed> $contract @param array<string, mixed> $extraPayload */
    private function candidate(EventType $type, array $contract, GenerationArtifact $draft, array $audits, array $extraPayload): StoryEventCandidate
    {
        $evidence = collect($audits)
            ->filter(fn (array $audit): bool => is_string($audit['evidence'] ?? null) && filled($audit['evidence']))
            ->map(fn (array $audit): array => [
                'artifact_id' => $draft->getKey(),
                'scene_id' => $audit['scene_id'],
                'quote' => $audit['evidence'],
                'start_offset' => null,
                'end_offset' => null,
            ])->unique(fn (array $item): string => $item['scene_id'].'|'.$item['quote'])->values()->all();
        if ($evidence === []) {
            throw ValidationException::withMessages(['outline_completion' => 'Completion Candidate 缺少正文逐字证据。']);
        }

        return new StoryEventCandidate(
            eventType: $type,
            subjectType: 'story_arc',
            subjectId: (string) $contract['identity']['story_arc_id'],
            payload: [
                ...$contract['identity'],
                'completion_contract_checksum' => $contract['checksum'],
                ...$extraPayload,
            ],
            evidence: $evidence,
            storyTime: null,
            confidence: 1,
        );
    }

    /** @param array<string, mixed> $contract */
    private function assertAuthoritativeEvent(StoryEventCandidate $event, array $contract): void
    {
        if ($event->subjectType !== 'story_arc'
            || $event->subjectId !== (string) $contract['identity']['story_arc_id']
            || (int) ($event->payload['story_arc_id'] ?? 0) !== $contract['identity']['story_arc_id']
            || (int) ($event->payload['novel_outline_id'] ?? 0) !== $contract['identity']['novel_outline_id']
            || (int) ($event->payload['novel_outline_arc_id'] ?? 0) !== $contract['identity']['novel_outline_arc_id']
            || (int) ($event->payload['novel_outline_beat_id'] ?? 0) !== $contract['identity']['novel_outline_beat_id']
            || (int) ($event->payload['novel_outline_milestone_id'] ?? 0) !== $contract['identity']['novel_outline_milestone_id']
            || ($event->payload['beat_key'] ?? null) !== $contract['identity']['beat_key']
            || ($event->payload['milestone_key'] ?? null) !== $contract['identity']['milestone_key']
            || ($event->payload['completion_contract_checksum'] ?? null) !== $contract['checksum']) {
            throw ValidationException::withMessages([
                'events' => 'Completion Candidate 的 Outline 身份与冻结 Chapter Plan 不一致。',
            ]);
        }
    }

    private function assertCurrentEvidence(StoryEventCandidate $event, Chapter $chapter, GenerationArtifact $draft): void
    {
        foreach ($event->evidence as $evidence) {
            $sceneId = $evidence['scene_id'] ?? null;
            $quote = $evidence['quote'] ?? null;
            $scene = is_int($sceneId) ? $chapter->scenes()->with('currentArtifact')->find($sceneId) : null;
            if (($evidence['artifact_id'] ?? null) !== $draft->getKey()
                || ! is_string($quote)
                || ! str_contains((string) $draft->content, $quote)
                || $scene === null
                || ! str_contains((string) $scene->currentArtifact?->content, $quote)) {
                throw ValidationException::withMessages([
                    'events' => 'Completion Candidate Evidence 必须逐字来自当前 Chapter Draft 与所属 Scene 当前 Artifact。',
                ]);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $audits */
    private function firstEvidence(array $audits): ?string
    {
        return collect($audits)->pluck('evidence')->first(fn ($evidence): bool => is_string($evidence) && filled($evidence));
    }

    /** @return array<string, mixed> */
    private function finding(string $code, string $dimension, ?int $sceneId, string $message, ?string $evidence): array
    {
        return [
            'code' => $code,
            'dimension' => $dimension,
            'severity' => 'error',
            'scene_id' => $sceneId,
            'scope' => $sceneId === null ? 'chapter' : 'scene',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'message' => $message,
            'evidence' => $evidence ?? '正文未提供满足完成条件的证据。',
            'source' => 'outline_completion_review',
        ];
    }

    /** @param array<string, mixed> $value @param array<int, string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
