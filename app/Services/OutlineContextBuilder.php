<?php

namespace App\Services;

use App\Models\Novel;
use Illuminate\Validation\ValidationException;

/**
 * 把 Current Outline Target 转换为 Planner 冻结上下文。
 */
class OutlineContextBuilder
{
    public function __construct(private readonly OutlineProgressResolver $progressResolver) {}

    /** @return array<string, mixed> 构建含 Arc、Beat、Milestone 与 Handoff 的权威上下文。 */
    public function build(Novel $novel): array
    {
        $target = $this->progressResolver->resolve($novel);
        if ($target === null) {
            throw ValidationException::withMessages([
                'outline' => '当前 Novel 没有可规划的 Current Outline Target。',
            ]);
        }

        return [
            ...$target->toArray(),
            'primary_beat_key' => $target->beat['key'],
            'primary_beat_sequence' => $target->beat['sequence'],
            'primary_milestone_key' => $target->milestone['key'],
            'primary_milestone_sequence' => $target->milestone['sequence'],
            'chapter_budget' => $target->beat['chapter_budget'],
            'acceptance_criteria' => $target->milestone['acceptance_criteria'],
            'must_include' => array_values(array_unique([...$target->beat['must_include'], ...$target->milestone['must_include']])),
            'must_not_include' => array_values(array_unique([...$target->beat['must_not_include'], ...$target->milestone['must_not_include']])),
            'character_candidates' => $target->beat['character_candidates'],
            'world_entity_candidates' => $target->beat['world_entity_candidates'],
        ];
    }
}
