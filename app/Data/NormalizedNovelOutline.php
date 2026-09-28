<?php

namespace App\Data;

use App\Models\NovelOutline;

/**
 * 表示按稳定顺序规范化后的完整大纲业务 DTO。
 */
readonly class NormalizedNovelOutline
{
    /**
     * 从 Provider 或表单数组建立 DTO，并统一所有子节点顺序。
     *
     * @param  array<string, mixed>  $data
     */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        // Provider 和表单只传稳定 Key；这里统一顺序和 Enum 值，不补造缺失业务字段。
        $data['volumes'] = self::sorted($data['volumes'] ?? []);
        foreach ($data['volumes'] as &$volume) {
            if (! is_array($volume)) {
                continue;
            }
            $volume['arcs'] = self::sorted($volume['arcs'] ?? []);
            foreach ($volume['arcs'] as &$arc) {
                if (! is_array($arc)) {
                    continue;
                }
                if (($arc['type'] ?? null) instanceof \BackedEnum) {
                    $arc['type'] = $arc['type']->value;
                }
                $arc['beats'] = self::sorted($arc['beats'] ?? []);
                foreach ($arc['beats'] as &$beat) {
                    if (! is_array($beat)) {
                        continue;
                    }
                    $beat['milestones'] = self::sorted($beat['milestones'] ?? []);
                }
                unset($beat);
            }
            unset($arc);
        }
        unset($volume);

        return new self($data);
    }

    /**
     * 从关系表还原不含数据库 ID 和时间戳的业务 DTO。
     */
    public static function fromModel(NovelOutline $outline): self
    {
        $outline->loadMissing([
            'volumes.arcs.beats.milestones',
            'volumes.arcs.beats.handoffNextBeat',
        ]);

        return self::fromArray([
            'title' => $outline->title,
            'summary' => $outline->summary,
            'must_include' => $outline->must_include ?? [],
            'must_not_include' => $outline->must_not_include ?? [],
            'volumes' => $outline->volumes->map(fn ($volume): array => [
                'key' => $volume->volume_key,
                'sequence' => $volume->sequence,
                'title' => $volume->title,
                'goal' => $volume->goal,
                'climax' => $volume->climax,
                'target_words' => $volume->target_words,
                'arcs' => $volume->arcs->map(fn ($arc): array => [
                    'key' => $arc->arc_key,
                    'sequence' => $arc->sequence,
                    'mainline_sequence' => $arc->mainline_sequence,
                    'type' => $arc->type->value,
                    'title' => $arc->title,
                    'goal' => $arc->goal,
                    'stakes' => $arc->stakes,
                    'completion_conditions' => $arc->completion_conditions ?? [],
                    'beats' => $arc->beats->map(fn ($beat): array => [
                        'key' => $beat->beat_key,
                        'sequence' => $beat->sequence,
                        'mainline_sequence' => $beat->mainline_sequence,
                        'title' => $beat->title,
                        'summary' => $beat->summary,
                        'chapter_budget' => [
                            'min' => $beat->chapter_budget_min,
                            'max' => $beat->chapter_budget_max,
                        ],
                        'acceptance_criteria' => $beat->acceptance_criteria ?? [],
                        'must_include' => $beat->must_include ?? [],
                        'must_not_include' => $beat->must_not_include ?? [],
                        'character_candidates' => $beat->character_candidates ?? [],
                        'world_entity_candidates' => $beat->world_entity_candidates ?? [],
                        'milestones' => $beat->milestones->map(fn ($milestone): array => [
                            'key' => $milestone->milestone_key,
                            'sequence' => $milestone->sequence,
                            'title' => $milestone->title,
                            'objective' => $milestone->objective,
                            'acceptance_criteria' => $milestone->acceptance_criteria ?? [],
                            'must_include' => $milestone->must_include ?? [],
                            'must_not_include' => $milestone->must_not_include ?? [],
                        ])->all(),
                        'handoff' => [
                            'next_beat_key' => $beat->handoffNextBeat?->beat_key,
                            'transition_mode' => $beat->handoff_transition_mode,
                            'exit_result' => $beat->handoff_exit_result,
                            'next_trigger' => $beat->handoff_next_trigger,
                            'carried_states' => $beat->handoff_carried_states ?? [],
                            'open_threads' => $beat->handoff_open_threads ?? [],
                            'required_transition' => $beat->handoff_required_transition ?? [],
                            'forbidden_jump' => $beat->handoff_forbidden_jump ?? [],
                        ],
                    ])->all(),
                ])->all(),
            ])->all(),
        ]);
    }

    /**
     * 返回可用于校验、Checksum 和版本复制的稳定数组。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * 按 sequence 稳定排序同级节点，避免数据库加载顺序影响 Checksum。
     *
     * @return array<int, mixed>
     */
    private static function sorted(mixed $nodes): array
    {
        if (! is_array($nodes)) {
            return [];
        }

        $nodes = array_values($nodes);
        usort($nodes, fn (mixed $left, mixed $right): int => (int) ($left['sequence'] ?? 0) <=> (int) ($right['sequence'] ?? 0));

        return $nodes;
    }
}
