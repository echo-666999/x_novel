<?php

namespace Database\Factories;

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Models\Foreshadowing;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Foreshadowing> */
class ForeshadowingFactory extends Factory
{
    protected $model = Foreshadowing::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'setup_chapter_id' => null,
            'promised_payoff' => fake()->sentence(),
            'due_from_chapter' => 10,
            'due_to_chapter' => 20,
            'importance' => ForeshadowingImportance::Medium,
            'status' => ForeshadowingStatus::Idea,
            'owner_arc_id' => null,
            'reinforce_count' => 0,
            'payoff_chapter_id' => null,
            'notes' => null,
        ];
    }
}
