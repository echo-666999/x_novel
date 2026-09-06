<?php

namespace Database\Factories;

use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoryArc> */
class StoryArcFactory extends Factory
{
    protected $model = StoryArc::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'volume_id' => null,
            'type' => StoryArcType::Main,
            'title' => fake()->sentence(4),
            'goal' => fake()->sentence(),
            'stakes' => fake()->sentence(),
            'beats' => [fake()->sentence(), fake()->sentence()],
            'completion_conditions' => [fake()->sentence()],
            'progress' => 0,
            'status' => StoryArcStatus::Planned,
        ];
    }

    public function forVolume(Volume $volume): static
    {
        return $this->state(fn (): array => [
            'novel_id' => $volume->novel_id,
            'volume_id' => $volume->getKey(),
        ]);
    }
}
