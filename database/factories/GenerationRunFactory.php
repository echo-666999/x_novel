<?php

namespace Database\Factories;

use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GenerationRun> */
class GenerationRunFactory extends Factory
{
    protected $model = GenerationRun::class;

    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'chapter_id' => null,
            'scene_id' => null,
            'scope_type' => 'novel',
            'scope_id' => fn (array $attributes): int => $attributes['novel_id'],
            'stage' => GenerationStage::ChapterPlanning,
            'status' => RunStatus::Queued,
            'attempt' => 1,
            'idempotency_key' => fake()->uuid(),
            'input_hash' => hash('sha256', fake()->uuid()),
            'state_version' => 0,
            'bible_version' => 1,
            'prompt_version' => 'chapter-planner-v1',
            'model_policy' => 'global',
            'context_snapshot' => [],
            'error_code' => null,
            'error_message' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
