<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class SceneLengthRepairPayload
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['content', 'self_check', 'foreshadowing_coverage'],
            'properties' => [
                'content' => ['type' => 'string'],
                'self_check' => PlanCoverage::schema(),
                'foreshadowing_coverage' => ForeshadowingCoverage::schema(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function validate(array $payload, array $foreshadowingExpectations): array
    {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['content', 'foreshadowing_coverage', 'self_check']) {
            throw ValidationException::withMessages([
                'scene_length_repair' => 'Scene 字数修复必须只包含 content、self_check 和 foreshadowing_coverage。',
            ]);
        }
        $content = $payload['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw ValidationException::withMessages(['content' => 'Scene 字数修复正文不能为空。']);
        }

        return [
            'content' => $content,
            'self_check' => PlanCoverage::validate(
                is_array($payload['self_check'] ?? null) ? $payload['self_check'] : [],
                $content,
                'self_check',
            ),
            'foreshadowing_coverage' => ForeshadowingCoverage::validate(
                is_array($payload['foreshadowing_coverage'] ?? null) ? $payload['foreshadowing_coverage'] : [],
                $content,
                $foreshadowingExpectations,
                'foreshadowing_coverage',
            ),
        ];
    }
}
