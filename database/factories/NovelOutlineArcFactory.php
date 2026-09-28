<?php

namespace Database\Factories;

use App\Enums\StoryArcType;
use App\Models\NovelOutlineArc;
use App\Models\NovelOutlineVolume;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 为关系化大纲 Arc 测试提供同版本父链。
 *
 * @extends Factory<NovelOutlineArc>
 */
class NovelOutlineArcFactory extends Factory
{
    protected $model = NovelOutlineArc::class;

    /** @return array<string, mixed> 返回最小主线 Arc 测试字段。 */
    public function definition(): array
    {
        return [
            'novel_outline_volume_id' => NovelOutlineVolume::factory(),
            'novel_outline_id' => fn (array $attributes): int => NovelOutlineVolume::query()->findOrFail($attributes['novel_outline_volume_id'])->novel_outline_id,
            'arc_key' => fake()->unique()->slug(2),
            'sequence' => 1,
            'mainline_sequence' => 1,
            'type' => StoryArcType::Main,
            'title' => fake()->sentence(3),
            'goal' => fake()->sentence(),
            'stakes' => fake()->sentence(),
            'completion_conditions' => [fake()->sentence()],
        ];
    }
}
