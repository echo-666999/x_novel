<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class PlanCoverage
{
    public const ELEMENTS = ['goal', 'conflict', 'turn', 'outcome'];

    public const STATUSES = ['fulfilled', 'missing', 'contradicted'];

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $item = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'evidence'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => self::STATUSES],
                'evidence' => ['type' => ['string', 'null']],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => self::ELEMENTS,
            'properties' => array_fill_keys(self::ELEMENTS, $item),
        ];
    }

    /** @return array<string, array{status: string, evidence: string|null}> */
    public static function validate(mixed $coverage, string $content, string $path = 'coverage'): array
    {
        if (! is_array($coverage) || ! self::hasExactKeys($coverage, self::ELEMENTS)) {
            throw ValidationException::withMessages([$path => 'Coverage 必须完整包含 goal、conflict、turn、outcome。']);
        }

        foreach (self::ELEMENTS as $element) {
            $item = $coverage[$element];
            $itemPath = "{$path}.{$element}";

            if (! is_array($item) || ! self::hasExactKeys($item, ['status', 'evidence'])) {
                throw ValidationException::withMessages([$itemPath => 'Coverage Item 必须只包含 status 和 evidence。']);
            }

            $status = $item['status'];
            $evidence = $item['evidence'];

            if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
                throw ValidationException::withMessages(["{$itemPath}.status" => 'Coverage status 必须是 fulfilled、missing 或 contradicted。']);
            }

            if ($status === 'missing') {
                if ($evidence !== null) {
                    throw ValidationException::withMessages(["{$itemPath}.evidence" => 'missing Coverage 的 evidence 必须为 null。']);
                }

                continue;
            }

            if (! is_string($evidence) || trim($evidence) === '' || ! str_contains($content, $evidence)) {
                throw ValidationException::withMessages(["{$itemPath}.evidence" => 'fulfilled 或 contradicted Coverage 的 evidence 必须逐字来自当前正文。']);
            }
        }

        return $coverage;
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  array<string, mixed>  $scenePlan
     * @return array<string, mixed>
     */
    public static function expectations(array $scene, array $scenePlan = []): array
    {
        return [
            'goal' => $scene['goal'] ?? null,
            'conflict' => $scene['conflict'] ?? null,
            'turn' => $scene['turn'] ?? null,
            'outcome' => [
                'description' => $scene['outcome'] ?? null,
                'allowed' => data_get($scenePlan, 'outcome_allowed', []),
                'forbidden' => data_get($scenePlan, 'outcome_forbidden', []),
            ],
        ];
    }

    /**
     * @param  array<string, array{status: string, evidence: string|null}>  $coverage
     * @param  array<string, mixed>  $expectations
     * @return array<int, array<string, mixed>>
     */
    public static function findings(int $sceneId, array $coverage, array $expectations, string $phase): array
    {
        $labels = [
            'goal' => '目标',
            'conflict' => '冲突',
            'turn' => '转折',
            'outcome' => '结果',
        ];

        return collect(self::ELEMENTS)
            ->filter(fn (string $element): bool => $coverage[$element]['status'] !== 'fulfilled')
            ->map(function (string $element) use ($sceneId, $coverage, $expectations, $phase, $labels): array {
                $status = $coverage[$element]['status'];

                return [
                    'code' => $status === 'missing'
                        ? 'SCENE_PLAN_COVERAGE_MISSING'
                        : 'SCENE_PLAN_COVERAGE_CONTRADICTED',
                    'dimension' => 'plan',
                    'severity' => 'error',
                    'scene_id' => $sceneId,
                    'scope' => 'scene',
                    'auto_fixable' => true,
                    'requires_human_decision' => false,
                    'plan_element' => $element,
                    'coverage_status' => $status,
                    'expected' => $expectations[$element] ?? null,
                    'evidence' => $coverage[$element]['evidence'],
                    'message' => '场景计划的'.$labels[$element].($status === 'missing' ? '未在正文中落实。' : '被正文反转。'),
                    'source' => $phase,
                ];
            })
            ->values()
            ->all();
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
