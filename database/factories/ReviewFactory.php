<?php

namespace Database\Factories;

use App\Enums\ArtifactType;
use App\Enums\ReviewDecision;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Review> */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return ['generation_run_id' => GenerationRun::factory(), 'artifact_id' => fn (array $attributes): int => GenerationArtifact::factory()->create(['generation_run_id' => $attributes['generation_run_id'], 'type' => ArtifactType::ReviewResult])->getKey(),
            'decision' => ReviewDecision::Pass, 'score' => 85, 'continuity_score' => 85, 'plan_score' => 85,
            'character_score' => 85, 'progress_score' => 85, 'repetition_score' => 85, 'pacing_score' => 85, 'style_score' => 85, 'findings' => []];
    }
}
