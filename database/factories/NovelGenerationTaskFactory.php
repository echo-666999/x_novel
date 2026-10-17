<?php

namespace Database\Factories;

use App\Enums\ArtifactType;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 测试任务的意图与Run始终同小说，不使用已有业务数据。 */
class NovelGenerationTaskFactory extends Factory
{
    public function definition(): array
    {
        return ['novel_id' => Novel::factory(), 'submission_key' => fake()->uuid(), 'target_words' => 100000,
            'status' => 'queued', 'mode' => 'full_book', 'policy_snapshot' => ['schema_version' => 1],
            'intent_artifact_id' => function (array $attributes): int {
                $run = GenerationRun::factory()->create(['novel_id' => $attributes['novel_id']]);

                return GenerationArtifact::factory()->create(['generation_run_id' => $run->id, 'type' => ArtifactType::Intent])->id;
            }];
    }
}
