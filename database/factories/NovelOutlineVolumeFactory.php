<?php

namespace Database\Factories;

use App\Models\NovelOutline;
use App\Models\NovelOutlineVolume;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 为关系化大纲 Volume 测试提供最小有效定义。
 *
 * @extends Factory<NovelOutlineVolume>
 */
class NovelOutlineVolumeFactory extends Factory
{
    protected $model = NovelOutlineVolume::class;

    /** @return array<string, mixed> 返回最小卷级测试字段。 */
    public function definition(): array
    {
        return [
            'novel_outline_id' => NovelOutline::factory(),
            'volume_key' => fake()->unique()->slug(2),
            'sequence' => 1,
            'title' => fake()->sentence(3),
            'goal' => fake()->sentence(),
            'climax' => fake()->sentence(),
            'target_words' => 200_000,
        ];
    }
}
