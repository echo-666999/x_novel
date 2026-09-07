<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Enums\VolumeStatus;
use App\Jobs\AssembleChapterJob;
use App\Jobs\CommitChapterJob;
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
use App\Models\Volume;
use App\Services\ResumeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('resolver detects every persisted pipeline resume point without queue state', function () {
    $resolver = app(ResumeResolver::class);

    $none = pausedResumeNovel();

    $planned = pausedResumeNovel();
    $plannedChapter = resumeChapter($planned);

    $withPlan = pausedResumeNovel();
    $planChapter = resumeChapter($withPlan);
    ChapterPlan::factory()->for($planChapter)->create(['status' => PlanStatus::Ready]);

    $partial = pausedResumeNovel();
    $partialChapter = resumeChapter($partial);
    [$finishedScene, $nextScene] = resumeScenes($partialChapter, false);

    $complete = pausedResumeNovel();
    $completeChapter = resumeChapter($complete);
    resumeScenes($completeChapter, true);

    $drafted = pausedResumeNovel();
    $draftedChapter = resumeChapter($drafted, ChapterStatus::Review);
    resumeArtifact($drafted, $draftedChapter, ArtifactType::ChapterDraft);

    $rewrite = pausedResumeNovel();
    $rewriteChapter = resumeChapter($rewrite, ChapterStatus::Rewrite);
    $rewriteReview = resumeReview($rewrite, $rewriteChapter, ReviewDecision::Rewrite, [['scene_id' => 7]]);

    $passed = pausedResumeNovel();
    $passedChapter = resumeChapter($passed, ChapterStatus::Review);
    $passReview = resumeReview($passed, $passedChapter, ReviewDecision::Pass);

    $canonical = pausedResumeNovel(['auto_generate' => true], 1);
    $canonicalChapter = resumeChapter($canonical, ChapterStatus::Canonical, 1);

    expect($resolver->detect($none)->key)->toBe('plan')
        ->and($resolver->detect($planned)->key)->toBe('plan')
        ->and($resolver->detect($planned)->chapterId)->toBe($plannedChapter->getKey())
        ->and($resolver->detect($withPlan)->key)->toBe('scene')
        ->and($resolver->detect($withPlan)->sceneId)->toBeNull()
        ->and($resolver->detect($partial)->sceneId)->toBe($nextScene->getKey())
        ->and($resolver->detect($complete)->key)->toBe('assemble')
        ->and($resolver->detect($drafted)->key)->toBe('review')
        ->and($resolver->detect($rewrite)->key)->toBe('rewrite')
        ->and($resolver->detect($rewrite)->sceneId)->toBe(7)
        ->and($resolver->detect($passed)->key)->toBe('commit')
        ->and($resolver->detect($passed)->reviewId)->toBe($passReview->getKey())
        ->and($resolver->detect($canonical)->key)->toBe('post_commit')
        ->and($resolver->detect($canonical)->chapterId)->toBe($canonicalChapter->getKey())
        ->and($finishedScene->current_artifact_id)->not->toBeNull()
        ->and($rewriteReview->decision)->toBe(ReviewDecision::Rewrite);
});

test('resume restores the previous status and dispatches exactly one job for each persisted point', function () {
    Queue::fake();
    $resolver = app(ResumeResolver::class);

    $none = pausedResumeNovel();
    $nonePoint = $resolver->resume($none);
    $noneChapter = $none->chapters()->sole();

    $withPlan = pausedResumeNovel();
    $planChapter = resumeChapter($withPlan);
    ChapterPlan::factory()->for($planChapter)->create([
        'status' => PlanStatus::Ready,
        'scene_plans' => [[
            'goal' => '找到线索', 'conflict' => '守卫阻拦', 'turn' => '密门开启', 'outcome' => '进入遗迹',
        ]],
    ]);
    $scenePoint = $resolver->resume($withPlan);
    $syncedScene = $planChapter->scenes()->sole();

    $complete = pausedResumeNovel();
    $completeChapter = resumeChapter($complete);
    resumeScenes($completeChapter, true);
    $resolver->resume($complete);

    $drafted = pausedResumeNovel();
    $draftedChapter = resumeChapter($drafted, ChapterStatus::Review);
    resumeArtifact($drafted, $draftedChapter, ArtifactType::ChapterDraft);
    $resolver->resume($drafted);

    $rewrite = pausedResumeNovel();
    $rewriteChapter = resumeChapter($rewrite, ChapterStatus::Rewrite);
    resumeReview($rewrite, $rewriteChapter, ReviewDecision::Rewrite);
    $resolver->resume($rewrite);

    $passed = pausedResumeNovel();
    $passedChapter = resumeChapter($passed, ChapterStatus::Review);
    $passReview = resumeReview($passed, $passedChapter, ReviewDecision::Pass);
    $resolver->resume($passed);

    expect($nonePoint->key)->toBe('plan')
        ->and($scenePoint->key)->toBe('scene')
        ->and($none->fresh()->status)->toBe(NovelStatus::Generating)
        ->and($withPlan->fresh()->status)->toBe(NovelStatus::Generating)
        ->and(data_get($none->fresh()->settings, 'pause.resumed_at'))->not->toBeNull()
        ->and(fn () => $resolver->resume($none))->toThrow(ValidationException::class, '只有已暂停的小说');

    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $noneChapter->getKey());
    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $syncedScene->getKey());
    Queue::assertPushed(AssembleChapterJob::class, fn (AssembleChapterJob $job): bool => $job->chapterId === $completeChapter->getKey());
    Queue::assertPushed(ReviewChapterJob::class, fn (ReviewChapterJob $job): bool => $job->chapterId === $draftedChapter->getKey());
    Queue::assertPushed(RewriteChapterJob::class, fn (RewriteChapterJob $job): bool => $job->chapterId === $rewriteChapter->getKey());
    Queue::assertPushed(CommitChapterJob::class, fn (CommitChapterJob $job): bool => $job->chapterId === $passedChapter->getKey() && $job->reviewId === $passReview->getKey());
});

test('blocked review stays paused and repeated resume is rejected', function () {
    Queue::fake();
    $novel = pausedResumeNovel();
    $chapter = resumeChapter($novel, ChapterStatus::Blocked);
    resumeReview($novel, $chapter, ReviewDecision::NeedsAttention);
    $resolver = app(ResumeResolver::class);

    expect($resolver->detect($novel)->canResume)->toBeFalse()
        ->and(fn () => $resolver->resume($novel))->toThrow(ValidationException::class, '需要先人工处理');
    expect($novel->fresh()->status)->toBe(NovelStatus::Paused);
    Queue::assertNothingPushed();
});

test('a review for an older draft does not decide the resume point for a newer draft', function () {
    $novel = pausedResumeNovel();
    $chapter = resumeChapter($novel, ChapterStatus::Review);
    resumeReview($novel, $chapter, ReviewDecision::Pass);
    $latestDraft = resumeArtifact($novel, $chapter, ArtifactType::RewriteDraft);

    $point = app(ResumeResolver::class)->detect($novel);

    expect($point->key)->toBe('review')
        ->and($latestDraft->type)->toBe(ArtifactType::RewriteDraft);
});

/** @param array<string, mixed> $settings */
function pausedResumeNovel(array $settings = [], ?int $currentSequence = null): Novel
{
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Paused,
        'current_chapter_sequence' => $currentSequence,
        'settings' => array_replace_recursive([
            'pause' => ['previous_status' => 'generating', 'label' => '章节规划'],
        ], $settings),
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    return $novel->refresh();
}

function resumeChapter(Novel $novel, ChapterStatus $status = ChapterStatus::Generating, int $sequence = 1): Chapter
{
    return Chapter::factory()->for($novel)->create([
        'volume_id' => $novel->volumes()->firstOrFail()->getKey(),
        'sequence' => $sequence,
        'status' => $status,
    ]);
}

/** @return array{Scene, Scene} */
function resumeScenes(Chapter $chapter, bool $complete): array
{
    $first = Scene::factory()->for($chapter)->create(['sequence' => 1, 'status' => SceneStatus::Draft]);
    $artifact = resumeArtifact($chapter->novel, $chapter, ArtifactType::SceneDraft, $first);
    $first->update(['current_artifact_id' => $artifact->getKey()]);
    $second = Scene::factory()->for($chapter)->create([
        'sequence' => 2,
        'status' => $complete ? SceneStatus::Draft : SceneStatus::Planned,
    ]);

    if ($complete) {
        $artifact = resumeArtifact($chapter->novel, $chapter, ArtifactType::SceneDraft, $second);
        $second->update(['current_artifact_id' => $artifact->getKey()]);
    }

    return [$first->refresh(), $second->refresh()];
}

function resumeArtifact(Novel $novel, Chapter $chapter, ArtifactType $type, ?Scene $scene = null): GenerationArtifact
{
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => $scene?->getKey(),
        'scope_type' => $scene === null ? 'chapter' : 'scene',
        'scope_id' => $scene?->getKey() ?? $chapter->getKey(),
        'stage' => $type === ArtifactType::SceneDraft ? GenerationStage::SceneGeneration : GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);

    return GenerationArtifact::factory()->for($run)->create(['type' => $type]);
}

/** @param array<int, array<string, mixed>> $findings */
function resumeReview(Novel $novel, Chapter $chapter, ReviewDecision $decision, array $findings = []): Review
{
    $draft = resumeArtifact($novel, $chapter, ArtifactType::ChapterDraft);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
    ]);

    $reviewArtifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ReviewResult,
        'data' => ['source_artifact_id' => $draft->getKey()],
    ]);

    return Review::factory()->create([
        'generation_run_id' => $run->getKey(),
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => $decision,
        'findings' => $findings,
    ]);
}
