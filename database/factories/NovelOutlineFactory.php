<?php

namespace Database\Factories;

use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Models\Novel;
use App\Models\NovelOutline;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NovelOutline> */
class NovelOutlineFactory extends Factory
{
    protected $model = NovelOutline::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $content = [
            'title' => fake()->sentence(4),
            'summary' => fake()->sentence(),
            'must_include' => [],
            'must_not_include' => [],
            'volumes' => [],
        ];

        return [
            'novel_id' => Novel::factory(),
            'version' => 1,
            'status' => NovelOutlineStatus::Draft,
            'source' => NovelOutlineSource::Manual,
            'schema_version' => 1,
            'content' => $content,
            'checksum' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)),
            'based_on_outline_id' => null,
            'created_by' => null,
            'applied_at' => null,
        ];
    }
}
