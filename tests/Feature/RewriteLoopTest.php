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
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\RewriteChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\StoryStateVersion;
use App\Services\ChapterRewriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function rewriteFixture(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Rewrite]);
    ChapterPlan::factory()->for($chapter)->create();
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

test('chapter rewrite creates a new immutable artifact with finding hash', function () {
    $fixture = rewriteFixture();
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(rewriteResponse()));

    $artifact = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect($artifact->type)->toBe(ArtifactType::RewriteDraft)
        ->and($artifact->version)->toBe(1)
        ->and($artifact->content)->toBe('修订后的章节正文')
        ->and($artifact->data['source_artifact_id'])->toBe($fixture['draft']->getKey())
        ->and($artifact->data['source_review_id'])->toBe($fixture['review']->getKey())
        ->and($artifact->data['finding_hash'])->toHaveLength(64)
        ->and($artifact->generationRun->idempotency_key)->toStartWith('rewrite:'.$fixture['draft']->getKey().':');
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

    Queue::assertPushed(ExtractStoryEventsJob::class, fn (ExtractStoryEventsJob $job): bool => $job->regenerate && $job->continueRewrite);
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
            'severity' => 'ambiguous',
            'message' => '自动 Rewrite 已达到最大 2 次，需要人工处理。',
            'source' => 'rewrite_loop',
        ])
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review);
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

test('stale rewrite run is marked interrupted before recovery', function () {
    $fixture = rewriteFixture();
    $stale = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scope_type' => 'chapter', 'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::Rewrite, 'status' => RunStatus::Running,
        'updated_at' => now()->subMinutes(3),
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
            $version = StoryStateVersion::factory()->for($this->novel)->create(['version' => 1]);
            $this->novel->update(['canonical_state_version_id' => $version->getKey()]);

            return rewriteResponse();
        }
    });

    expect(fn () => app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, 'Story State 已变化');
    expect(GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)->count())->toBe(0);
});
