<?php

use App\Actions\Chapters\InvalidateChapterStageDownstreamAction;
use App\Actions\Generation\GenerateNextChapterAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Exceptions\GenerationPreflightException;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Models\StoryArc;
use App\Models\Volume;
use App\Services\GenerationFailurePolicy;
use App\Services\GenerationStageFingerprint;
use App\Services\GenerationStageGraph;
use App\Services\NovelGenerationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('stage fingerprints ignore map order and runtime delivery fields', function () {
    $fingerprint = app(GenerationStageFingerprint::class);
    $first = $fingerprint->make(GenerationStage::SceneGeneration, [
        'scene' => ['goal' => '抵达灯塔', 'sequence' => 2],
        'attempt' => 1,
        'updated_at' => now()->subDay(),
        'queue_id' => 'queue-a',
    ], ['scene-a'], ['model' => 'model-a'], 'scene-v1');
    $same = $fingerprint->make(GenerationStage::SceneGeneration, [
        'queue_id' => 'queue-b',
        'updated_at' => now(),
        'attempt' => 99,
        'scene' => ['sequence' => 2, 'goal' => '抵达灯塔'],
    ], ['scene-a'], ['model' => 'model-a'], 'scene-v1');

    expect($same)->toBe($first)
        ->and($fingerprint->make(GenerationStage::SceneGeneration, ['scene' => ['goal' => '离开灯塔', 'sequence' => 2]], ['scene-a'], ['model' => 'model-a'], 'scene-v1'))->not->toBe($first)
        ->and($fingerprint->make(GenerationStage::SceneGeneration, ['scene' => ['goal' => '抵达灯塔', 'sequence' => 2]], ['scene-b'], ['model' => 'model-a'], 'scene-v1'))->not->toBe($first);
});

test('the declarative graph exposes only legal chapter downstream stages', function () {
    $graph = app(GenerationStageGraph::class);

    expect($graph->next(GenerationStage::ChapterAssembly))->toBe([GenerationStage::CoverageJudgment, GenerationStage::EventExtraction])
        ->and($graph->next(GenerationStage::ChapterRecovery))->toContain(GenerationStage::ChapterAssembly, GenerationStage::CoverageJudgment, GenerationStage::EventExtraction, GenerationStage::Review, GenerationStage::Rewrite)
        ->and($graph->next(GenerationStage::CoverageJudgment))->toBe([GenerationStage::CoverageJudgment, GenerationStage::EventExtraction])
        ->and($graph->downstream(GenerationStage::SceneGeneration))->toContain(GenerationStage::ChapterAssembly, GenerationStage::CoverageJudgment, GenerationStage::Review, GenerationStage::Commit)
        ->and($graph->artifactsProducedBy(GenerationStage::CoverageJudgment))->toBe([ArtifactType::Context])
        ->and($graph->artifactsProducedBy(GenerationStage::ChapterRecovery))->toBe([ArtifactType::Context])
        ->and($graph->artifactsProducedBy(GenerationStage::Review))->toBe([ArtifactType::ReviewResult]);

    $graph->assertTransition(GenerationStage::Review, GenerationStage::Commit);
    expect(fn () => $graph->assertTransition(GenerationStage::ChapterPlanning, GenerationStage::Commit))
        ->toThrow(InvalidArgumentException::class);
});

test('downstream invalidation clears current pointers only for the affected chapter', function () {
    $novel = Novel::factory()->create();
    $affected = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    $other = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    $run = GenerationRun::factory()->for($novel)->create(['chapter_id' => $affected->getKey(), 'status' => RunStatus::Succeeded]);
    $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::SceneDraft]);
    $otherRun = GenerationRun::factory()->for($novel)->create(['chapter_id' => $other->getKey(), 'status' => RunStatus::Succeeded]);
    $otherArtifact = GenerationArtifact::factory()->for($otherRun)->create(['type' => ArtifactType::SceneDraft]);
    $scene = Scene::factory()->for($affected)->create(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);
    $otherScene = Scene::factory()->for($other)->create(['status' => SceneStatus::Draft, 'current_artifact_id' => $otherArtifact->getKey()]);

    app(InvalidateChapterStageDownstreamAction::class)->execute($affected, GenerationStage::ChapterPlanning);

    expect($scene->fresh()->current_artifact_id)->toBeNull()
        ->and($affected->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($otherScene->fresh()->current_artifact_id)->toBe($otherArtifact->getKey())
        ->and(GenerationArtifact::query()->count())->toBe(2);
});

test('failure policies retry temporary provider errors but not schema evidence state or code defects', function () {
    $policy = app(GenerationFailurePolicy::class);

    expect($policy->maxAttempts(GenerationStage::EventExtraction))->toBe(3)
        ->and($policy->allowsRepair(GenerationStage::EventExtraction, 'evidence'))->toBeTrue()
        ->and($policy->shouldQueueRetry(new AiProviderException('provider_timeout', 'timeout', true), GenerationStage::Review))->toBeTrue()
        ->and($policy->shouldQueueRetry(new AiProviderException('event_schema_invalid', 'schema', false), GenerationStage::EventExtraction))->toBeFalse()
        ->and($policy->shouldQueueRetry(new ErrorException('Undefined array key'), GenerationStage::SceneGeneration))->toBeFalse();
});

test('readiness and next chapter action expose the same blocker without side effects', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Paused]);
    $result = app(NovelGenerationReadiness::class)->nextChapter($novel);

    try {
        app(GenerateNextChapterAction::class)->handle($novel);
        test()->fail('Expected readiness blocker.');
    } catch (GenerationPreflightException $exception) {
        expect($exception->reason)->toBe($result->firstCode())
            ->and($novel->chapters()->count())->toBe(0)
            ->and($novel->generationRuns()->count())->toBe(0);
    }
});

test('a failed embedding does not block an otherwise ready first chapter', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    StoryArc::factory()->forVolume($volume)->create(['status' => StoryArcStatus::Active]);
    GenerationRun::factory()->for($novel)->create([
        'stage' => GenerationStage::Embedding,
        'status' => RunStatus::Failed,
        'error_code' => 'embedding_failed',
    ]);

    expect(app(NovelGenerationReadiness::class)->nextChapter($novel->fresh())->isReady())->toBeTrue();
});
