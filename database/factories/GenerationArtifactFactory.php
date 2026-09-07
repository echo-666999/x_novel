<?php

namespace Database\Factories;

use App\Enums\ArtifactType;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GenerationArtifact> */
class GenerationArtifactFactory extends Factory
{
    protected $model = GenerationArtifact::class;

    public function definition(): array
    {
        $content = fake()->paragraph();

        return [
            'generation_run_id' => GenerationRun::factory(),
            'type' => ArtifactType::ChapterPlan,
            'version' => 1,
            'content' => $content,
            'data' => [],
            'checksum' => hash('sha256', $content),
        ];
    }
}
