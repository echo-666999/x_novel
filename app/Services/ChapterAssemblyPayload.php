<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\GenerationArtifact;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ChapterAssemblyPayload
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $coverage = PlanCoverage::schema();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['content', 'scene_coverage', 'introduced_major_facts'],
            'properties' => [
                'content' => ['type' => 'string'],
                'scene_coverage' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['scene_id', ...PlanCoverage::ELEMENTS],
                        'properties' => [
                            'scene_id' => ['type' => 'integer', 'minimum' => 1],
                            ...$coverage['properties'],
                        ],
                    ],
                ],
                'introduced_major_facts' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, GenerationArtifact>  $sourceArtifacts
     * @return array<string, mixed>
     */
    public static function validate(array $payload, Chapter $chapter, Collection $sourceArtifacts): array
    {
        if (! self::hasExactKeys($payload, ['content', 'scene_coverage', 'introduced_major_facts'])) {
            throw ValidationException::withMessages(['assembly' => 'Assembly Payload 必须只包含 content、scene_coverage 和 introduced_major_facts。']);
        }

        $validated = validator($payload, [
            'content' => ['required', 'string', 'min:1'],
            'scene_coverage' => ['present', 'array'],
            'introduced_major_facts' => ['present', 'array'],
            'introduced_major_facts.*' => ['string'],
        ])->validate();

        if (blank(trim($validated['content']))) {
            throw ValidationException::withMessages(['content' => 'Chapter Assembly 正文不能为空。']);
        }

        if ($validated['introduced_major_facts'] !== []) {
            throw ValidationException::withMessages(['introduced_major_facts' => 'Chapter Assembly 不得新增重大事实、能力、世界规则或角色知识。']);
        }

        $scenes = $chapter->scenes->sortBy('sequence')->values();
        $expectedSceneIds = $scenes->modelKeys();
        $actualSceneIds = collect($validated['scene_coverage'])->pluck('scene_id')->all();

        if (count($actualSceneIds) !== count(array_unique($actualSceneIds, SORT_REGULAR))
            || $actualSceneIds !== $expectedSceneIds) {
            throw ValidationException::withMessages(['scene_coverage' => 'Assembly Coverage 必须按顺序且不重复地引用本章全部 Scene。']);
        }

        $sourceByScene = $sourceArtifacts->keyBy(fn (GenerationArtifact $artifact): int => (int) $artifact->generationRun->scene_id);
        $findings = [];

        foreach ($validated['scene_coverage'] as $index => $row) {
            if (! is_array($row) || ! self::hasExactKeys($row, ['scene_id', ...PlanCoverage::ELEMENTS])) {
                throw ValidationException::withMessages(["scene_coverage.{$index}" => 'Scene Coverage 必须包含 scene_id、goal、conflict、turn 和 outcome。']);
            }

            $sceneId = (int) $row['scene_id'];
            $coverage = PlanCoverage::validate(
                collect($row)->except('scene_id')->all(),
                $validated['content'],
                "scene_coverage.{$index}",
            );
            $sourceCoverage = data_get($sourceByScene->get($sceneId)?->data, 'self_check');

            if (is_array($sourceCoverage)) {
                foreach (PlanCoverage::ELEMENTS as $element) {
                    $sourceStatus = data_get($sourceCoverage, "{$element}.status");

                    if (in_array($sourceStatus, ['missing', 'contradicted'], true)
                        && $coverage[$element]['status'] === 'fulfilled') {
                        throw ValidationException::withMessages([
                            "scene_coverage.{$index}.{$element}" => 'Assembly 不得把 Scene Draft 中缺失或反转的计划项改写为 fulfilled。',
                        ]);
                    }
                }
            }

            $scene = $scenes->firstWhere('id', $sceneId);
            $scenePlan = data_get($chapter->latestPlan?->scene_plans, $index, []);
            $findings = [
                ...$findings,
                ...PlanCoverage::findings(
                    $sceneId,
                    $coverage,
                    PlanCoverage::expectations($scene?->only(PlanCoverage::ELEMENTS) ?? [], $scenePlan),
                    'assembly_coverage',
                ),
            ];
            $validated['scene_coverage'][$index] = ['scene_id' => $sceneId, ...$coverage];
        }

        $validated['plan_findings'] = $findings;

        return $validated;
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
