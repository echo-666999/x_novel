<?php

namespace App\Services;

use App\Enums\EventType;
use App\Enums\ForeshadowingPlanAction;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use Illuminate\Validation\ValidationException;

final class ForeshadowingReviewAudit
{
    public const STATUSES = ['fulfilled', 'rewrite_required', 'needs_attention'];

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['foreshadowing_id', 'action', 'target_scene_sequence', 'status', 'summary', 'evidence'],
                'properties' => [
                    'foreshadowing_id' => ['type' => 'integer', 'minimum' => 1],
                    'action' => ['type' => 'string', 'enum' => array_column(ForeshadowingPlanAction::cases(), 'value')],
                    'target_scene_sequence' => ['type' => 'integer', 'minimum' => 1],
                    'status' => ['type' => 'string', 'enum' => self::STATUSES],
                    'summary' => ['type' => 'string', 'minLength' => 1],
                    'evidence' => ['type' => ['string', 'null']],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $audits
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>|null  $candidate
     * @return array<int, array<string, mixed>>
     */
    public function validate(array $audits, Chapter $chapter, GenerationArtifact $draft, array $contract, ?array $candidate): array
    {
        $actions = collect($contract['actions'] ?? [])->filter(fn (mixed $item): bool => is_array($item))->values();
        $expected = $actions->map(fn (array $item): array => [
            'foreshadowing_id' => (int) ($item['foreshadowing_id'] ?? 0),
            'action' => (string) data_get($item, 'plan_action.action'),
            'target_scene_sequence' => (int) data_get($item, 'plan_action.target_scene_sequence'),
        ])->all();

        $actual = [];
        foreach ($audits as $index => $audit) {
            if (! is_array($audit) || ! $this->hasExactKeys($audit, ['foreshadowing_id', 'action', 'target_scene_sequence', 'status', 'summary', 'evidence'])) {
                throw ValidationException::withMessages(["foreshadowing_audits.{$index}" => '伏笔审校项结构无效。']);
            }

            $id = filter_var($audit['foreshadowing_id'], FILTER_VALIDATE_INT);
            $action = is_string($audit['action']) ? ForeshadowingPlanAction::tryFrom($audit['action']) : null;
            $targetSceneSequence = filter_var($audit['target_scene_sequence'], FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || $action === null
                || $targetSceneSequence === false || $targetSceneSequence < 1
                || ! is_string($audit['status']) || ! in_array($audit['status'], self::STATUSES, true)
                || ! is_string($audit['summary']) || blank($audit['summary'])
                || (! is_null($audit['evidence']) && ! is_string($audit['evidence']))) {
                throw ValidationException::withMessages(["foreshadowing_audits.{$index}" => '伏笔审校项字段无效。']);
            }

            $evidence = $audit['status'] === 'fulfilled'
                ? $this->fulfilledCoverageEvidence(
                    $chapter,
                    $draft,
                    (int) $id,
                    $action->value,
                    (int) $targetSceneSequence,
                ) ?? (is_string($audit['evidence']) ? trim($audit['evidence']) : null)
                : (is_string($audit['evidence']) ? trim($audit['evidence']) : null);
            if ($evidence !== null && $evidence !== '') {
                $evidence = PlanCoverage::resolveRepresentativeExactEvidence($draft->content, $evidence);
                if (! str_contains($draft->content, $evidence)) {
                    throw ValidationException::withMessages(["foreshadowing_audits.{$index}.evidence" => '伏笔审校证据必须逐字来自当前正文。']);
                }
            } else {
                $evidence = null;
            }

            $normalized = [
                'foreshadowing_id' => (int) $id,
                'action' => $action->value,
                'target_scene_sequence' => (int) $targetSceneSequence,
                'status' => $audit['status'],
                'summary' => trim($audit['summary']),
                'evidence' => $evidence,
            ];
            $actual[] = ['foreshadowing_id' => (int) $id, 'action' => $action->value, 'target_scene_sequence' => (int) $targetSceneSequence];

            if ($normalized['status'] === 'fulfilled') {
                $this->assertFulfilledHasStructuredProof($normalized, $chapter, $draft, $contract, $candidate, $index);
            }

            $audits[$index] = $normalized;
        }

        if ($actual !== $expected) {
            throw ValidationException::withMessages([
                'foreshadowing_audits' => 'Review 必须按冻结 Plan 顺序且不重复地审校本章全部伏笔动作。',
            ]);
        }

        return array_values($audits);
    }

    private function fulfilledCoverageEvidence(
        Chapter $chapter,
        GenerationArtifact $draft,
        int $foreshadowingId,
        string $action,
        int $targetSceneSequence,
    ): ?string {
        $sceneId = (int) ($chapter->scenes->firstWhere('sequence', $targetSceneSequence)?->getKey() ?? 0);
        $coverage = collect(data_get($draft->data, 'scene_coverage', []))->firstWhere('scene_id', $sceneId);
        $row = collect(data_get($coverage, 'foreshadowing_coverage', []))->first(
            fn (mixed $item): bool => is_array($item)
                && (int) ($item['foreshadowing_id'] ?? 0) === $foreshadowingId
                && ($item['action'] ?? null) === $action
                && ($item['status'] ?? null) === 'fulfilled',
        );
        $evidence = is_array($row) && is_string($row['evidence'] ?? null)
            ? trim($row['evidence'])
            : '';

        return $sceneId > 0 && $evidence !== '' && str_contains((string) $draft->content, $evidence)
            ? $evidence
            : null;
    }

    /** @param array<int, array<string, mixed>> $audits @param array<string, mixed> $contract @return array<int, array<string, mixed>> */
    public function findings(array $audits, Chapter $chapter, array $contract): array
    {
        $scenes = $chapter->scenes->keyBy('sequence');
        $contractActions = collect($contract['actions'] ?? []);

        return collect($audits)->filter(fn (array $audit): bool => $audit['status'] !== 'fulfilled')
            ->map(function (array $audit) use ($scenes, $contractActions): array {
                $contract = $contractActions->first(fn (mixed $item): bool => is_array($item)
                    && (int) ($item['foreshadowing_id'] ?? 0) === $audit['foreshadowing_id']
                    && data_get($item, 'plan_action.action') === $audit['action']
                    && (int) data_get($item, 'plan_action.target_scene_sequence') === $audit['target_scene_sequence']);
                $sceneId = $scenes->get((int) data_get($contract, 'plan_action.target_scene_sequence'))?->getKey();
                $human = $audit['status'] === 'needs_attention';

                return [
                    'code' => $human ? 'FORESHADOWING_DECISION_REQUIRED' : 'FORESHADOWING_SEMANTIC_REWRITE_REQUIRED',
                    'dimension' => 'plan',
                    'severity' => $human ? 'ambiguous' : 'error',
                    'scene_id' => $human ? null : $sceneId,
                    'scope' => $human || $sceneId === null ? 'chapter' : 'scene',
                    'auto_fixable' => ! $human,
                    'requires_human_decision' => $human,
                    'foreshadowing_id' => $audit['foreshadowing_id'],
                    'foreshadowing_action' => $audit['action'],
                    'target_scene_sequence' => $audit['target_scene_sequence'],
                    'target_scene_id' => $sceneId,
                    'message' => $audit['summary'],
                    'evidence' => $audit['evidence'],
                    'source' => 'foreshadowing_review',
                ];
            })->values()->all();
    }

    /** @param array<string, mixed> $audit @param array<string, mixed> $contract @param array<string, mixed>|null $candidate */
    private function assertFulfilledHasStructuredProof(array $audit, Chapter $chapter, GenerationArtifact $draft, array $contract, ?array $candidate, int $index): void
    {
        if ($audit['evidence'] === null) {
            throw ValidationException::withMessages(["foreshadowing_audits.{$index}.evidence" => 'fulfilled 伏笔审校必须提供当前正文逐字证据。']);
        }

        $item = collect($contract['actions'] ?? [])->first(fn (mixed $item): bool => is_array($item)
            && (int) ($item['foreshadowing_id'] ?? 0) === $audit['foreshadowing_id']
            && data_get($item, 'plan_action.action') === $audit['action']
            && (int) data_get($item, 'plan_action.target_scene_sequence') === $audit['target_scene_sequence']);
        $sequence = (int) data_get($item, 'plan_action.target_scene_sequence');
        $sceneId = (int) ($chapter->scenes->firstWhere('sequence', $sequence)?->getKey() ?? 0);
        $coverage = collect(data_get($draft->data, 'scene_coverage', []))->firstWhere('scene_id', $sceneId);
        $hasCoverage = collect(data_get($coverage, 'foreshadowing_coverage', []))->contains(fn (mixed $row): bool => is_array($row)
            && (int) ($row['foreshadowing_id'] ?? 0) === $audit['foreshadowing_id']
            && ($row['action'] ?? null) === $audit['action']
            && ($row['status'] ?? null) === 'fulfilled');

        if (! $hasCoverage) {
            throw ValidationException::withMessages(["foreshadowing_audits.{$index}.status" => '没有 fulfilled Coverage 的伏笔动作不能审校为 fulfilled。']);
        }

        $expectedEvent = match ($audit['action']) {
            ForeshadowingPlanAction::Plant->value => EventType::ForeshadowingPlanted->value,
            ForeshadowingPlanAction::Reinforce->value => EventType::ForeshadowingReinforced->value,
            ForeshadowingPlanAction::PayOff->value => EventType::ForeshadowingPaidOff->value,
            ForeshadowingPlanAction::Abandon->value => EventType::ForeshadowingAbandoned->value,
            ForeshadowingPlanAction::Defer->value => null,
        };
        if ($expectedEvent !== null && ! collect($candidate['events'] ?? [])->contains(fn (mixed $event): bool => is_array($event)
            && ($event['event_type'] ?? null) === $expectedEvent
            && (int) ($event['subject_id'] ?? 0) === $audit['foreshadowing_id'])) {
            throw ValidationException::withMessages(["foreshadowing_audits.{$index}.status" => '缺少匹配 Event Candidate 的伏笔动作不能审校为 fulfilled。']);
        }
    }

    /** @param array<int, string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
