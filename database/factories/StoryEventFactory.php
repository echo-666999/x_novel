<?php

namespace Database\Factories;

use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\StoryEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoryEvent> */
class StoryEventFactory extends Factory
{
    protected $model = StoryEvent::class;

    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'chapter_id' => fn (array $attributes): int => Chapter::factory()->create(['novel_id' => $attributes['novel_id']])->getKey(),
            'scene_id' => null,
            'event_type' => EventType::CharacterMoved,
            'subject_type' => 'character',
            'subject_id' => (string) fake()->numberBetween(1, 100),
            'payload' => ['to' => fake()->city()],
            'evidence' => [['quote' => fake()->sentence(), 'artifact_id' => 1, 'scene_id' => null]],
            'story_time' => null,
            'state_version' => 1,
            'status' => StoryEventStatus::Active,
            'invalidated_at' => null,
        ];
    }
}
