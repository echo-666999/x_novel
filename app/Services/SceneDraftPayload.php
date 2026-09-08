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
                'temporary_state_delta' => [
                    'type' => 'string',
                    'description' => 'JSON object containing temporary state changes for the next scene. Use {} when there are no changes.',
                ],
                'declared_events' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'description' => 'One declared event encoded as a JSON object.',
                    ],
                ],
                'uncertainties' => ['type' => 'array', 'items' => ['type' => 'string']],
                'self_check' => [
                    'type' => 'string',
                    'description' => 'Self-check result encoded as a JSON object.',
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function validate(array $payload): array
    {
        $payload['temporary_state_delta'] = self::decodeObject($payload['temporary_state_delta'] ?? null, 'temporary_state_delta');
        $payload['declared_events'] = self::decodeObjectList($payload['declared_events'] ?? null);
        $payload['self_check'] = self::decodeObject($payload['self_check'] ?? null, 'self_check');

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

    /** @return array<string, mixed> */
    private static function decodeObject(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value);

            if (is_object($decoded)) {
                return json_decode($value, true);
            }
        }

        throw ValidationException::withMessages([$field => $field.' 必须是 JSON 对象。']);
    }

    /** @return array<int, array<string, mixed>> */
    private static function decodeObjectList(mixed $value): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages(['declared_events' => 'declared_events 必须是数组。']);
        }

        return array_map(
            fn (mixed $event): array => self::decodeObject($event, 'declared_events'),
            $value,
        );
    }
}
