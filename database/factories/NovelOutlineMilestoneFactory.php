<?php

namespace Database\Factories;

use App\Models\NovelOutlineBeat;
use App\Models\NovelOutlineMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 为关系化大纲 Milestone 测试提供同 Beat 父链。
 *
 * @extends Factory<NovelOutlineMilestone>
 */
class NovelOutlineMilestoneFactory extends Factory
{
    protected $model = NovelOutlineMilestone::class;

    /** @return array<string, mixed> 返回最小 Milestone 测试字段。 */
    public function definition(): array
    {
        return [
            'novel_outline_beat_id' => NovelOutlineBeat::factory(),
            'novel_outline_id' => fn (array $attributes): int => NovelOutlineBeat::query()->findOrFail($attributes['novel_outline_beat_id'])->novel_outline_id,
            'milestone_key' => fake()->unique()->slug(2),
            'sequence' => 1,
            'title' => fake()->sentence(3),
            'objective' => fake()->sentence(),
            'acceptance_criteria' => [fake()->sentence()],
            'must_include' => [],
            'must_not_include' => [],
        ];
    }
}
