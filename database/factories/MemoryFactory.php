<?php

namespace Database\Factories;

use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Models\Memory;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Memory> */
class MemoryFactory extends Factory
{
    protected $model = Memory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'type' => MemoryType::Event,
            'source_type' => 'story_event',
            'source_id' => fake()->numberBetween(1, 1000),
            'summary' => fake()->sentence(),
            'entities' => [],
            'salience' => 0.700,
            'status' => MemoryStatus::Active,
            'embedding' => null,
            'embedding_model' => null,
            'valid_from_chapter' => 1,
            'valid_to_chapter' => null,
        ];
    }
}
