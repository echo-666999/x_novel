<?php

namespace App\Services;

use App\Enums\GenerationStage;
use BackedEnum;
use DateTimeInterface;

/**
 * Builds semantic stage fingerprints. Runtime delivery facts must never affect reuse.
 */
final class GenerationStageFingerprint
{
    private const RUNTIME_KEYS = [
        'attempt', 'attempts', 'retry_count', 'queue', 'queue_id', 'job_id',
        'operation_id', 'created_at', 'updated_at', 'started_at', 'finished_at',
        'queued_at', 'dispatched_at', 'heartbeat_at', 'request_started_at',
    ];

    /**
     * @param  array<string, mixed>  $businessInputs
     * @param  array<int|string, mixed>  $upstreamChecksums
     * @param  array<string, mixed>  $frozen
     */
    public function make(
        GenerationStage|string $stage,
        array $businessInputs,
        array $upstreamChecksums = [],
        array $frozen = [],
        ?string $contractVersion = null,
    ): string {
        return hash('sha256', json_encode($this->normalize([
            'stage' => $stage instanceof GenerationStage ? $stage->value : $stage,
            'contract_version' => $contractVersion,
            'business_inputs' => $businessInputs,
            'upstream_checksums' => $upstreamChecksums,
            'frozen' => $frozen,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function normalize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array($key, self::RUNTIME_KEYS, true)) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        $normalized = [];
        foreach ($value as $itemKey => $item) {
            if (in_array((string) $itemKey, self::RUNTIME_KEYS, true)) {
                continue;
            }
            $normalized[(string) $itemKey] = $this->normalize($item, (string) $itemKey);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
