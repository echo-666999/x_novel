<?php

use App\Actions\Chapters\RequestAiChapterRepairAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\ReviewChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Queue::fake();
});

test('one click queues AI regeneration from the failed scene and preserves old artifacts', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Blocked]);
    ChapterPlan::factory()->for($chapter)->create(['status' => PlanStatus::Ready]);
    $first = Scene::factory()->for($chapter)->create(['sequence' => 1, 'status' => SceneStatus::Failed]);
    $second = Scene::factory()->for($chapter)->create(['sequence' => 2, 'status' => SceneStatus::Draft]);
    $prior = GenerationRun::factory()->for($novel)->for($chapter)->for($second)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($prior)->create(['type' => ArtifactType::SceneDraft]);
    $second->update(['current_artifact_id' => $artifact->getKey()]);
    GenerationRun::factory()->for($novel)->for($chapter)->for($first)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Failed,
        'error_code' => 'content_repair_time_limit_exceeded',
        'error_message' => '内容修复已超过 1800 秒上限。',
    ]);

    $action = app(RequestAiChapterRepairAction::class);
    expect($action->preview($chapter)['scene_id'])->toBe($first->getKey());

    $result = $action->handle($chapter);

    expect($result)->toBe(['mode' => 'scene_regeneration', 'scene_id' => $first->getKey(), 'affected_scenes' => 2])
        ->and($first->fresh()->status)->toBe(SceneStatus::Planned)
        ->and($second->fresh()->current_artifact_id)->toBeNull()
        ->and($artifact->fresh())->not->toBeNull();
    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $first->getKey()
        && $job->cascade && $job->regenerationBatchId !== null);
    Queue::assertPushed(GenerateSceneJob::class, 1);

    expect(fn () => $action->handle($chapter))->toThrow(ValidationException::class);
    Queue::assertPushed(GenerateSceneJob::class, 1);
});

test('hard fact review cannot be handed to AI as an automatic repair', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Blocked]);
    ChapterPlan::factory()->for($chapter)->create(['status' => PlanStatus::Ready]);
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 1]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::ReviewResult]);
    Review::factory()->for($run)->create([
        'artifact_id' => $artifact->getKey(),
        'decision' => ReviewDecision::Block,
        'findings' => [[
            'code' => 'LOCKED_FACT_CONFLICT',
            'dimension' => 'state',
            'scope' => 'scene',
            'scene_id' => $scene->getKey(),
            'severity' => 'hard',
            'auto_fixable' => false,
            'requires_human_decision' => true,
            'message' => '与锁定事实冲突。',
        ]],
    ]);

    $action = app(RequestAiChapterRepairAction::class);

    expect($action->preview($chapter))->toBeNull()
        ->and(fn () => $action->handle($chapter))->toThrow(ValidationException::class);
    Queue::assertNothingPushed();
});

test('a scene failure classified as a locked fact conflict cannot use the AI repair button', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Blocked]);
    ChapterPlan::factory()->for($chapter)->create(['status' => PlanStatus::Ready]);
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 1, 'status' => SceneStatus::Failed]);
    GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Failed,
        'error_code' => 'locked_fact_conflict',
    ]);

    expect(app(RequestAiChapterRepairAction::class)->preview($chapter))->toBeNull();
    Queue::assertNothingPushed();
});

test('event evidence failure queues a fresh extractor request through the frozen contract', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Blocked]);
    freezeChapterRouteContractsForTest(ChapterPlan::factory()->for($chapter)->create(['status' => PlanStatus::Ready]));
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 1, 'status' => SceneStatus::Draft]);
    $sceneRun = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $sceneArtifact = GenerationArtifact::factory()->for($sceneRun)->create(['type' => ArtifactType::SceneDraft]);
    $scene->update(['current_artifact_id' => $sceneArtifact->getKey()]);
    $assemblyRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    GenerationArtifact::factory()->for($assemblyRun)->create(['type' => ArtifactType::ChapterDraft]);
    GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Failed,
        'error_code' => 'event_validation_failed',
        'error_message' => '已满足或冲突的条件必须提供当前正文逐字证据。',
    ]);

    $action = app(RequestAiChapterRepairAction::class);
    expect($action->preview($chapter)['mode'])->toBe('event_extraction')
        ->and($action->handle($chapter)['mode'])->toBe('event_extraction');

    Queue::assertPushed(ExtractStoryEventsJob::class, fn (ExtractStoryEventsJob $job): bool => $job->chapterId === $chapter->getKey()
        && $job->regenerate);
});

test('a locatable needs-attention scene finding can explicitly request AI rebuilding', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    ChapterPlan::factory()->for($chapter)->create(['status' => PlanStatus::Ready]);
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 1, 'status' => SceneStatus::Draft]);
    $reviewRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create(['type' => ArtifactType::ReviewResult]);
    Review::factory()->for($reviewRun)->create([
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::NeedsAttention,
        'findings' => [[
            'code' => 'SCENE_OUTCOME_CONFLICT',
            'dimension' => 'continuity',
            'scope' => 'scene',
            'scene_id' => $scene->getKey(),
            'severity' => 'error',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'message' => '场景结局需要修正。',
        ]],
    ]);

    $action = app(RequestAiChapterRepairAction::class);
    expect($action->preview($chapter)['scene_id'])->toBe($scene->getKey());

    $action->handle($chapter);

    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $scene->getKey());
});

test('event retry cannot queue AI when the extractor admission contract is missing', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Blocked]);
    ChapterPlan::factory()->for($chapter)->create(['status' => PlanStatus::Ready]);
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 1]);
    $sceneRun = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $sceneArtifact = GenerationArtifact::factory()->for($sceneRun)->create(['type' => ArtifactType::SceneDraft]);
    $scene->update(['current_artifact_id' => $sceneArtifact->getKey()]);
    $assemblyRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    GenerationArtifact::factory()->for($assemblyRun)->create(['type' => ArtifactType::ChapterDraft]);
    GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Failed,
        'error_code' => 'event_validation_failed',
    ]);

    $action = app(RequestAiChapterRepairAction::class);
    expect($action->preview($chapter)['mode'])->toBe('event_extraction')
        ->and(fn () => $action->handle($chapter))->toThrow(AiProviderException::class, '[ADMISSION_CONTRACT_MISSING]');
    Queue::assertNothingPushed();
});

test('saved review evidence can be handed to AI without repeating the full review', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Blocked]);
    $plan = freezeChapterRouteContractsForTest(ChapterPlan::factory()->for($chapter)->create(['status' => PlanStatus::Ready]));
    $snapshot = $plan->admission_snapshot;
    $snapshot['bible_version'] = 1;
    $plan->update(['admission_snapshot' => $snapshot]);
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 1, 'status' => SceneStatus::Draft]);
    $sceneRun = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'stage' => GenerationStage::SceneGeneration, 'status' => RunStatus::Succeeded,
    ]);
    $sceneArtifact = GenerationArtifact::factory()->for($sceneRun)->create(['type' => ArtifactType::SceneDraft]);
    $scene->update(['current_artifact_id' => $sceneArtifact->getKey()]);
    $assemblyRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded,
    ]);
    $draft = GenerationArtifact::factory()->for($assemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'data' => ['ordered_scene_checksums' => [$sceneArtifact->checksum]],
    ]);
    $extractRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction, 'status' => RunStatus::Succeeded,
        'state_version' => $novel->fresh()->canonicalStateVersion->version,
    ]);
    $candidate = GenerationArtifact::factory()->for($extractRun)->create([
        'type' => ArtifactType::EventCandidate, 'data' => ['source_artifact_id' => $draft->getKey()],
    ]);
    GenerationArtifact::factory()->for($extractRun)->create([
        'type' => ArtifactType::StatePatch,
        'version' => 1,
        'data' => [
            'source_artifact_id' => $candidate->getKey(),
            'expected_state_version' => $novel->fresh()->canonicalStateVersion->version,
        ],
    ]);
    $route = data_get($plan->admission_snapshot, 'routes.reviewer');
    $reviewRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Failed,
        'error_code' => 'review_evidence_output_invalid',
        'state_version' => $novel->fresh()->canonicalStateVersion->version,
        'provider' => $route['provider'],
        'model_policy' => $route['model'],
        'prompt_version' => $route['prompt_version'],
        'context_snapshot' => [
            'chapter_plan' => ['id' => $plan->getKey()],
            'draft' => ['artifact_id' => $draft->getKey(), 'checksum' => $draft->checksum],
            'operation_id' => 'review-request-1',
        ],
    ]);
    GenerationArtifact::factory()->for($reviewRun)->create([
        'type' => ArtifactType::Context,
        'content' => null,
        'data' => ['role' => 'review_evidence_payload', 'source_artifact_id' => $draft->getKey(), 'payload' => ['scores' => []]],
    ]);

    $action = app(RequestAiChapterRepairAction::class);
    expect($action->preview($chapter)['mode'])->toBe('review_evidence')
        ->and($action->handle($chapter)['mode'])->toBe('review_evidence');
    Queue::assertPushed(ReviewChapterJob::class, fn (ReviewChapterJob $job): bool => $job->chapterId === $chapter->getKey()
        && $job->regenerate && $job->operationId === 'review-request-1');
    expect($action->preview($chapter))->toBeNull();

    $scene->update(['current_artifact_id' => null]);
    Cache::flush();
    expect($action->preview($chapter))->toBeNull();

    $scene->update(['current_artifact_id' => $sceneArtifact->getKey()]);
    $changedSnapshot = $plan->admission_snapshot;
    $changedSnapshot['routes']['reviewer']['model'] = 'different-reviewer-model';
    $plan->update(['admission_snapshot' => $changedSnapshot]);
    expect($action->preview($chapter))->toBeNull();
});
