<?php

namespace Database\Factories;

use App\Enums\WorldEntityStatus;
use App\Enums\WorldEntityType;
use App\Models\Novel;
use App\Models\WorldEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorldEntity> */
class WorldEntityFactory extends Factory
{
    protected $model = WorldEntity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'type' => WorldEntityType::Location,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'attributes' => [],
            'rules' => [],
            'current_state' => [],
            'locked_fields' => [],
            'status' => WorldEntityStatus::Active,
        ];
    }
}
