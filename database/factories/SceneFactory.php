<?php

namespace Database\Factories;

use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\Scene;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Scene> */
class SceneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'sequence' => fake()->unique()->numberBetween(1, 1_000_000),
            'pov_character_id' => null,
            'location' => fake()->city(),
            'time_anchor' => '当日傍晚',
            'goal' => fake()->sentence(),
            'conflict' => fake()->sentence(),
            'turn' => fake()->sentence(),
            'outcome' => fake()->sentence(),
            'status' => SceneStatus::Planned,
            'current_artifact_id' => null,
        ];
    }
}
