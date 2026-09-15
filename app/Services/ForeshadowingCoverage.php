<?php

namespace App\Services;

use App\Enums\ForeshadowingPlanAction;
use Illuminate\Validation\ValidationException;

final class ForeshadowingCoverage
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['foreshadowing_id', 'action', 'status', 'evidence'],
                'properties' => [
                    'foreshadowing_id' => ['type' => 'integer', 'minimum' => 1],
                    'action' => [
                        'type' => 'string',
                        'enum' => array_map(fn (ForeshadowingPlanAction $action): string => $action->value, ForeshadowingPlanAction::cases()),
                    ],
                    'status' => ['type' => 'string', 'enum' => PlanCoverage::STATUSES],
                    'evidence' => ['type' => ['string', 'null']],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<int, array<string, mixed>>
     */
    public static function expectationsForScene(array $contract, int $sceneSequence): array
    {
        return collect($contract['actions'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item)
                && (int) data_get($item, 'plan_action.target_scene_sequence') === $sceneSequence)
            ->map(fn (array $item): array => [
                'foreshadowing_id' => (int) ($item['foreshadowing_id'] ?? 0),
                'action' => (string) data_get($item, 'plan_action.action'),
                'title' => $item['title'] ?? null,
                'promised_payoff' => $item['promised_payoff'] ?? null,
                'acceptance_criteria' => data_get($item, 'plan_action.acceptance_criteria'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $expectations
     * @return array<int, array{foreshadowing_id: int, action: string, status: string, evidence: string|null}>
     */
    public static function validate(mixed $coverage, string $content, array $expectations, string $path): array
    {
        if (! is_array($coverage)) {
            throw ValidationException::withMessages([$path => '伏笔 Coverage 必须是数组。']);
        }

        $expectedIdentities = collect($expectations)->map(fn (array $item): array => [
            'foreshadowing_id' => (int) ($item['foreshadowing_id'] ?? 0),
            'action' => (string) ($item['action'] ?? ''),
        ])->all();
        $actualIdentities = [];

        foreach ($coverage as $index => $row) {
            $itemPath = "{$path}.{$index}";

            if (! is_array($row) || ! self::hasExactKeys($row, ['foreshadowing_id', 'action', 'status', 'evidence'])) {
                throw ValidationException::withMessages([$itemPath => '伏笔 Coverage Item 必须只包含 foreshadowing_id、action、status 和 evidence。']);
            }

            $id = filter_var($row['foreshadowing_id'], FILTER_VALIDATE_INT);
            $action = is_string($row['action']) ? ForeshadowingPlanAction::tryFrom($row['action']) : null;

            if ($id === false || $id < 1 || $action === null) {
                throw ValidationException::withMessages([$itemPath => '伏笔 Coverage 必须引用合法的伏笔 ID 和动作。']);
            }

            $coverage[$index] = [
                'foreshadowing_id' => $id,
                'action' => $action->value,
                ...self::validateEvidence($row['status'], $row['evidence'], $content, $itemPath),
            ];
            $actualIdentities[] = ['foreshadowing_id' => $id, 'action' => $action->value];
        }

        if ($actualIdentities !== $expectedIdentities) {
            throw ValidationException::withMessages([
                $path => '伏笔 Coverage 必须按 Plan 顺序且不重复地覆盖当前 Scene 的全部伏笔动作，不能引用其他 Scene、其他伏笔或其他动作。',
            ]);
        }

        return array_values($coverage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $coverage
     * @param  array<int, array<string, mixed>>  $expectations
     * @return array<int, array<string, mixed>>
     */
    public static function fallbackUnverifiableEvidenceToMissing(array $coverage, string $content, array $expectations, string $path): array
    {
        foreach ($coverage as $index => $row) {
            if (! is_array($row) || ! self::hasExactKeys($row, ['foreshadowing_id', 'action', 'status', 'evidence'])) {
                return self::validate($coverage, $content, $expectations, $path);
            }

            if (($row['status'] ?? null) === 'missing') {
                $coverage[$index]['evidence'] = null;

                continue;
            }

            $evidence = is_string($row['evidence'] ?? null)
                ? PlanCoverage::resolveExactEvidence($content, $row['evidence'])
                : '';

            if ($evidence !== '' && str_contains($content, $evidence)) {
                $coverage[$index]['evidence'] = $evidence;

                continue;
            }

            $coverage[$index]['status'] = 'missing';
            $coverage[$index]['evidence'] = null;
        }

        return self::validate($coverage, $content, $expectations, $path);
    }

    /**
     * @param  array<int, array<string, mixed>>  $coverage
     * @param  array<int, array<string, mixed>>  $expectations
     * @return array<int, array<string, mixed>>
     */
    public static function findings(int $sceneId, array $coverage, array $expectations, string $source): array
    {
        return collect($coverage)
            ->filter(fn (array $item): bool => $item['status'] !== 'fulfilled')
            ->map(function (array $item, int $index) use ($sceneId, $expectations, $source): array {
                $expected = $expectations[$index] ?? [];
                $status = $item['status'];
                $title = filled($expected['title'] ?? null)
                    ? "「{$expected['title']}」"
                    : "#{$item['foreshadowing_id']}";

                return [
                    'code' => $status === 'missing'
                        ? 'FORESHADOWING_COVERAGE_MISSING'
                        : 'FORESHADOWING_COVERAGE_CONTRADICTED',
                    'dimension' => 'plan',
                    'severity' => 'error',
                    'scene_id' => $sceneId,
                    'scope' => 'scene',
                    'auto_fixable' => true,
                    'requires_human_decision' => false,
                    'foreshadowing_id' => $item['foreshadowing_id'],
                    'foreshadowing_action' => $item['action'],
                    'coverage_status' => $status,
                    'acceptance_criteria' => $expected['acceptance_criteria'] ?? null,
                    'evidence' => $item['evidence'],
                    'message' => "伏笔{$title}的 {$item['action']} 动作".($status === 'missing' ? '未在正文中落实。' : '被正文反转。'),
                    'source' => $source,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{status: string, evidence: string|null} */
    private static function validateEvidence(mixed $status, mixed $evidence, string $content, string $path): array
    {
        if (! is_string($status) || ! in_array($status, PlanCoverage::STATUSES, true)) {
            throw ValidationException::withMessages(["{$path}.status" => '伏笔 Coverage status 必须是 fulfilled、missing 或 contradicted。']);
        }

        if ($status === 'missing') {
            if ($evidence !== null) {
                throw ValidationException::withMessages(["{$path}.evidence" => 'missing 伏笔 Coverage 的 evidence 必须为 null。']);
            }

            return ['status' => $status, 'evidence' => null];
        }

        if (! is_string($evidence) || trim($evidence) === '') {
            throw ValidationException::withMessages(["{$path}.evidence" => 'fulfilled 或 contradicted 伏笔 Coverage 的 evidence 必须逐字来自当前正文。']);
        }

        $evidence = PlanCoverage::resolveExactEvidence($content, $evidence);

        if (! str_contains($content, $evidence)) {
            throw ValidationException::withMessages(["{$path}.evidence" => 'fulfilled 或 contradicted 伏笔 Coverage 的 evidence 必须逐字来自当前正文。']);
        }

        return ['status' => $status, 'evidence' => $evidence];
    }

    /** @param array<int, string> $keys */
    private static function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
