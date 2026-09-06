<?php

namespace Database\Factories;

use App\Enums\VolumeStatus;
use App\Models\Novel;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Volume> */
class VolumeFactory extends Factory
{
    protected $model = Volume::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'sequence' => 1,
            'title' => fake()->words(3, true),
            'goal' => fake()->sentence(),
            'climax' => fake()->sentence(),
            'target_words' => 200_000,
            'status' => VolumeStatus::Planned,
            'summary' => null,
        ];
    }
}
