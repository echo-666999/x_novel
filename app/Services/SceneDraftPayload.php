<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class SceneDraftPayload
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['content', 'temporary_state_delta', 'declared_events', 'uncertainties', 'self_check'],
            'properties' => [
                'content' => ['type' => 'string'],
                'temporary_state_delta' => ['type' => 'object'],
                'declared_events' => ['type' => 'array', 'items' => ['type' => 'object']],
                'uncertainties' => ['type' => 'array', 'items' => ['type' => 'string']],
                'self_check' => ['type' => 'object'],
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function validate(array $payload): array
    {
        $validated = validator($payload, [
            'content' => ['required', 'string', 'min:1'],
            'temporary_state_delta' => ['present', 'array'],
            'declared_events' => ['present', 'array'],
            'declared_events.*' => ['array'],
            'uncertainties' => ['present', 'array'],
            'uncertainties.*' => ['string'],
            'self_check' => ['present', 'array'],
        ])->validate();

        if (blank(trim($validated['content']))) {
            throw ValidationException::withMessages(['content' => 'Scene 正文不能为空。']);
        }

        return $validated;
    }
}
