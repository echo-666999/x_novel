<?php

namespace App\Services;

use App\Enums\WorldEntityType;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use Illuminate\Validation\ValidationException;

/**
 * Review 的模型边界只保留语义判断。数据库身份、顺序和汇总状态均由
 * Laravel 从冻结的 Chapter Plan / Outline / Foreshadowing Contract 恢复。
 */
final class CompactReviewPayload
{
    private const DIMENSIONS = ['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'];

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $scores = array_fill_keys(self::DIMENSIONS, ['type' => 'number', 'minimum' => 0, 'maximum' => 100]);
        $audit = self::auditSchema(['fulfilled', 'not_met', 'contradicted']);
        $compactList = fn (array $statuses): array => ['type' => 'array', 'items' => self::auditSchema($statuses)];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'scores', 'chapter_plan_completion', 'milestone_completion', 'beat_exit', 'handoff_readiness',
                'foreshadowing_audits', 'arc_beat_audits', 'arc_completion_audits',
                'character_candidate_audits', 'world_entity_candidate_audits',
                'unapproved_characters', 'unapproved_world_entities', 'findings',
            ],
            'properties' => [
                'scores' => ['type' => 'object', 'additionalProperties' => false, 'required' => self::DIMENSIONS, 'properties' => $scores],
                'chapter_plan_completion' => $audit,
                'milestone_completion' => $compactList(['fulfilled', 'not_met', 'contradicted']),
                'beat_exit' => $compactList(['fulfilled', 'not_met', 'contradicted']),
                'handoff_readiness' => $compactList(['fulfilled', 'not_met', 'contradicted', 'not_applicable']),
                'foreshadowing_audits' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['status', 'summary', 'evidence'],
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ForeshadowingReviewAudit::STATUSES],
                        'summary' => ['type' => 'string', 'minLength' => 1],
                        'evidence' => ['type' => ['string', 'null']],
                    ],
                ]],
                'arc_beat_audits' => $compactList(['fulfilled', 'missing', 'contradicted']),
                'arc_completion_audits' => $compactList(['fulfilled', 'not_met']),
                'character_candidate_audits' => $compactList(['introduced', 'missing', 'contradicted']),
                'world_entity_candidate_audits' => $compactList(['introduced', 'missing', 'contradicted']),
                'unapproved_world_entities' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['name', 'type', 'evidence'],
                    'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 1],
                        'type' => ['type' => 'string', 'enum' => array_column(WorldEntityType::cases(), 'value')],
                        'evidence' => ['type' => 'string', 'minLength' => 1],
                    ],
                ]],
                'unapproved_characters' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['name', 'evidence'],
                    'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 1],
                        'evidence' => ['type' => 'string', 'minLength' => 1],
                    ],
                ]],
                'findings' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['code', 'dimension', 'severity', 'scope', 'auto_fixable', 'requires_human_decision', 'message', 'evidence'],
                    'properties' => [
                        'code' => ['type' => 'string', 'enum' => ['CONTINUITY_BREAK', 'PLAN_DEVIATION', 'CHARACTER_INCONSISTENCY', 'INSUFFICIENT_PROGRESS', 'EXCESSIVE_REPETITION', 'PACING_ISSUE', 'STYLE_MISMATCH']],
                        'dimension' => ['type' => 'string', 'enum' => self::DIMENSIONS],
                        'severity' => ['type' => 'string', 'enum' => ['warning', 'error']],
                        'scope' => ['type' => 'string', 'enum' => ['paragraph', 'scene', 'chapter']],
                        'auto_fixable' => ['type' => 'boolean'],
                        'requires_human_decision' => ['type' => 'boolean'],
                        'message' => ['type' => 'string', 'minLength' => 1],
                        'evidence' => ['type' => 'string', 'minLength' => 1],
                    ],
                ]],
            ],
        ];
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $outlineContract @param array<string, mixed> $foreshadowingContract @return array<string, mixed> */
    public static function expand(array $payload, Chapter $chapter, GenerationArtifact $draft, array $outlineContract, array $foreshadowingContract): array
    {
        self::assertExactKeys($payload, self::schema()['required'], 'review');
        $plan = $chapter->latestPlan;
        if ($plan === null) {
            throw ValidationException::withMessages(['review' => 'Review 缺少冻结 Chapter Plan。']);
        }

        $expandOrdered = function (mixed $audits, array $contracts, callable $identity, string $field) use ($chapter): array {
            if (! is_array($audits) || count($audits) !== count($contracts)) {
                throw ValidationException::withMessages([$field => "{$field} 必须按冻结契约逐项完整返回。"]);
            }

            return collect(array_values($audits))->map(function (mixed $audit, int $index) use ($contracts, $identity, $field, $chapter): array {
                self::assertAudit($audit, $field);

                return [
                    ...$identity($contracts[$index], $index),
                    'status' => $audit['status'],
                    'evidence' => $audit['evidence'],
                    'scene_id' => self::sceneIdForEvidence($chapter, $audit['evidence']),
                ];
            })->all();
        };

        self::assertAudit($payload['chapter_plan_completion'], 'chapter_plan_completion');
        $payload['chapter_plan_completion']['scene_id'] = self::sceneIdForEvidence($chapter, $payload['chapter_plan_completion']['evidence']);

        foreach ([
            'milestone_completion' => $outlineContract['milestone_criteria'] ?? [],
            'beat_exit' => $outlineContract['beat_exit_criteria'] ?? [],
        ] as $field => $criteria) {
            $items = $expandOrdered($payload[$field], array_values($criteria), fn (string $criterion): array => ['criterion' => $criterion], $field);
            $payload[$field] = ['status' => self::aggregateCriteriaStatus($items), 'criteria' => $items];
        }

        $handoffChecks = array_values($outlineContract['handoff_checks'] ?? []);
        $checks = $expandOrdered($payload['handoff_readiness'], $handoffChecks, fn (array $check): array => [
            'key' => $check['key'], 'requirement' => $check['requirement'],
        ], 'handoff_readiness');
        $payload['handoff_readiness'] = [
            'status' => ($outlineContract['handoff_next_beat_id'] ?? null) === null
                ? 'not_applicable'
                : (collect($checks)->every(fn (array $audit): bool => $audit['status'] === 'fulfilled') ? 'ready' : 'not_ready'),
            'checks' => $checks,
        ];

        $compactForeshadowing = $payload['foreshadowing_audits'];
        $foreshadowingActions = array_values($foreshadowingContract['actions'] ?? []);
        if (! is_array($compactForeshadowing) || count($compactForeshadowing) !== count($foreshadowingActions)) {
            throw ValidationException::withMessages(['foreshadowing_audits' => 'foreshadowing_audits 必须按冻结契约逐项完整返回。']);
        }
        $payload['foreshadowing_audits'] = collect(array_values($compactForeshadowing))->map(function (mixed $audit, int $index) use ($foreshadowingActions): array {
            if (! is_array($audit) || ! is_string($audit['summary'] ?? null) || blank($audit['summary'])) {
                throw ValidationException::withMessages(['foreshadowing_audits' => '伏笔语义审计结构无效。']);
            }
            self::assertAudit($audit, 'foreshadowing_audits');
            $item = $foreshadowingActions[$index];

            return [
                'foreshadowing_id' => (int) $item['foreshadowing_id'],
                'action' => (string) data_get($item, 'plan_action.action'),
                'target_scene_sequence' => (int) data_get($item, 'plan_action.target_scene_sequence'),
                'status' => $audit['status'],
                'summary' => trim($audit['summary']),
                'evidence' => $audit['evidence'],
            ];
        })->all();

        $payload['arc_beat_audits'] = $expandOrdered($payload['arc_beat_audits'], array_values($plan->arc_contributions ?? []), fn (array $row): array => [
            'arc_id' => (int) $row['arc_id'], 'beat_key' => (string) $row['beat_key'],
        ], 'arc_beat_audits');
        $arcIds = collect($plan->arc_contributions ?? [])->pluck('arc_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $payload['arc_completion_audits'] = $expandOrdered($payload['arc_completion_audits'], $arcIds, fn (int $arcId): array => ['arc_id' => $arcId], 'arc_completion_audits');
        foreach ($payload['arc_completion_audits'] as &$audit) {
            unset($audit['scene_id']);
        }
        unset($audit);
        $payload['character_candidate_audits'] = $expandOrdered($payload['character_candidate_audits'], array_values($plan->character_candidates ?? []), fn (array $row): array => ['candidate_key' => (string) $row['candidate_key']], 'character_candidate_audits');
        $payload['world_entity_candidate_audits'] = $expandOrdered($payload['world_entity_candidate_audits'], array_values($plan->world_entity_candidates ?? []), fn (array $row): array => ['candidate_key' => (string) $row['candidate_key']], 'world_entity_candidate_audits');

        foreach (['unapproved_characters', 'unapproved_world_entities', 'findings'] as $field) {
            if (! is_array($payload[$field])) {
                throw ValidationException::withMessages([$field => "{$field} 必须返回数组。"]);
            }
            foreach ($payload[$field] as $index => $item) {
                if (! is_array($item)) {
                    throw ValidationException::withMessages([$field => "{$field} 结构无效。"]);
                }
                $payload[$field][$index]['scene_id'] = ($item['scope'] ?? 'scene') === 'chapter'
                    ? null
                    : self::sceneIdForEvidence($chapter, $item['evidence'] ?? null);
            }
        }

        $payload['dimension_audits'] = collect(self::DIMENSIONS)->mapWithKeys(function (string $dimension) use ($payload): array {
            $hasIssue = collect($payload['findings'])->contains(fn (array $finding): bool => ($finding['dimension'] ?? null) === $dimension);

            return [$dimension => [
                'status' => $hasIssue ? 'issues_found' : 'pass',
                'summary' => $hasIssue ? '该维度存在结构化 Finding。' : '该维度未返回结构化 Finding。',
            ]];
        })->all();

        return $payload;
    }

    /** @return array<string, mixed> */
    private static function auditSchema(array $statuses): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['status', 'evidence'], 'properties' => [
            'status' => ['type' => 'string', 'enum' => $statuses],
            'evidence' => ['type' => ['string', 'null']],
        ]];
    }

    private static function assertAudit(mixed $audit, string $field): void
    {
        if (! is_array($audit) || ! array_key_exists('status', $audit) || ! array_key_exists('evidence', $audit)) {
            throw ValidationException::withMessages([$field => "{$field} 审计结构无效。"]);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function aggregateCriteriaStatus(array $items): string
    {
        return collect($items)->contains(fn (array $item): bool => $item['status'] === 'contradicted')
            ? 'contradicted'
            : (collect($items)->every(fn (array $item): bool => $item['status'] === 'fulfilled') ? 'fulfilled' : 'not_met');
    }

    private static function sceneIdForEvidence(Chapter $chapter, mixed $evidence): ?int
    {
        if (! is_string($evidence) || trim($evidence) === '') {
            return null;
        }

        $matches = $chapter->scenes->filter(fn ($scene): bool => str_contains((string) $scene->currentArtifact?->content, $evidence));

        return $matches->count() === 1 ? (int) $matches->first()->getKey() : null;
    }

    /** @param array<int, string> $required */
    private static function assertExactKeys(array $payload, array $required, string $field): void
    {
        $actual = array_keys($payload);
        sort($actual);
        sort($required);
        if ($actual !== $required) {
            throw ValidationException::withMessages([$field => 'Narrative Review 返回结构无效。']);
        }
    }
}
