<?php

namespace Database\Factories;

use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Models\Fact;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Fact> */
class FactFactory extends Factory
{
    protected $model = Fact::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'subject_type' => 'character',
            'subject_id' => fake()->numberBetween(1, 1000),
            'predicate' => fake()->randomElement(['is_alive', 'can_swim', 'knows_secret']),
            'value' => ['value' => fake()->boolean()],
            'hardness' => FactHardness::Hard,
            'confidence' => 1,
            'status' => FactStatus::Active,
            'locked' => false,
            'source_type' => FactSourceType::Manual,
            'source_event_id' => null,
        ];
    }
}
