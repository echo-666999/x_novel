<?php

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\AssembleChapterJob;
use App\Jobs\CommitChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\Scene;
use App\Services\ChapterAssembler;
use App\Services\ChapterPlanner;
use App\Services\ChapterReviewer;
use App\Services\ChapterRewriter;
use App\Services\SceneGenerator;
use App\Services\StoryEventExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    config()->set('ai.budget.daily_hard_limit', null);
    config()->set('ai.budget.novel_total_limit', null);
    config()->set('ai.budget.chapter_max_cost', null);
});

function pipelineChapter(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Planned,
    ]);

    return compact('novel', 'state', 'chapter');
}

function pipelineArtifact(
    Novel $novel,
    Chapter $chapter,
    ArtifactType $type,
    GenerationStage $stage,
    array $data = [],
    ?Scene $scene = null,
    ?string $content = null,
): GenerationArtifact {
    $novel->refresh();
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => $scene?->getKey(),
        'scope_type' => $scene === null ? 'chapter' : 'scene',
        'scope_id' => $scene?->getKey() ?? $chapter->getKey(),
        'stage' => $stage,
        'status' => RunStatus::Succeeded,
        'state_version' => $novel->canonicalStateVersion()->value('version'),
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $content ??= $type->value.'-'.$run->getKey();

    return GenerationArtifact::factory()->for($run)->create([
        'type' => $type,
        'content' => $content,
        'data' => $data,
        'checksum' => hash('sha256', $content),
    ]);
}

function pipelineReview(Novel $novel, Chapter $chapter, GenerationArtifact $draft, ReviewDecision $decision, array $findings = []): Review
{
    $novel->refresh();
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
        'state_version' => $novel->canonicalStateVersion()->value('version'),
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ReviewResult,
        'data' => ['decision' => $decision->value, 'source_artifact_id' => $draft->getKey()],
    ]);

    return Review::factory()->create([
        'generation_run_id' => $run->getKey(),
        'artifact_id' => $artifact->getKey(),
        'decision' => $decision,
        'findings' => $findings,
    ]);
}

test('one pipeline start advances each persisted stage in order and stops at pass', function () {
    $fixture = pipelineChapter();
    $chapter = $fixture['chapter'];
    $novel = $fixture['novel'];
    $advance = app(AdvanceChapterPipelineAction::class);

    expect($advance->handle($chapter->getKey()))->toBe(GenerationStage::ChapterPlanning);
    Queue::assertPushed(PlanChapterJob::class, 1);

    ChapterPlan::factory()->for($chapter)->create([
        'status' => PlanStatus::Ready,
        'scene_plans' => [
            ['goal' => '进入城门', 'conflict' => '守卫阻拦', 'turn' => '令牌生效', 'outcome' => '成功入城', 'outcome_allowed' => [], 'outcome_forbidden' => [], 'transition_from_previous' => null],
            ['goal' => '寻找线人', 'conflict' => '追兵抵达', 'turn' => '线人现身', 'outcome' => '获得线索', 'outcome_allowed' => [], 'outcome_forbidden' => [], 'transition_from_previous' => null],
        ],
    ]);
    $chapter->update(['status' => ChapterStatus::Generating]);

    expect($advance->handle($chapter->getKey()))->toBe(GenerationStage::SceneGeneration);
    $scenes = $chapter->scenes()->orderBy('sequence')->get();
    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $scenes[0]->getKey());

    $first = pipelineArtifact($novel, $chapter, ArtifactType::SceneDraft, GenerationStage::SceneGeneration, scene: $scenes[0]);
    $scenes[0]->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $first->getKey()]);
    expect($advance->handle($chapter->getKey()))->toBe(GenerationStage::SceneGeneration);
    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $scenes[1]->getKey());

    $second = pipelineArtifact($novel, $chapter, ArtifactType::SceneDraft, GenerationStage::SceneGeneration, scene: $scenes[1]);
    $scenes[1]->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $second->getKey()]);
    expect($advance->handle($chapter->getKey()))->toBe(GenerationStage::ChapterAssembly);
    Queue::assertPushed(AssembleChapterJob::class, 1);

    $draft = pipelineArtifact($novel, $chapter, ArtifactType::ChapterDraft, GenerationStage::ChapterAssembly, [
        'ordered_scene_checksums' => [$first->checksum, $second->checksum],
    ]);
    expect($advance->handle($chapter->getKey()))->toBe(GenerationStage::EventExtraction);
    Queue::assertPushed(ExtractStoryEventsJob::class, 1);

    $candidate = pipelineArtifact($novel, $chapter, ArtifactType::EventCandidate, GenerationStage::EventExtraction, [
        'status' => 'candidate',
        'source_artifact_id' => $draft->getKey(),
        'events' => [],
    ]);
    expect($advance->handle($chapter->getKey()))->toBe(GenerationStage::Review);
    $patch = GenerationArtifact::query()->where('type', ArtifactType::StatePatch)->sole();
    expect((int) data_get($patch->data, 'source_artifact_id'))->toBe($candidate->getKey());
    Queue::assertPushed(ReviewChapterJob::class, 1);

    pipelineReview($novel, $chapter, $draft, ReviewDecision::Pass);
    $chapter->update(['status' => ChapterStatus::Review]);
    expect($advance->handle($chapter->getKey()))->toBeNull();
    Queue::assertNotPushed(CommitChapterJob::class);
});

test('duplicate advancement dispatches only one job and never calls a provider', function () {
    $fixture = pipelineChapter();
    $advance = app(AdvanceChapterPipelineAction::class);

    expect($advance->handle($fixture['chapter']->getKey()))->toBe(GenerationStage::ChapterPlanning)
        ->and($advance->handle($fixture['chapter']->getKey()))->toBe(GenerationStage::ChapterPlanning)
        ->and($fixture['chapter']->generationRuns()->count())->toBe(0);
    Queue::assertPushed(PlanChapterJob::class, 1);
});

test('a paused novel persists its checkpoint without dispatching another stage', function () {
    $fixture = pipelineChapter();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);

    expect(app(AdvanceChapterPipelineAction::class)->handle($fixture['chapter']->getKey()))->toBeNull();
    Queue::assertNothingPushed();
});

test('a stale running stage resumes from the last durable artifact', function () {
    $fixture = pipelineChapter();
    GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Running,
        'updated_at' => now()->subHour(),
    ]);

    expect(app(AdvanceChapterPipelineAction::class)->handle($fixture['chapter']->getKey()))
        ->toBe(GenerationStage::ChapterPlanning);
    Queue::assertPushed(PlanChapterJob::class, 1);
});

test('a fresh running stage is not dispatched a second time', function () {
    $fixture = pipelineChapter();
    GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Running,
        'updated_at' => now(),
    ]);

    expect(app(AdvanceChapterPipelineAction::class)->handle($fixture['chapter']->getKey()))
        ->toBe(GenerationStage::ChapterPlanning);
    Queue::assertNothingPushed();
});

test('a rewrite review dispatches its frozen scope while legacy auto commit remains disabled', function () {
    $fixture = pipelineChapter();
    $draft = pipelineArtifact($fixture['novel'], $fixture['chapter'], ArtifactType::ChapterDraft, GenerationStage::ChapterAssembly);
    pipelineReview($fixture['novel'], $fixture['chapter'], $draft, ReviewDecision::Rewrite, [[
        'scope' => 'chapter',
        'scene_id' => null,
        'auto_fixable' => true,
        'requires_human_decision' => false,
    ]]);
    $fixture['chapter']->update(['status' => ChapterStatus::Rewrite]);

    expect(app(AdvanceChapterPipelineAction::class)->handle($fixture['chapter']->getKey()))->toBe(GenerationStage::Rewrite);
    Queue::assertPushed(RewriteChapterJob::class, fn (RewriteChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey() && $job->sceneId === null);

    Cache::flush();
    $review = Review::query()->latest('id')->firstOrFail();
    $review->forceFill(['decision' => ReviewDecision::Pass])->save();
    $fixture['novel']->update(['settings' => ['auto_commit' => true]]);
    $fixture['chapter']->update(['status' => ChapterStatus::Review]);
    expect(app(AdvanceChapterPipelineAction::class)->handle($fixture['chapter']->getKey()))->toBeNull();
    Queue::assertNotPushed(CommitChapterJob::class);
});

test('an explicit automatic commit setting dispatches the frozen pass review exactly once', function () {
    $fixture = pipelineChapter();
    $chapter = $fixture['chapter'];
    $novel = $fixture['novel'];
    $draft = pipelineArtifact($novel, $chapter, ArtifactType::ChapterDraft, GenerationStage::ChapterAssembly);
    $candidate = pipelineArtifact($novel, $chapter, ArtifactType::EventCandidate, GenerationStage::EventExtraction, [
        'status' => 'candidate',
        'source_artifact_id' => $draft->getKey(),
        'events' => [],
    ]);
    pipelineArtifact($novel, $chapter, ArtifactType::StatePatch, GenerationStage::EventExtraction, [
        'source_artifact_id' => $candidate->getKey(),
        'expected_state_version' => 0,
        'operations' => [],
    ]);
    $review = pipelineReview($novel, $chapter, $draft, ReviewDecision::Pass);
    $novel->update(['settings' => ['auto_commit' => true, 'auto_commit_configured' => true]]);
    $chapter->update(['status' => ChapterStatus::Review]);

    $advance = app(AdvanceChapterPipelineAction::class);
    expect($advance->handle($chapter->getKey()))->toBe(GenerationStage::Commit)
        ->and($advance->handle($chapter->getKey()))->toBe(GenerationStage::Commit);

    Queue::assertPushed(CommitChapterJob::class, 1);
    Queue::assertPushed(CommitChapterJob::class, fn (CommitChapterJob $job): bool => $job->reviewId === $review->getKey());
});

test('automatic commit stops when the frozen state version is stale', function () {
    $fixture = pipelineChapter();
    $chapter = $fixture['chapter'];
    $novel = $fixture['novel'];
    $draft = pipelineArtifact($novel, $chapter, ArtifactType::ChapterDraft, GenerationStage::ChapterAssembly);
    $candidate = pipelineArtifact($novel, $chapter, ArtifactType::EventCandidate, GenerationStage::EventExtraction, [
        'source_artifact_id' => $draft->getKey(),
        'events' => [],
    ]);
    pipelineArtifact($novel, $chapter, ArtifactType::StatePatch, GenerationStage::EventExtraction, [
        'source_artifact_id' => $candidate->getKey(),
        'expected_state_version' => 0,
        'operations' => [],
    ]);
    $review = pipelineReview($novel, $chapter, $draft, ReviewDecision::Pass);
    $review->generationRun->update(['state_version' => 99]);
    $novel->update(['settings' => ['auto_commit' => true, 'auto_commit_configured' => true]]);
    $chapter->update(['status' => ChapterStatus::Review]);

    expect(app(AdvanceChapterPipelineAction::class)->handle($chapter->getKey()))->toBe(GenerationStage::Review);
    Queue::assertNotPushed(CommitChapterJob::class);
});

test('every successful generation job delegates continuation to the same action', function () {
    $fixture = pipelineChapter();
    $scene = Scene::factory()->for($fixture['chapter'])->create();
    $artifact = new GenerationArtifact;
    $plan = new ChapterPlan;
    $review = new Review;
    $advance = Mockery::mock(AdvanceChapterPipelineAction::class);
    $advance->shouldReceive('handle')->with($fixture['chapter']->getKey())->times(5);
    $advance->shouldReceive('handle')->with($fixture['chapter']->getKey(), 'batch-1')->once();

    $planner = Mockery::mock(ChapterPlanner::class);
    $planner->shouldReceive('generate')->once()->andReturn($plan);
    (new PlanChapterJob($fixture['chapter']->getKey()))->handle($planner, $advance);

    $writer = Mockery::mock(SceneGenerator::class);
    $writer->shouldReceive('generate')->once()->andReturn($artifact);
    (new GenerateSceneJob($scene->getKey(), regenerationBatchId: 'batch-1'))->handle($writer, $advance);

    $assembler = Mockery::mock(ChapterAssembler::class);
    $assembler->shouldReceive('assemble')->once()->andReturn($artifact);
    (new AssembleChapterJob($fixture['chapter']->getKey()))->handle($assembler, $advance);

    $extractor = Mockery::mock(StoryEventExtractor::class);
    $extractor->shouldReceive('extract')->once()->andReturn($artifact);
    (new ExtractStoryEventsJob($fixture['chapter']->getKey()))->handle($extractor, $advance);

    $reviewer = Mockery::mock(ChapterReviewer::class);
    $reviewer->shouldReceive('review')->once()->andReturn($review);
    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle($reviewer, $advance);

    $rewriter = Mockery::mock(ChapterRewriter::class);
    $rewriter->shouldReceive('rewrite')->once()->andReturn($artifact);
    (new RewriteChapterJob($fixture['chapter']->getKey()))->handle($rewriter, $advance);
});
