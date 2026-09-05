<?php

namespace Database\Factories;

use App\Enums\NovelStatus;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Novel>
 */
class NovelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'genre' => fake()->randomElement(['玄幻', '科幻', '悬疑', '都市']),
            'premise' => fake()->paragraph(),
            'target_words' => fake()->numberBetween(300_000, 2_000_000),
            'status' => NovelStatus::Draft,
            'current_chapter_sequence' => null,
            'settings' => [],
        ];
    }
}
