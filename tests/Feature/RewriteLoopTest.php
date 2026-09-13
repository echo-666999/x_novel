<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\AssembleChapterJob;
use App\Jobs\ExtractStoryEventsJob;
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
use App\Services\ChapterRewriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function rewriteFixture(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Rewrite]);
    ChapterPlan::factory()->for($chapter)->create(['target_words' => 8, 'status' => PlanStatus::Ready]);
    $assembly = GenerationRun::factory()->for($novel)->for($chapter)->create(['scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded]);
    $draft = GenerationArtifact::factory()->for($assembly)->create(['type' => ArtifactType::ChapterDraft, 'content' => '原始章节正文', 'checksum' => hash('sha256', '原始章节正文')]);
    $reviewRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create(['type' => ArtifactType::ReviewResult]);
    $review = Review::factory()->create(['generation_run_id' => $reviewRun->getKey(), 'artifact_id' => $reviewArtifact->getKey(), 'decision' => ReviewDecision::Rewrite, 'findings' => [['dimension' => 'pacing', 'severity' => 'error', 'message' => '结尾节奏拖沓', 'evidence' => '末段重复']]]);

    return compact('novel', 'chapter', 'draft', 'review');
}

function rewriteResponse(string $content = '修订后的章节正文'): AiResponse
{
    return new AiResponse(content: $content, structuredData: null, inputTokens: 100, outputTokens: 100, cachedTokens: 0, latencyMs: 100, providerRequestId: 'rewrite', model: 'rewrite-test');
}

function sceneRewriteResponse(string $content = '破门逆转'): AiResponse
{
    $selfCheck = collect(['goal', 'conflict', 'turn', 'outcome'])
        ->mapWithKeys(fn (string $element): array => [$element => [
            'status' => 'fulfilled',
            'evidence' => '破门',
        ]])
        ->all();
    $data = ['content' => $content, 'self_check' => $selfCheck];

    return new AiResponse(
        content: json_encode($data, JSON_UNESCAPED_UNICODE),
        structuredData: $data,
        inputTokens: 100,
        outputTokens: 100,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'scene-rewrite',
        model: 'rewrite-test',
    );
}

function sceneRewriteFixture(): array
{
    $fixture = rewriteFixture();
    $fixture['chapter']->latestPlan()->update([
        'target_words' => 8,
        'scene_plans' => [
            [
                'goal' => '突破城门',
                'conflict' => '守军阻拦',
                'turn' => '队友掩护',
                'outcome' => '林舟破门',
                'outcome_allowed' => ['破门'],
                'outcome_forbidden' => ['撤退'],
            ],
            [
                'goal' => '固守通道',
                'conflict' => '追兵逼近',
                'turn' => '机关启动',
                'outcome' => '暂时守住',
                'outcome_allowed' => ['守住'],
                'outcome_forbidden' => ['失守'],
            ],
        ],
    ]);

    $scenes = collect([
        ['sequence' => 1, 'content' => '旧稿失速', 'goal' => '突破城门', 'conflict' => '守军阻拦', 'turn' => '队友掩护', 'outcome' => '林舟破门'],
        ['sequence' => 2, 'content' => '守军待命', 'goal' => '固守通道', 'conflict' => '追兵逼近', 'turn' => '机关启动', 'outcome' => '暂时守住'],
    ])->map(function (array $attributes) use ($fixture): array {
        $content = $attributes['content'];
        unset($attributes['content']);
        $scene = Scene::factory()->for($fixture['chapter'])->create([
            ...$attributes,
            'status' => SceneStatus::Draft,
        ]);
        $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
            'scene_id' => $scene->getKey(),
            'scope_type' => 'scene',
            'scope_id' => $scene->getKey(),
            'stage' => GenerationStage::SceneGeneration,
            'status' => RunStatus::Succeeded,
        ]);
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft,
            'content' => $content,
            'checksum' => hash('sha256', $content),
        ]);
        $scene->update(['current_artifact_id' => $artifact->getKey()]);

        return ['scene' => $scene->refresh(), 'artifact' => $artifact];
    })->all();

    $fixture['review']->update(['findings' => [
        [
            'code' => 'STYLE_MISMATCH',
            'dimension' => 'style',
            'severity' => 'error',
            'scene_id' => $scenes[0]['scene']->getKey(),
            'scope' => 'scene',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'message' => '第一场景节奏失速。',
            'evidence' => '旧稿失速',
        ],
        [
            'code' => 'STYLE_MISMATCH',
            'dimension' => 'style',
            'severity' => 'warning',
            'scene_id' => $scenes[1]['scene']->getKey(),
            'scope' => 'scene',
            'auto_fixable' => false,
            'requires_human_decision' => false,
            'message' => '第二场景有可选的文风建议。',
            'evidence' => '守军待命',
        ],
    ]]);

    return [...$fixture, 'target' => $scenes[0], 'untouched' => $scenes[1]];
}

test('chapter rewrite creates a new immutable artifact with finding hash', function () {
    $fixture = rewriteFixture();
    $fake = (new FakeAiProvider)->enqueue(rewriteResponse());
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect($artifact->type)->toBe(ArtifactType::RewriteDraft)
        ->and($artifact->version)->toBe(1)
        ->and($artifact->content)->toBe('修订后的章节正文')
        ->and($artifact->data['source_artifact_id'])->toBe($fixture['draft']->getKey())
        ->and($artifact->data['source_review_id'])->toBe($fixture['review']->getKey())
        ->and($artifact->data['finding_hash'])->toHaveLength(64)
        ->and($artifact->data['word_count'])->toBe(mb_strlen('修订后的章节正文'))
        ->and(data_get($artifact->generationRun->context_snapshot, 'length_requirement.target_words'))->toBe($fixture['chapter']->latestPlan->target_words)
        ->and(data_get($artifact->generationRun->context_snapshot, 'length_requirement.current_words'))->toBe(mb_strlen('原始章节正文'))
        ->and($artifact->generationRun->bible_version)->toBe(1)
        ->and(data_get($artifact->generationRun->context_snapshot, 'style_contract_checksum'))->toBe(data_get($artifact->generationRun->context_snapshot, 'l4.checksum'))
        ->and(data_get($artifact->generationRun->context_snapshot, 'l4.primary_style.name'))->toBe('通俗爽快')
        ->and(data_get($artifact->data, 'plan_acceptance.chapter_function'))->toBe($fixture['chapter']->latestPlan->chapter_function)
        ->and(data_get($artifact->data, 'repair_findings.0.evidence'))->toBe('末段重复')
        ->and($fake->requests()[0]->prompt)->toContain('"plan_acceptance"')
        ->and($artifact->generationRun->idempotency_key)->toStartWith('rewrite:'.$fixture['draft']->getKey().':');
});

test('chapter rewrite receives the previous canonical ending for continuity repair', function () {
    $fixture = rewriteFixture();
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
        'content' => '两人正向魔法学院走去。',
    ]);
    $previous->update(['canonical_artifact_id' => $previousDraft->getKey()]);
    $fake = (new FakeAiProvider)->enqueue(rewriteResponse());
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect(data_get($artifact->generationRun->context_snapshot, 'previous_chapter_ending.text'))->toBe('两人正向魔法学院走去。')
        ->and($fake->requests()[0]->systemPrompt)->toContain('时间、地点和行动过渡');
});

test('duplicate rewrite delivery reuses the artifact for the same review', function () {
    $fixture = rewriteFixture();
    $fake = (new FakeAiProvider)->enqueue(rewriteResponse());
    app()->instance(AiProvider::class, $fake);

    $first = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());
    $second = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect($second?->is($first))->toBeTrue()->and($fake->requests())->toHaveCount(1);
});

test('rewrite job continues with fresh event extraction', function () {
    Queue::fake();
    $fixture = rewriteFixture();
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(rewriteResponse()));

    (new RewriteChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterRewriter::class));

    Queue::assertPushed(ExtractStoryEventsJob::class, fn (ExtractStoryEventsJob $job): bool => ! $job->regenerate && ! $job->continueRewrite);
});

test('scene rewrite replaces only the target pointer and preserves plan and style constraints', function () {
    $fixture = sceneRewriteFixture();
    $fake = (new FakeAiProvider)->enqueue(sceneRewriteResponse());
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterRewriter::class)->rewrite(
        $fixture['chapter']->getKey(),
        $fixture['target']['scene']->getKey(),
    );

    expect($artifact->type)->toBe(ArtifactType::RewriteDraft)
        ->and($artifact->data['scope'])->toBe('scene')
        ->and($artifact->data['source_artifact_id'])->toBe($fixture['target']['artifact']->getKey())
        ->and($artifact->data['repair_findings'])->toHaveCount(1)
        ->and(data_get($artifact->data, 'repair_findings.0.evidence'))->toBe('旧稿失速')
        ->and(data_get($artifact->data, 'plan_acceptance.coverage_expectations.outcome.description'))->toBe('林舟破门')
        ->and(data_get($artifact->data, 'self_check.outcome.status'))->toBe('fulfilled')
        ->and($artifact->data['plan_findings'])->toBe([])
        ->and($fixture['target']['scene']->fresh()->current_artifact_id)->toBe($artifact->getKey())
        ->and($fixture['untouched']['scene']->fresh()->current_artifact_id)->toBe($fixture['untouched']['artifact']->getKey())
        ->and($fixture['target']['artifact']->fresh()->content)->toBe('旧稿失速')
        ->and($fixture['draft']->fresh()->content)->toBe('原始章节正文')
        ->and(data_get($artifact->generationRun->context_snapshot, 'l4.primary_style.name'))->toBe('通俗爽快')
        ->and($fake->requests()[0]->responseSchema)->not->toBeNull()
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得改写其他 Scene')
        ->and($fake->requests()[0]->prompt)->toContain('旧稿失速')
        ->and($fake->requests()[0]->prompt)->not->toContain('第二场景有可选的文风建议');
});

test('scene rewrite job returns to assembly before event extraction', function () {
    Queue::fake();
    $fixture = sceneRewriteFixture();
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(sceneRewriteResponse()));

    (new RewriteChapterJob(
        $fixture['chapter']->getKey(),
        $fixture['target']['scene']->getKey(),
    ))->handle(app(ChapterRewriter::class));

    Queue::assertPushed(AssembleChapterJob::class, fn (AssembleChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey()
        && ! $job->regenerate
        && ! $job->continueRewrite);
    Queue::assertNotPushed(ExtractStoryEventsJob::class);
});

test('rewrite exhaustion becomes needs attention', function () {
    $fixture = rewriteFixture();
    foreach ([1, 2] as $attempt) {
        $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create(['scope_type' => 'chapter', 'scope_id' => $fixture['chapter']->getKey(), 'stage' => GenerationStage::Rewrite, 'status' => RunStatus::Succeeded]);
        GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::RewriteDraft, 'version' => $attempt, 'data' => ['source_review_id' => 999 + $attempt]]);
    }
    app()->instance(AiProvider::class, new FakeAiProvider);

    expect(fn () => app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '最大 2 次');
    expect($fixture['review']->fresh()->decision)->toBe(ReviewDecision::Rewrite)
        ->and(Review::query()->latest('id')->first()->decision)->toBe(ReviewDecision::NeedsAttention)
        ->and(Review::query()->latest('id')->first()->findings)->toContainEqual([
            'code' => 'REWRITE_EXHAUSTED',
            'dimension' => 'workflow',
            'severity' => 'ambiguous',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => false,
            'requires_human_decision' => true,
            'message' => '自动 Rewrite 已达到最大 2 次，需要人工处理。',
            'evidence' => '已完成 2 次自动 Rewrite。',
            'source' => 'rewrite_loop',
        ])
        ->and(data_get(Review::query()->latest('id')->first()->artifact->data, 'decision_basis.rule'))->toBe('human_decision_required')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review);
});

test('manual edits do not consume the automatic rewrite budget', function () {
    $fixture = rewriteFixture();
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
            'content' => '人工修改稿',
            'data' => ['manual_edit' => true, 'source_review_id' => 1000 + $version],
        ]);
    }
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(rewriteResponse()));

    $artifact = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect($artifact->version)->toBe(3)
        ->and($artifact->data['attempt'])->toBe(1)
        ->and($artifact->data)->not->toHaveKey('manual_edit');
});

test('paused novel cannot start rewrite', function () {
    $fixture = rewriteFixture();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);
    app()->instance(AiProvider::class, new FakeAiProvider);

    expect(fn () => app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');
});

test('retryable provider failure records a failed run before retry succeeds', function () {
    $fixture = rewriteFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(new AiProviderException('provider_timeout', 'timeout', true))
        ->enqueue(rewriteResponse());
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, 'timeout');
    $artifact = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect($artifact->type)->toBe(ArtifactType::RewriteDraft)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::Rewrite)->where('status', RunStatus::Failed)->count())->toBe(1)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::Rewrite)->where('status', RunStatus::Succeeded)->count())->toBe(1);
});

test('an overlength rewrite is compressed once before it becomes a rewrite draft', function () {
    $fixture = rewriteFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(rewriteResponse(str_repeat('超', 120)))
        ->enqueue(rewriteResponse(str_repeat('改', 100)));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect($artifact->content)->toBe(str_repeat('改', 100))
        ->and($artifact->data['word_count'])->toBe(100)
        ->and($artifact->data['maximum_words'])->toBe(115)
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->systemPrompt)->toContain('重写稿压缩器')
        ->and($fake->requests()[1]->systemPrompt)->toContain('POV、时态、主文风')
        ->and($fake->requests()[1]->prompt)->toContain('"l4"')
        ->and($fake->requests()[1]->prompt)->toContain('通俗爽快');
});

test('a failed length repair does not consume a rewrite artifact attempt', function () {
    $fixture = rewriteFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(rewriteResponse(str_repeat('超', 120)))
        ->enqueue(rewriteResponse(str_repeat('仍', 116)))
        ->enqueue(rewriteResponse(str_repeat('合', 100)));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '本次结果不会成为当前版本');

    expect(GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)->count())->toBe(0);

    $artifact = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect($artifact->version)->toBe(1)
        ->and($artifact->data['attempt'])->toBe(1)
        ->and($artifact->content)->toBe(str_repeat('合', 100))
        ->and($fake->requests())->toHaveCount(3);
});

test('stale rewrite run is marked interrupted before recovery', function () {
    $fixture = rewriteFixture();
    $stale = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scope_type' => 'chapter', 'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::Rewrite, 'status' => RunStatus::Running,
        'updated_at' => now()->subSeconds((int) config('generation.stalled_run_after_seconds') + 1),
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(rewriteResponse()));

    app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect($stale->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->fresh()->error_code)->toBe('worker_interrupted');
});

test('state version change during rewrite prevents artifact persistence', function () {
    $fixture = rewriteFixture();
    app()->instance(AiProvider::class, new class($fixture['novel']) implements AiProvider
    {
        public function __construct(private Novel $novel) {}

        public function generate(AiRequest $request): AiResponse
        {
            $nextVersion = (int) $this->novel->storyStateVersions()->max('version') + 1;
            $version = StoryStateVersion::factory()->for($this->novel)->create(['version' => $nextVersion]);
            $this->novel->update(['canonical_state_version_id' => $version->getKey()]);

            return rewriteResponse();
        }
    });

    expect(fn () => app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, 'Story State 已变化');
    expect(GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)->count())->toBe(0);
});
