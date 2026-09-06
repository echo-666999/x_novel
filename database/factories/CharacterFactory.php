<?php

namespace Database\Factories;

use App\Enums\CharacterStatus;
use App\Models\Character;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Character> */
class CharacterFactory extends Factory
{
    protected $model = Character::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'name' => fake()->name(),
            'aliases' => [],
            'role' => '配角',
            'profile' => [],
            'motivation' => fake()->sentence(),
            'personality' => [],
            'abilities' => [],
            'knowledge' => [],
            'current_state' => [],
            'locked_fields' => [],
            'status' => CharacterStatus::Active,
        ];
    }
}
