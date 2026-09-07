<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\UsageRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException as ArtifactLogicException;

uses(RefreshDatabase::class);

test('a generation run stores trace and timing data with typed relationships', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $scene = Scene::factory()->for($chapter)->create();

    $run = GenerationRun::factory()->for($novel)->create([
        'chapter_id' => $chapter->getKey(),
        'scene_id' => $scene->getKey(),
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
        'attempt' => 2,
        'context_snapshot' => ['state_version' => 4, 'memory_ids' => [1, 2]],
        'started_at' => '2026-09-07 10:00:00.000000',
        'finished_at' => '2026-09-07 10:00:02',
    ]);

    expect($run->fresh())
        ->stage->toBe(GenerationStage::SceneGeneration)
        ->status->toBe(RunStatus::Succeeded)
        ->attempt->toBe(2)
        ->context_snapshot->toBe(['state_version' => 4, 'memory_ids' => [1, 2]])
        ->and($run->novel->is($novel))->toBeTrue()
        ->and($run->chapter->is($chapter))->toBeTrue()
        ->and($run->scene->is($scene))->toBeTrue()
        ->and($run->durationMilliseconds())->toBe(2000);
});

test('generation run idempotency keys are unique', function () {
    $run = GenerationRun::factory()->create(['idempotency_key' => 'plan:chapter-1']);

    GenerationRun::factory()->create([
        'novel_id' => $run->novel_id,
        'idempotency_key' => 'plan:chapter-1',
    ]);
})->throws(QueryException::class);

test('artifact versions are unique within a run and type', function () {
    $run = GenerationRun::factory()->create();
    GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterPlan,
        'version' => 1,
    ]);

    GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterPlan,
        'version' => 1,
    ]);
})->throws(QueryException::class);

test('generation artifacts cannot be updated', function () {
    $artifact = GenerationArtifact::factory()->create();

    $artifact->update(['content' => 'Changed content']);
})->throws(ArtifactLogicException::class, 'Generation artifacts are immutable');

test('generation artifacts cannot be deleted directly', function () {
    $artifact = GenerationArtifact::factory()->create();

    $artifact->delete();
})->throws(ArtifactLogicException::class, 'Generation artifacts are immutable');

test('usage records belong to generation runs and survive run deletion', function () {
    $run = GenerationRun::factory()->create();
    $usage = UsageRecord::factory()->create(['generation_run_id' => $run->getKey()]);

    expect($usage->generationRun->is($run))->toBeTrue();

    $run->delete();

    expect($usage->fresh()->generation_run_id)->toBeNull();
});
