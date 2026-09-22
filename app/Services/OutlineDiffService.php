<?php

namespace App\Services;

class OutlineDiffService
{
    /** @param array<string, mixed> $before @param array<string, mixed> $after @return array<string, array<int, array<string, mixed>>> */
    public function compare(array $before, array $after): array
    {
        $old = $this->flatten($before);
        $new = $this->flatten($after);
        $result = ['added' => [], 'removed' => [], 'reordered' => [], 'modified' => []];

        foreach (array_diff_key($new, $old) as $key => $node) {
            $result['added'][] = ['key' => $key, 'type' => $node['type'], 'parent_key' => $node['parent_key']];
        }
        foreach (array_diff_key($old, $new) as $key => $node) {
            $result['removed'][] = ['key' => $key, 'type' => $node['type'], 'parent_key' => $node['parent_key']];
        }
        foreach (array_intersect_key($new, $old) as $key => $node) {
            $previous = $old[$key];
            if ($previous['sequence'] !== $node['sequence'] || $previous['parent_key'] !== $node['parent_key']) {
                $result['reordered'][] = [
                    'key' => $key,
                    'type' => $node['type'],
                    'from_parent_key' => $previous['parent_key'],
                    'to_parent_key' => $node['parent_key'],
                    'from_sequence' => $previous['sequence'],
                    'to_sequence' => $node['sequence'],
                ];
            }
            if ($previous['semantic'] !== $node['semantic']) {
                $changed = array_keys(array_filter(
                    array_merge(array_fill_keys(array_keys($previous['semantic']), true), array_fill_keys(array_keys($node['semantic']), true)),
                    fn (bool $_, string $field): bool => ($previous['semantic'][$field] ?? null) !== ($node['semantic'][$field] ?? null),
                    ARRAY_FILTER_USE_BOTH,
                ));
                sort($changed);
                $result['modified'][] = ['key' => $key, 'type' => $node['type'], 'changed_fields' => $changed];
            }
        }
        foreach ($result as &$items) {
            usort($items, fn (array $a, array $b): int => [$a['type'], $a['key']] <=> [$b['type'], $b['key']]);
        }

        return $result;
    }

    /** @param array<string, mixed> $content @return array<string, array<string, mixed>> */
    private function flatten(array $content): array
    {
        $outline = $content;
        unset($outline['volumes']);
        ksort($outline);
        $nodes = [
            '@outline' => [
                'type' => 'outline',
                'parent_key' => null,
                'sequence' => null,
                'semantic' => $outline,
            ],
        ];
        foreach ($content['volumes'] ?? [] as $volume) {
            if (! is_array($volume) || ! is_string($volume['key'] ?? null)) {
                continue;
            }
            $nodes[$volume['key']] = $this->node('volume', null, $volume, 'arcs');
            foreach ($volume['arcs'] ?? [] as $arc) {
                if (! is_array($arc) || ! is_string($arc['key'] ?? null)) {
                    continue;
                }
                $nodes[$arc['key']] = $this->node('arc', $volume['key'], $arc, 'beats');
                foreach ($arc['beats'] ?? [] as $beat) {
                    if (is_array($beat) && is_string($beat['key'] ?? null)) {
                        $nodes[$beat['key']] = $this->node('beat', $arc['key'], $beat, null);
                    }
                }
            }
        }

        return $nodes;
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function node(string $type, ?string $parent, array $value, ?string $children): array
    {
        $semantic = $value;
        unset($semantic['key'], $semantic['sequence']);
        if ($children !== null) {
            unset($semantic[$children]);
        }
        ksort($semantic);

        return ['type' => $type, 'parent_key' => $parent, 'sequence' => $value['sequence'] ?? null, 'semantic' => $semantic];
    }
}
