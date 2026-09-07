<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class StatePatch
{
    public const OPERATIONS = ['set', 'unset', 'increment', 'append_unique', 'remove'];

    public const STATE_DOMAINS = [
        'characters', 'relationships', 'locations', 'items', 'world', 'timeline',
        'open_threads', 'foreshadowings', 'reader_promises',
    ];

    /**
     * @param  array<int, array{op: string, path: string, value?: mixed, source_event_index: int}>  $operations
     * @param  array<int, mixed>  $factChanges
     * @param  array<int, mixed>  $foreshadowingChanges
     */
    public function __construct(
        public int $expectedStateVersion,
        public array $operations,
        public array $factChanges = [],
        public array $foreshadowingChanges = [],
    ) {
        Validator::make($this->toArray(), [
            'expected_state_version' => ['required', 'integer', 'min:0'],
            'operations' => ['array'],
            'operations.*.op' => ['required', Rule::in(self::OPERATIONS)],
            'operations.*.path' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $segments = explode('.', (string) $value);

                if (! in_array($segments[0] ?? null, self::STATE_DOMAINS, true) || count($segments) < 2) {
                    $fail('State Patch 路径必须位于已声明的 Story State Domain。');
                }

                if (collect($segments)->contains(fn (string $segment): bool => $segment === '' || str_contains($segment, '*'))) {
                    $fail('State Patch 路径包含非法片段。');
                }
            }],
            'operations.*.source_event_index' => ['required', 'integer', 'min:0'],
            'fact_changes' => ['array'],
            'foreshadowing_changes' => ['array'],
        ])->validate();

        foreach ($operations as $operation) {
            if ($operation['op'] !== 'unset' && ! array_key_exists('value', $operation)) {
                throw ValidationException::withMessages([
                    'operations' => '除 unset 外的 State Patch Operation 必须提供 value。',
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'expected_state_version' => $this->expectedStateVersion,
            'operations' => $this->operations,
            'fact_changes' => $this->factChanges,
            'foreshadowing_changes' => $this->foreshadowingChanges,
        ];
    }
}
