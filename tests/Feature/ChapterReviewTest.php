<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Providers\FakeAiProvider;
use App\Data\StateFinding;
use App\Data\StateValidationResult;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\StateFindingSeverity;
use App\Jobs\CommitChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Services\ChapterReviewer;
use App\Services\StateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function reviewFixture(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    $content = '林舟守住城门，也兑现了向同伴作出的承诺。';
    ChapterPlan::factory()->for($chapter)->create(['target_words' => mb_strlen($content)]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create(['scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded]);
    $draft = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::ChapterDraft, 'content' => $content, 'checksum' => hash('sha256', $content)]);

    return compact('novel', 'chapter', 'draft');
}

function reviewResponse(string $decision = 'PASS', int $score = 90): AiResponse
{
    $data = ['recommended_decision' => $decision, 'scores' => ['continuity' => $score, 'plan' => $score, 'character' => $score, 'progress' => $score, 'repetition' => $score, 'pacing' => $score, 'style' => $score], 'findings' => []];

    return new AiResponse(content: json_encode($data), structuredData: $data, inputTokens: 100, outputTokens: 80, cachedTokens: 0, latencyMs: 100, providerRequestId: 'review-request', model: 'review-test');
}

function bindStateValidation(StateValidationResult $result): void
{
    $validator = Mockery::mock(StateValidator::class);
    $validator->shouldReceive('validate')->andReturn($result);
    app()->instance(StateValidator::class, $validator);
}

test('narrative review persists seven weighted scores and an immutable result artifact', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse());
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Pass)
        ->and($review->score)->toBe('90.00')
        ->and($review->continuity_score)->toBe('90.00')
        ->and($review->artifact->type)->toBe(ArtifactType::ReviewResult)
        ->and($review->generationRun->status)->toBe(RunStatus::Succeeded)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review)
        ->and($fake->requests()[0]->systemPrompt)->toContain('message 与 evidence 必须使用简体中文')
        ->and($fake->requests()[0]->systemPrompt)->toContain('state_findings 为空表示确定性检查未发现问题')
        ->and($fake->requests()[0]->systemPrompt)->toContain('may_hint 是可选提示');
});

test('deterministic length check forces a short chapter into rewrite', function () {
    $fixture = reviewFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse());
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());
    $actualWords = mb_strlen($fixture['draft']->content);

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and($review->findings)->toContainEqual([
            'code' => 'CHAPTER_LENGTH_TOO_SHORT',
            'dimension' => 'pacing',
            'severity' => 'error',
            'message' => "当前草稿 {$actualWords} 字，目标 100 字，至少需要达到 85 字。",
            'evidence' => '当前稿完成度为 '.$actualWords.'%。',
            'source' => 'length_check',
        ])
        ->and(data_get($review->generationRun->context_snapshot, 'length_check.status'))->toBe('too_short')
        ->and($fake->requests()[0]->systemPrompt)->toContain('不要重复报告其中的字数问题');
});

test('deterministic length check also rejects an excessively long chapter', function () {
    $fixture = reviewFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 10]);
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse()));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and(collect($review->findings)->pluck('code'))->toContain('CHAPTER_LENGTH_TOO_LONG')
        ->and(data_get($review->generationRun->context_snapshot, 'length_check.status'))->toBe('too_long');
});

test('deterministic hard state finding overrides narrative score with block', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([new StateFinding('LOCKED_FACT_CONFLICT', StateFindingSeverity::Hard, '与锁定事实冲突。')]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse()));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Block)
        ->and($review->findings[0]['code'])->toBe('LOCKED_FACT_CONFLICT')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked);
});

test('low score requests rewrite and ambiguous model block requires attention', function (string $recommended, int $score, ReviewDecision $expected) {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse($recommended, $score)));

    expect(app(ChapterReviewer::class)->review($fixture['chapter']->getKey())->decision)->toBe($expected);
})->with([
    ['PASS', 60, ReviewDecision::Rewrite],
    ['BLOCK', 95, ReviewDecision::NeedsAttention],
]);

test('duplicate review delivery reuses the successful review', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse());
    app()->instance(AiProvider::class, $fake);

    $first = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());
    $second = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($second?->is($first))->toBeTrue()->and($fake->requests())->toHaveCount(1);
});

test('review job dispatches canonical commit only for pass reviews with auto commit enabled', function () {
    Queue::fake();
    $fixture = reviewFixture();
    $fixture['novel']->update(['settings' => ['auto_commit' => true]]);
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse()));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertPushed(CommitChapterJob::class, fn (CommitChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey());
});

test('a provider response is saved but pause prevents the review job from dispatching commit', function () {
    Queue::fake();
    $fixture = reviewFixture();
    $fixture['novel']->update(['settings' => ['auto_commit' => true]]);
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, new class($fixture['novel']) implements AiProvider
    {
        public function __construct(private readonly Novel $novel) {}

        public function generate(AiRequest $request): AiResponse
        {
            $this->novel->update(['status' => NovelStatus::Paused]);

            return reviewResponse();
        }
    });

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    $completedRun = $fixture['chapter']->generationRuns()
        ->where('stage', GenerationStage::Review)
        ->where('status', RunStatus::Succeeded)
        ->sole();

    expect($completedRun->artifacts()->where('type', ArtifactType::ReviewResult)->exists())->toBeTrue()
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review);
    Queue::assertNotPushed(CommitChapterJob::class);
});

test('review job keeps manual mode when auto commit is disabled', function () {
    Queue::fake();
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse()));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertNotPushed(CommitChapterJob::class);
});

test('review job does not auto commit a non pass review', function () {
    Queue::fake();
    $fixture = reviewFixture();
    $fixture['novel']->update(['settings' => ['auto_commit' => true]]);
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse('REWRITE', 60)));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertNotPushed(CommitChapterJob::class);
});
