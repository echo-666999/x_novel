<?php

namespace App\Services;

use App\Models\Novel;
use App\Models\StoryStateVersion;

class StoryStateService
{
    public function current(Novel $novel): ?StoryStateVersion
    {
        return $novel->canonicalStateVersion()->first();
    }

    public function findVersion(Novel $novel, int $version): ?StoryStateVersion
    {
        return $novel->storyStateVersions()
            ->where('version', $version)
            ->first();
    }

    /**
     * @param  array<string|int, mixed>  $state
     * @param  array<string|int, mixed>  $patch
     * @return array<string|int, mixed>
     */
    public function previewPatch(array $state, array $patch): array
    {
        return $this->mergePatch($state, $patch);
    }

    /**
     * @param  array<string|int, mixed>  $before
     * @param  array<string|int, mixed>  $after
     * @return array<int, array{path: string, before: mixed, after: mixed, before_missing: bool, after_missing: bool, type: string}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        $this->collectChanges($before, $after, '', false, false, $changes);

        usort($changes, fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return $changes;
    }

    /** @param array<string|int, mixed> $state */
    public function checksum(array $state): string
    {
        return hash('sha256', json_encode(
            $this->sortAssociativeKeys($state),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  array<string|int, mixed>  $state
     * @param  array<string|int, mixed>  $patch
     * @return array<string|int, mixed>
     */
    private function mergePatch(array $state, array $patch): array
    {
        if (array_is_list($state) || array_is_list($patch)) {
            return $patch;
        }

        foreach ($patch as $key => $value) {
            $state[$key] = is_array($value)
                && isset($state[$key])
                && is_array($state[$key])
                ? $this->mergePatch($state[$key], $value)
                : $value;
        }

        return $state;
    }

    /**
     * @param  array<int, array{path: string, before: mixed, after: mixed, before_missing: bool, after_missing: bool, type: string}>  $changes
     */
    private function collectChanges(
        mixed $before,
        mixed $after,
        string $path,
        bool $beforeMissing,
        bool $afterMissing,
        array &$changes,
    ): void {
        if (! $beforeMissing && ! $afterMissing && is_array($before) && is_array($after)) {
            $keys = array_unique([...array_keys($before), ...array_keys($after)]);

            foreach ($keys as $key) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;
                $hasBefore = array_key_exists($key, $before);
                $hasAfter = array_key_exists($key, $after);

                $this->collectChanges(
                    $hasBefore ? $before[$key] : null,
                    $hasAfter ? $after[$key] : null,
                    $childPath,
                    ! $hasBefore,
                    ! $hasAfter,
                    $changes,
                );
            }

            return;
        }

        if (! $beforeMissing && ! $afterMissing && $before === $after) {
            return;
        }

        $changes[] = [
            'path' => $path,
            'before' => $before,
            'after' => $after,
            'before_missing' => $beforeMissing,
            'after_missing' => $afterMissing,
            'type' => $beforeMissing ? 'added' : ($afterMissing ? 'removed' : 'changed'),
        ];
    }

    /** @return array<string|int, mixed> */
    private function sortAssociativeKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortAssociativeKeys($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
