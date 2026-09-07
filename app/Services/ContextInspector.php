<?php

namespace App\Services;

use App\Models\GenerationRun;
use App\Models\Memory;

class ContextInspector
{
    /** @return array<string, mixed> */
    public function inspect(GenerationRun $run): array
    {
        $snapshot = is_array($run->context_snapshot) ? $run->context_snapshot : [];
        $memoryIds = collect(data_get($snapshot, 'memory_ids', []))
            ->merge(collect(data_get($snapshot, 'l3.memories', []))->pluck('id'))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $memories = Memory::query()
            ->where('novel_id', $run->novel_id)
            ->whereKey($memoryIds)
            ->get()
            ->keyBy('id');
        $snapshotMemories = collect(data_get($snapshot, 'l3.memories', []))->keyBy('id');

        return [
            'meta' => [
                'state_version' => data_get($snapshot, 'state_version', $run->state_version),
                'bible_version' => data_get($snapshot, 'bible_version', $run->bible_version),
                'prompt_version' => data_get($snapshot, 'prompt_version', $run->prompt_version),
                'model' => data_get($snapshot, 'model', $run->model_policy),
            ],
            'l0' => $this->layer($snapshot, 'l0'),
            'l1' => $this->layer($snapshot, 'l1'),
            'l2' => $this->layer($snapshot, 'l2'),
            'l3' => $this->layer($snapshot, 'l3'),
            'l4' => $this->layer($snapshot, 'l4'),
            'token_allocation' => $this->tokenAllocation($snapshot),
            'truncated_sections' => collect(data_get($snapshot, 'truncated_sections', []))
                ->filter(fn (mixed $section): bool => is_string($section) && $section !== '')
                ->map(fn (string $section): array => ['section' => $section])
                ->values()
                ->all(),
            'selected_memories' => $memoryIds->map(function (int $id) use ($memories, $snapshotMemories): array {
                $memory = $memories->get($id);
                $frozen = $snapshotMemories->get($id, []);

                if ($memory === null) {
                    return [
                        'id' => $id,
                        'summary' => '当前 Memory 记录不可用；Snapshot 仍保留引用 ID。',
                        'type' => '—',
                        'source' => '—',
                        'source_chapter' => null,
                        'status' => '不可用',
                        'final_score' => data_get($frozen, 'final_score'),
                    ];
                }

                return [
                    'id' => $id,
                    'summary' => data_get($frozen, 'summary', $memory->summary),
                    'type' => $memory->type->getLabel(),
                    'source' => $memory->sourceLabel(),
                    'source_chapter' => $memory->valid_from_chapter,
                    'status' => $memory->status->getLabel(),
                    'final_score' => data_get($frozen, 'final_score'),
                ];
            })->all(),
            'raw' => $snapshot,
        ];
    }

    /** @return array<string, mixed> */
    private function layer(array $snapshot, string $layer): array
    {
        $value = data_get($snapshot, $layer, []);

        return is_array($value) ? $value : ['value' => $value];
    }

    /** @return array<string, mixed> */
    private function tokenAllocation(array $snapshot): array
    {
        $allocation = data_get($snapshot, 'token_allocation', []);
        $sections = is_array(data_get($allocation, 'sections')) ? data_get($allocation, 'sections') : [];

        return [
            'budget' => (int) data_get($allocation, 'budget', data_get($snapshot, 'token_budget', 0)),
            'used' => (int) data_get($allocation, 'used', array_sum($sections)),
            'remaining' => (int) data_get($allocation, 'remaining', 0),
            'sections' => collect($sections)->map(
                fn (mixed $tokens, string $section): array => ['section' => strtoupper($section), 'tokens' => (int) $tokens],
            )->values()->all(),
        ];
    }
}
