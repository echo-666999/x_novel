<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class SceneRewritePayload
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['content', 'self_check'],
            'properties' => [
                'content' => ['type' => 'string'],
                'self_check' => PlanCoverage::schema(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{content: string, self_check: array<string, array{status: string, evidence: string|null}>}
     */
    public static function validate(array $payload): array
    {
        if (! self::hasExactKeys($payload, ['content', 'self_check'])) {
            throw ValidationException::withMessages(['scene_rewrite' => 'Scene Rewrite 必须只包含 content 和 self_check。']);
        }

        $validated = validator($payload, [
            'content' => ['required', 'string', 'min:1'],
            'self_check' => ['required', 'array'],
        ])->validate();

        if (blank(trim($validated['content']))) {
            throw ValidationException::withMessages(['content' => 'Scene Rewrite 正文不能为空。']);
        }

        $validated['self_check'] = PlanCoverage::validate(
            $validated['self_check'],
            $validated['content'],
            'self_check',
        );

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
