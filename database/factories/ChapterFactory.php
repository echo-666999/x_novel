<?php

namespace Database\Factories;

use App\Enums\ChapterStatus;
use App\Models\Chapter;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Chapter> */
class ChapterFactory extends Factory
{
    protected $model = Chapter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'volume_id' => null,
            'sequence' => fake()->unique()->numberBetween(1, 100_000),
            'title' => fake()->sentence(4),
            'status' => ChapterStatus::Planned,
            'canonical_artifact_id' => null,
            'word_count' => 0,
            'summary' => null,
        ];
    }
}
