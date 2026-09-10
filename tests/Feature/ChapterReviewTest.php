<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
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
use App\Jobs\RewriteChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Review;
use App\Models\Scene;
use App\Models\StoryStateVersion;
use App\Services\ChapterReviewer;
use App\Services\StateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config()->set('ai.budget.daily_hard_limit', null);
    config()->set('ai.budget.novel_total_limit', null);
    config()->set('ai.budget.chapter_max_cost', null);
});

function reviewFixture(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    $content = '林舟守住城门，也兑现了向同伴作出的承诺。';
    ChapterPlan::factory()->for($chapter)->create(['target_words' => mb_strlen($content)]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create(['scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded]);
    $draft = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::ChapterDraft, 'content' => $content, 'checksum' => hash('sha256', $content)]);

    return compact('novel', 'chapter', 'draft');
}

function reviewResponse(string $decision = 'PASS', int $score = 90, array $findings = []): AiResponse
{
    $data = ['recommended_decision' => $decision, 'scores' => ['continuity' => $score, 'plan' => $score, 'character' => $score, 'progress' => $score, 'repetition' => $score, 'pacing' => $score, 'style' => $score], 'findings' => $findings];

    return new AiResponse(content: json_encode($data), structuredData: $data, inputTokens: 100, outputTokens: 80, cachedTokens: 0, latencyMs: 100, providerRequestId: 'review-request', model: 'review-test');
}

function reviewFinding(
    string $code = 'STYLE_MISMATCH',
    string $dimension = 'style',
    string $severity = 'warning',
    ?int $sceneId = null,
    string $scope = 'chapter',
    bool $autoFixable = false,
    bool $requiresHumanDecision = false,
    string $message = '主文风不匹配。',
    string $evidence = '林舟守住城门',
): array {
    return [
        'code' => $code,
        'dimension' => $dimension,
        'severity' => $severity,
        'scene_id' => $sceneId,
        'scope' => $scope,
        'auto_fixable' => $autoFixable,
        'requires_human_decision' => $requiresHumanDecision,
        'message' => $message,
        'evidence' => $evidence,
    ];
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
        ->and($review->generationRun->bible_version)->toBe(1)
        ->and(data_get($review->generationRun->context_snapshot, 'style_contract_checksum'))->toBe(data_get($review->generationRun->context_snapshot, 'l4.checksum'))
        ->and(data_get($review->generationRun->context_snapshot, 'l4.primary_style.name'))->toBe('通俗爽快')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review)
        ->and($fake->requests()[0]->systemPrompt)->toContain('message 与 evidence 必须使用简体中文')
        ->and($fake->requests()[0]->systemPrompt)->toContain('state_findings 为空表示确定性检查未发现问题')
        ->and($fake->requests()[0]->systemPrompt)->toContain('may_hint 是可选提示');
});

test('style findings are grounded in draft evidence and the frozen style contract', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $finding = [
        'code' => 'STYLE_MISMATCH',
        'dimension' => 'style',
        'severity' => 'warning',
        'scene_id' => null,
        'scope' => 'chapter',
        'auto_fixable' => false,
        'requires_human_decision' => false,
        'message' => '连续铺陈削弱了主文风要求的直接推进。',
        'evidence' => '林舟守住城门',
    ];
    $fake = (new FakeAiProvider)->enqueue(reviewResponse(findings: [$finding]));
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->findings)->toContainEqual([...$finding, 'source' => 'narrative_review'])
        ->and($fake->requests()[0]->prompt)->toContain('通俗爽快')
        ->and($fake->requests()[0]->systemPrompt)->toContain('style finding 的 evidence 必须引用草稿中的具体短句');
});

test('review rejects a style finding without textual evidence', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(evidence: '')]));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterReviewer::class)->review($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, 'Findings 结构无效');

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::Review)->sole()->status)
        ->toBe(RunStatus::Failed);
});

test('narrative review compares the opening with the previous canonical ending', function () {
    $fixture = reviewFixture();
    $fixture['chapter']->update(['sequence' => 2]);
    $previous = Chapter::factory()->for($fixture['novel'])->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
    ]);
    $previousRun = GenerationRun::factory()->for($fixture['novel'])->for($previous)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $previousDraft = GenerationArtifact::factory()->for($previousRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => '两人正沿山路走向学院。',
    ]);
    $previous->update(['canonical_artifact_id' => $previousDraft->getKey()]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse());
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect(data_get($review->generationRun->context_snapshot, 'previous_chapter_ending.text'))->toBe('两人正沿山路走向学院。')
        ->and($fake->requests()[0]->systemPrompt)->toContain('本章开头');
});

test('deterministic length check forces a short chapter into rewrite', function () {
    $fixture = reviewFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse(score: 60));
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());
    $actualWords = mb_strlen($fixture['draft']->content);

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and($review->findings)->toContainEqual([
            'code' => 'CHAPTER_LENGTH_TOO_SHORT',
            'dimension' => 'pacing',
            'severity' => 'error',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => true,
            'requires_human_decision' => false,
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
        ->and($review->findings[0]['auto_fixable'])->toBeFalse()
        ->and(data_get($review->artifact->data, 'decision_basis.rule'))->toBe('hard_finding')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked);
});

test('missing state patch stops review before an ai request and does not create a false block review', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([
        new StateFinding('INVALID_STATE_PATCH', StateFindingSeverity::Hard, '本章尚未生成 State Patch。'),
    ]));
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterReviewer::class)->review($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '请先补建状态补丁');

    expect($fake->requests())->toHaveCount(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::Review)->count())->toBe(0)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review);
});

test('model recommendation does not override the laravel decision matrix', function (string $recommended) {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse($recommended, 95)));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Pass)
        ->and(data_get($review->artifact->data, 'recommended_decision'))->toBe($recommended)
        ->and(data_get($review->artifact->data, 'decision_basis.rule'))->toBe('score_and_non_blocking_findings');
})->with([
    'model suggests rewrite' => ['REWRITE'],
    'model suggests needs attention' => ['NEEDS_ATTENTION'],
    'model suggests block' => ['BLOCK'],
]);

test('structured findings deterministically route ordinary warnings', function (array $finding, ReviewDecision $expected, string $rule) {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(score: 95, findings: [$finding])));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe($expected)
        ->and(data_get($review->artifact->data, 'decision_basis.rule'))->toBe($rule)
        ->and(data_get($review->artifact->data, 'decision_basis.finding_codes'))->toBe([$finding['code']]);
})->with([
    'auto fixable warning' => [reviewFinding(autoFixable: true), ReviewDecision::Rewrite, 'auto_fixable_finding'],
    'non blocking warning' => [reviewFinding(), ReviewDecision::Pass, 'score_and_non_blocking_findings'],
    'user choice required' => [reviewFinding(requiresHumanDecision: true, message: '两个互斥的角色动机均无 Canonical 依据，需要用户选择。'), ReviewDecision::NeedsAttention, 'human_decision_required'],
]);

test('auto fixable plan and style findings request rewrite', function (array $finding) {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [$finding])));

    expect(app(ChapterReviewer::class)->review($fixture['chapter']->getKey())->decision)
        ->toBe(ReviewDecision::Rewrite);
})->with([
    'plan' => [reviewFinding(code: 'PLAN_DEVIATION', dimension: 'plan', severity: 'error', autoFixable: true, message: '未完成必须揭示的信息。')],
    'style' => [reviewFinding(severity: 'error', autoFixable: true)],
]);

test('a low score with an executable finding requests rewrite', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(score: 60, findings: [reviewFinding(autoFixable: true)])));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and(data_get($review->artifact->data, 'decision_basis.rule'))->toBe('auto_fixable_finding');
});

test('an ambiguous deterministic finding requires attention', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([
        new StateFinding('CANONICAL_AMBIGUITY', StateFindingSeverity::Ambiguous, 'Canonical 数据不足以确定两个分支中的哪一个成立。'),
    ]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse()));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::NeedsAttention)
        ->and($review->findings[0]['requires_human_decision'])->toBeTrue()
        ->and(data_get($review->artifact->data, 'decision_basis.finding_codes'))->toBe(['CANONICAL_AMBIGUITY']);
});

test('review validates scene references and finding evidence before persistence', function (string $invalidCase) {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create();
    $finding = match ($invalidCase) {
        'foreign_scene' => reviewFinding(sceneId: Scene::factory()->create()->getKey(), scope: 'scene'),
        'missing_scene_id' => reviewFinding(scope: 'scene'),
        'missing_evidence' => reviewFinding(evidence: ''),
        'mismatched_code' => reviewFinding(code: 'PLAN_DEVIATION', dimension: 'style'),
    };
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [$finding])));

    expect(fn () => app(ChapterReviewer::class)->review($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, 'Findings 结构无效');

    expect(Review::query()->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $fixture['chapter']->getKey()))->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::Review)->sole()->status)->toBe(RunStatus::Failed);
})->with([
    'scene from another chapter' => ['foreign_scene'],
    'scene scope without scene id' => ['missing_scene_id'],
    'missing evidence' => ['missing_evidence'],
    'code dimension mismatch' => ['mismatched_code'],
]);

test('a valid scene finding preserves its chapter scoped reference', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create();
    bindStateValidation(new StateValidationResult([]));
    $finding = reviewFinding(sceneId: $scene->getKey(), scope: 'scene', autoFixable: true);
    $fake = (new FakeAiProvider)->enqueue(reviewResponse(findings: [$finding]));
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and($review->findings)->toContainEqual([...$finding, 'source' => 'narrative_review'])
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.findings.items.properties.code.enum'))->toContain('STYLE_MISMATCH')
        ->and(data_get($review->generationRun->context_snapshot, 'scenes.0.id'))->toBe($scene->getKey());
});

test('a below threshold score without an executable finding is rejected', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(score: 60)));

    expect(fn () => app(ChapterReviewer::class)->review($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, '没有提供可执行或需要人工决策的 Finding');
});

test('the review after the final rewrite moves unresolved findings to needs attention', function () {
    $fixture = reviewFixture();
    foreach ([1, 2] as $attempt) {
        $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
            'scope_type' => 'chapter',
            'scope_id' => $fixture['chapter']->getKey(),
            'stage' => GenerationStage::Rewrite,
            'status' => RunStatus::Succeeded,
            'attempt' => $attempt,
        ]);
        GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::RewriteDraft,
            'version' => $attempt,
            'content' => $fixture['draft']->content,
            'checksum' => $fixture['draft']->checksum,
        ]);
    }
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse('PASS', 90, [reviewFinding(autoFixable: true)])));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::NeedsAttention)
        ->and(collect($review->findings)->pluck('code'))->toContain('REWRITE_EXHAUSTED')
        ->and(data_get($review->artifact->data, 'decision_basis.rule'))->toBe('human_decision_required')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review);
});

test('manual edits do not exhaust automatic rewrite attempts during review', function () {
    $fixture = reviewFixture();
    foreach ([1, 2] as $version) {
        $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
            'scope_type' => 'chapter',
            'scope_id' => $fixture['chapter']->getKey(),
            'stage' => GenerationStage::Rewrite,
            'status' => RunStatus::Succeeded,
        ]);
        GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::RewriteDraft,
            'version' => $version,
            'content' => $fixture['draft']->content,
            'checksum' => $fixture['draft']->checksum,
            'data' => ['manual_edit' => true],
        ]);
    }
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(autoFixable: true)])));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and(collect($review->findings)->pluck('code'))->not->toContain('REWRITE_EXHAUSTED');
});

test('duplicate review delivery reuses the successful review', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse());
    app()->instance(AiProvider::class, $fake);

    $first = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());
    $second = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($second?->is($first))->toBeTrue()->and($fake->requests())->toHaveCount(1);
});

test('review job automatically dispatches one rewrite for an auto fixable finding', function () {
    Queue::fake();
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(autoFixable: true)])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertPushed(RewriteChapterJob::class, 1);
    Queue::assertPushed(RewriteChapterJob::class, fn (RewriteChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey() && $job->sceneId === null);
});

test('duplicate review job delivery does not dispatch the same rewrite twice', function () {
    Queue::fake();
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(autoFixable: true)]));
    app()->instance(AiProvider::class, $fake);
    $job = new ReviewChapterJob($fixture['chapter']->getKey(), true);

    $job->handle(app(ChapterReviewer::class));
    $job->handle(app(ChapterReviewer::class));

    expect($fake->requests())->toHaveCount(1);
    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::Review)->count())->toBe(1);
    Queue::assertPushed(RewriteChapterJob::class, 1);
});

test('a completed rewrite for the reused review prevents stale redispatch', function () {
    Queue::fake();
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(autoFixable: true)])));
    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());
    $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::Rewrite,
        'status' => RunStatus::Succeeded,
    ]);
    GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::RewriteDraft,
        'data' => ['source_review_id' => $review->getKey()],
    ]);
    $reviewer = Mockery::mock(ChapterReviewer::class);
    $reviewer->shouldReceive('review')->once()->andReturn($review);

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle($reviewer);

    Queue::assertNotPushed(RewriteChapterJob::class);
});

test('budget exhaustion after review preserves the result and stops before rewrite dispatch', function () {
    Queue::fake();
    config()->set('ai.budget.daily_hard_limit', 0);
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(autoFixable: true)])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Rewrite)
        ->and(data_get($fixture['novel']->fresh()->settings, 'auto_stop.code'))->toBe('budget_limit');
    Queue::assertNotPushed(RewriteChapterJob::class);
});

test('a provider response is saved but pause prevents automatic rewrite dispatch', function () {
    Queue::fake();
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, new class($fixture['novel']) implements AiProvider
    {
        public function __construct(private readonly Novel $novel) {}

        public function generate(AiRequest $request): AiResponse
        {
            $this->novel->update(['status' => NovelStatus::Paused]);

            return reviewResponse(findings: [reviewFinding(autoFixable: true)]);
        }
    });

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    expect($fixture['chapter']->generationRuns()
        ->where('stage', GenerationStage::Review)
        ->where('status', RunStatus::Succeeded)
        ->sole()
        ->artifacts()
        ->where('type', ArtifactType::ReviewResult)
        ->exists())->toBeTrue();
    Queue::assertNotPushed(RewriteChapterJob::class);
});

test('state version conflict during review never dispatches rewrite', function () {
    Queue::fake();
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, new class($fixture['novel']) implements AiProvider
    {
        public function __construct(private readonly Novel $novel) {}

        public function generate(AiRequest $request): AiResponse
        {
            $nextVersion = (int) $this->novel->storyStateVersions()->max('version') + 1;
            $version = StoryStateVersion::factory()->for($this->novel)->create(['version' => $nextVersion]);
            $this->novel->update(['canonical_state_version_id' => $version->getKey()]);

            return reviewResponse(findings: [reviewFinding(autoFixable: true)]);
        }
    });

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    expect(Review::query()->count())->toBe(0);
    Queue::assertNotPushed(RewriteChapterJob::class);
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
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse('PASS', 60, [reviewFinding(autoFixable: true)])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertNotPushed(CommitChapterJob::class);
});
