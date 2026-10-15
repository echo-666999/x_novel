<?php

namespace App\Services;

use App\Enums\WorldEntityType;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use Illuminate\Validation\ValidationException;

/**
 * Review 的模型边界只保留语义判断。数据库身份、顺序和汇总状态均由
 * Laravel 从冻结的 Chapter Plan / Outline / Foreshadowing Contract 恢复。
 */
final class CompactReviewPayload
{
    private const DIMENSIONS = ['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'];

    /**
     * @param  array<string, int>|null  $contractCounts
     * @return array<string, mixed>
     */
    public static function schema(?array $contractCounts = null): array
    {
        $scores = array_fill_keys(self::DIMENSIONS, ['type' => 'number', 'minimum' => 0, 'maximum' => 100]);
        $audit = self::auditSchema(['fulfilled', 'not_met', 'contradicted']);
        $compactList = function (array $statuses, string $field) use ($contractCounts): array {
            $schema = ['type' => 'array', 'items' => self::auditSchema($statuses)];
            if (is_int($contractCounts[$field] ?? null)) {
                // 数量和顺序属于 Laravel 冻结合同。把精确数量下推到 Provider Schema，
                // 避免模型漏项后才在业务校验阶段发现并浪费整次审校费用。
                $schema['minItems'] = $contractCounts[$field];
                $schema['maxItems'] = $contractCounts[$field];
            }

            return $schema;
        };

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
                'milestone_completion' => $compactList(['fulfilled', 'not_met', 'contradicted'], 'milestone_completion'),
                'beat_exit' => $compactList(['fulfilled', 'not_met', 'contradicted'], 'beat_exit'),
                'handoff_readiness' => $compactList(['fulfilled', 'not_met', 'contradicted', 'not_applicable'], 'handoff_readiness'),
                'foreshadowing_audits' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['status', 'summary', 'evidence'],
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ForeshadowingReviewAudit::STATUSES],
                        'summary' => ['type' => 'string', 'minLength' => 1],
                        'evidence' => ['type' => ['string', 'null']],
                    ],
                ], ...self::arrayBounds($contractCounts, 'foreshadowing_audits')],
                'arc_beat_audits' => $compactList(['fulfilled', 'missing', 'contradicted'], 'arc_beat_audits'),
                'arc_completion_audits' => $compactList(['fulfilled', 'not_met'], 'arc_completion_audits'),
                'character_candidate_audits' => $compactList(['introduced', 'missing', 'contradicted'], 'character_candidate_audits'),
                'world_entity_candidate_audits' => $compactList(['introduced', 'missing', 'contradicted'], 'world_entity_candidate_audits'),
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

    /**
     * 从冻结 Plan、Outline 与伏笔合同计算模型必须逐项返回的精确数量。
     *
     * @param  array<string, mixed>  $outlineContract
     * @param  array<string, mixed>  $foreshadowingContract
     * @return array<string, int>
     */
    public static function contractCounts(ChapterPlan $plan, array $outlineContract, array $foreshadowingContract): array
    {
        return [
            'milestone_completion' => count((array) ($outlineContract['milestone_criteria'] ?? [])),
            'beat_exit' => count((array) ($outlineContract['beat_exit_criteria'] ?? [])),
            'handoff_readiness' => count((array) ($outlineContract['handoff_checks'] ?? [])),
            'foreshadowing_audits' => count((array) ($foreshadowingContract['actions'] ?? [])),
            'arc_beat_audits' => count((array) ($plan->arc_contributions ?? [])),
            'arc_completion_audits' => collect($plan->arc_contributions ?? [])->pluck('arc_id')->map(fn ($id): int => (int) $id)->unique()->count(),
            'character_candidate_audits' => count((array) ($plan->character_candidates ?? [])),
            'world_entity_candidate_audits' => count((array) ($plan->world_entity_candidates ?? [])),
        ];
    }

    /** @param array<string, mixed> $payload @param array<string, int> $counts @return array<int, string> */
    public static function contractMismatches(array $payload, array $counts): array
    {
        return collect($counts)
            ->filter(fn (int $expected, string $field): bool => ! is_array($payload[$field] ?? null)
                || count($payload[$field]) !== $expected)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * 修复请求只能返回已经确认缺项的合同数组，不能借机改写评分或 Findings。
     *
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public static function repairSchema(array $counts, array $fields): array
    {
        $properties = self::schema($counts)['properties'];
        $selected = collect($fields)->mapWithKeys(fn (string $field): array => [$field => $properties[$field]])->all();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_values($fields),
            'properties' => $selected,
        ];
    }

    /**
     * 校验原响应除待替换合同数组外仍完整有效，避免把其他 Schema 错误误判为可局部修复。
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public static function repairableSourceSchema(array $fields): array
    {
        $schema = self::schema();
        $schema['required'] = array_values(array_diff($schema['required'], $fields));
        foreach ($fields as $field) {
            if (array_key_exists($field, $schema['properties'])) {
                $schema['properties'][$field] = [];
            }
        }

        return $schema;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $outlineContract @param array<string, mixed> $foreshadowingContract @return array<string, mixed> */
    public static function expand(array $payload, Chapter $chapter, GenerationArtifact $draft, array $outlineContract, array $foreshadowingContract): array
    {
        self::assertExactKeys($payload, self::schema()['required'], 'review');
        $plan = $chapter->latestPlan;
        if ($plan === null) {
            throw ValidationException::withMessages(['review' => 'Review 缺少冻结 Chapter Plan。']);
        }

        $normalizeEvidence = fn (mixed $evidence): mixed => is_string($evidence)
            ? PlanCoverage::resolveRepresentativeExactEvidence((string) $draft->content, $evidence)
            : $evidence;
        $expandOrdered = function (mixed $audits, array $contracts, callable $identity, string $field, ?string $unboundStatus = null) use ($chapter, $normalizeEvidence): array {
            if (! is_array($audits) || count($audits) !== count($contracts)) {
                throw ValidationException::withMessages([$field => "{$field} 必须按冻结契约逐项完整返回。"]);
            }

            return collect(array_values($audits))->map(function (mixed $audit, int $index) use ($contracts, $identity, $field, $chapter, $normalizeEvidence, $unboundStatus): array {
                self::assertAudit($audit, $field);
                $evidence = $normalizeEvidence($audit['evidence']);
                $targetSequence = is_array($contracts[$index]) ? data_get($contracts[$index], 'target_scene_sequence') : null;
                $targetScene = is_numeric($targetSequence) ? $chapter->scenes->firstWhere('sequence', (int) $targetSequence) : null;
                $sceneId = $targetScene === null
                    ? self::sceneIdForEvidence($chapter, $evidence)
                    : (is_string($evidence) && $evidence !== '' && str_contains((string) $targetScene->currentArtifact?->content, $evidence)
                        ? (int) $targetScene->getKey()
                        : null);
                $status = $audit['status'];

                if ($unboundStatus !== null
                    && in_array($status, ['fulfilled', 'introduced'], true)
                    && $sceneId === null) {
                    // 过短或跨 Scene 重复的证据不能证明某个 Scene 已完成条件。
                    // 保守降级为未完成，让业务 Review 产生可执行 Finding。
                    $status = $unboundStatus;
                    $evidence = null;
                }

                // 冲突证据即使因跨 Scene 重复而无法唯一定位，也不能降级成“未完成”后丢失。
                // 保留 contradicted 与章节逐字证据，由 RewriteScopeResolver 导向章级处理。

                return [
                    ...$identity($contracts[$index], $index),
                    'status' => $status,
                    'evidence' => $evidence,
                    'scene_id' => $sceneId,
                ];
            })->all();
        };

        self::assertAudit($payload['chapter_plan_completion'], 'chapter_plan_completion');
        $payload['chapter_plan_completion']['evidence'] = $normalizeEvidence($payload['chapter_plan_completion']['evidence']);
        $payload['chapter_plan_completion']['scene_id'] = self::sceneIdForEvidence($chapter, $payload['chapter_plan_completion']['evidence']);

        foreach ([
            'milestone_completion' => $outlineContract['milestone_criteria'] ?? [],
            'beat_exit' => $outlineContract['beat_exit_criteria'] ?? [],
        ] as $field => $criteria) {
            $items = $expandOrdered($payload[$field], array_values($criteria), fn (string $criterion): array => ['criterion' => $criterion], $field, 'not_met');
            $payload[$field] = ['status' => self::aggregateCriteriaStatus($items), 'criteria' => $items];
        }

        $handoffChecks = array_values($outlineContract['handoff_checks'] ?? []);
        $checks = $expandOrdered($payload['handoff_readiness'], $handoffChecks, fn (array $check): array => [
            'key' => $check['key'], 'requirement' => $check['requirement'],
        ], 'handoff_readiness', 'not_met');
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
        ], 'arc_beat_audits', 'missing');
        $arcIds = collect($plan->arc_contributions ?? [])->pluck('arc_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $payload['arc_completion_audits'] = $expandOrdered($payload['arc_completion_audits'], $arcIds, fn (int $arcId): array => ['arc_id' => $arcId], 'arc_completion_audits');
        foreach ($payload['arc_completion_audits'] as &$audit) {
            unset($audit['scene_id']);
        }
        unset($audit);
        $payload['character_candidate_audits'] = $expandOrdered($payload['character_candidate_audits'], array_values($plan->character_candidates ?? []), fn (array $row): array => ['candidate_key' => (string) $row['candidate_key']], 'character_candidate_audits', 'missing');
        $payload['world_entity_candidate_audits'] = $expandOrdered($payload['world_entity_candidate_audits'], array_values($plan->world_entity_candidates ?? []), fn (array $row): array => ['candidate_key' => (string) $row['candidate_key']], 'world_entity_candidate_audits', 'missing');

        foreach (['unapproved_characters', 'unapproved_world_entities', 'findings'] as $field) {
            if (! is_array($payload[$field])) {
                throw ValidationException::withMessages([$field => "{$field} 必须返回数组。"]);
            }
            foreach ($payload[$field] as $index => $item) {
                if (! is_array($item)) {
                    throw ValidationException::withMessages([$field => "{$field} 结构无效。"]);
                }
                $payload[$field][$index]['evidence'] = $normalizeEvidence($item['evidence'] ?? null);
                $payload[$field][$index]['scene_id'] = ($item['scope'] ?? 'scene') === 'chapter'
                    ? null
                    : self::sceneIdForEvidence($chapter, $payload[$field][$index]['evidence']);

                if ($field === 'findings'
                    && ($item['severity'] ?? null) === 'error'
                    && ($item['auto_fixable'] ?? null) === false
                    && ($item['requires_human_decision'] ?? null) === false) {
                    // Error 必须有明确处置路径。Chapter 级问题只进入确定性的
                    // Plan/Scene 重建建议；局部问题在模型未确认可自动修复时交给人工。
                    $chapterScope = ($item['scope'] ?? null) === 'chapter';
                    $payload[$field][$index]['auto_fixable'] = $chapterScope;
                    $payload[$field][$index]['requires_human_decision'] = ! $chapterScope;
                }
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

    /** @param array<string, int>|null $counts @return array<string, int> */
    private static function arrayBounds(?array $counts, string $field): array
    {
        return is_int($counts[$field] ?? null)
            ? ['minItems' => $counts[$field], 'maxItems' => $counts[$field]]
            : [];
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
