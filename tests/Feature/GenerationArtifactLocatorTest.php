<?php

use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Services\GenerationArtifactLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('artifact locator requires matching input hash source chain and validated status', function () {
    $chapter = Chapter::factory()->create();
    $draftRun = GenerationRun::factory()->for($chapter->novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
        'input_hash' => str_repeat('a', 64),
    ]);
    $draft = GenerationArtifact::factory()->for($draftRun)->create(['type' => ArtifactType::ChapterDraft]);
    $candidateRun = GenerationRun::factory()->for($chapter->novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'input_hash' => str_repeat('b', 64),
    ]);
    $valid = GenerationArtifact::factory()->for($candidateRun)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => ['source_artifact_id' => $draft->getKey()],
    ]);
    GenerationArtifact::factory()->for($candidateRun)->create([
        'type' => ArtifactType::EventCandidate,
        'version' => 2,
        'data' => ['source_artifact_id' => $draft->getKey()],
        'validation_status' => ArtifactValidationStatus::Rejected,
    ]);

    $found = app(GenerationArtifactLocator::class)->latestForChapter(
        $chapter->getKey(), ArtifactType::EventCandidate, $draft->getKey(), null, str_repeat('b', 64),
    );

    expect($found?->is($valid))->toBeTrue()
        ->and($found?->input_hash)->toBe($candidateRun->input_hash)
        ->and($found?->source_artifact_id)->toBe($draft->getKey())
        ->and(app(GenerationArtifactLocator::class)->latestForChapter(
            $chapter->getKey(), ArtifactType::EventCandidate, $draft->getKey(), null, str_repeat('c', 64),
        ))->toBeNull();
});
