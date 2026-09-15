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
            'required' => ['content', 'temporary_state_delta', 'declared_events', 'uncertainties', 'self_check', 'foreshadowing_coverage'],
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
                    ...PlanCoverage::schema(),
                    'description' => 'Coverage of the planned goal, conflict, turn, and outcome.',
                ],
                'foreshadowing_coverage' => [
                    ...ForeshadowingCoverage::schema(),
                    'description' => 'Coverage of every foreshadowing action assigned to this scene, in plan order.',
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function validate(array $payload, array $foreshadowingExpectations = []): array
    {
        if (! self::hasExactKeys($payload, ['content', 'temporary_state_delta', 'declared_events', 'uncertainties', 'self_check', 'foreshadowing_coverage'])) {
            throw ValidationException::withMessages(['scene_draft' => 'Scene Draft 必须只包含 content、temporary_state_delta、declared_events、uncertainties、self_check 和 foreshadowing_coverage。']);
        }

        $machineFields = self::validateMachineFields(
            $payload['temporary_state_delta'] ?? null,
            $payload['declared_events'] ?? null,
        );
        $payload['temporary_state_delta'] = $machineFields['temporary_state_delta'];
        $payload['declared_events'] = $machineFields['declared_events'];
        $validated = validator($payload, [
            'content' => ['required', 'string', 'min:1'],
            'temporary_state_delta' => ['present', 'array'],
            'declared_events' => ['present', 'array'],
            'declared_events.*' => ['array'],
            'uncertainties' => ['present', 'array'],
            'uncertainties.*' => ['string'],
            'self_check' => ['required', 'array'],
            'foreshadowing_coverage' => ['present', 'array'],
        ])->validate();

        if (blank(trim($validated['content']))) {
            throw ValidationException::withMessages(['content' => 'Scene 正文不能为空。']);
        }

        $validated['self_check'] = PlanCoverage::validate($validated['self_check'], $validated['content'], 'self_check');
        $validated['foreshadowing_coverage'] = ForeshadowingCoverage::validate(
            $validated['foreshadowing_coverage'],
            $validated['content'],
            $foreshadowingExpectations,
            'foreshadowing_coverage',
        );

        return $validated;
    }

    /** @return array{temporary_state_delta: array<string, mixed>, declared_events: array<int, array<string, mixed>>} */
    public static function validateMachineFields(mixed $temporaryStateDelta, mixed $declaredEvents): array
    {
        return [
            'temporary_state_delta' => self::decodeObject($temporaryStateDelta, 'temporary_state_delta'),
            'declared_events' => self::decodeObjectList($declaredEvents),
        ];
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

    /** @param array<int, string> $keys */
    private static function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
