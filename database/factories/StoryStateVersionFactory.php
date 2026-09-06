<?php

namespace Database\Factories;

use App\Models\Novel;
use App\Models\StoryStateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoryStateVersion> */
class StoryStateVersionFactory extends Factory
{
    protected $model = StoryStateVersion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $state = [
            'schema_version' => 1,
            'characters' => [],
            'relationships' => [],
            'locations' => [],
            'items' => [],
            'world' => [],
            'timeline' => [],
            'open_threads' => [],
            'foreshadowings' => [],
            'reader_promises' => [],
        ];

        return [
            'novel_id' => Novel::factory(),
            'version' => 0,
            'chapter_id' => null,
            'state' => $state,
            'checksum' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }
}
