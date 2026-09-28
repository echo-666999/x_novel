<?php

namespace Database\Factories;

use App\Models\NovelOutlineArc;
use App\Models\NovelOutlineBeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 为关系化大纲 Beat 测试提供预算与 Handoff 默认值。
 *
 * @extends Factory<NovelOutlineBeat>
 */
class NovelOutlineBeatFactory extends Factory
{
    protected $model = NovelOutlineBeat::class;

    /** @return array<string, mixed> 返回最小 Main Beat 测试字段。 */
    public function definition(): array
    {
        return [
            'novel_outline_arc_id' => NovelOutlineArc::factory(),
            'novel_outline_id' => fn (array $attributes): int => NovelOutlineArc::query()->findOrFail($attributes['novel_outline_arc_id'])->novel_outline_id,
            'beat_key' => fake()->unique()->slug(2),
            'sequence' => 1,
            'mainline_sequence' => 1,
            'title' => fake()->sentence(3),
            'summary' => fake()->sentence(),
            'chapter_budget_min' => 1,
            'chapter_budget_max' => 4,
            'acceptance_criteria' => [fake()->sentence()],
            'must_include' => [],
            'must_not_include' => [],
            'character_candidates' => [],
            'world_entity_candidates' => [],
            'handoff_next_beat_id' => null,
            'handoff_transition_mode' => null,
            'handoff_exit_result' => null,
            'handoff_next_trigger' => null,
            'handoff_carried_states' => [],
            'handoff_open_threads' => [],
            'handoff_required_transition' => [],
            'handoff_forbidden_jump' => [],
        ];
    }
}
