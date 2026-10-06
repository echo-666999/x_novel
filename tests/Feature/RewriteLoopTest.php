<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
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
use App\Models\Character;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Review;
use App\Models\Scene;
use App\Services\ChapterRewriter;
use App\Services\OutlineProgressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function rewriteFixture(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Rewrite]);
    $pov = Character::factory()->for($novel)->create();
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'target_words' => 8,
        'pov_character_id' => $pov->getKey(),
        'status' => PlanStatus::Ready,
    ]);
    $target = app(OutlineProgressResolver::class)->resolve($novel->fresh());
    $plan->update([
        'arc_contributions' => [[
            'role' => 'primary',
            'arc_id' => $target->arcId,
            'beat_key' => $target->beat['key'],
            'beat_index' => $target->beat['sequence'],
            'milestone_key' => $target->milestone['key'],
            'milestone_sequence' => $target->milestone['sequence'],
            'target_scene_sequence' => 1,
            'acceptance_criteria' => $target->milestone['acceptance_criteria'][0],
        ]],
        'must_reveal' => $target->milestone['must_include'],
        'must_not_reveal' => array_values(array_unique([
            ...$target->beat['must_not_include'],
            ...$target->milestone['must_not_include'],
        ])),
    ]);
    $assembly = GenerationRun::factory()->for($novel)->for($chapter)->create(['scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded]);
    $draft = GenerationArtifact::factory()->for($assembly)->create(['type' => ArtifactType::ChapterDraft, 'content' => '原始章节正文', 'checksum' => hash('sha256', '原始章节正文')]);
    $reviewRun = GenerationRun::factory()->for($novel)->for($chapter)->create(['scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create(['type' => ArtifactType::ReviewResult]);
    $review = Review::factory()->create(['generation_run_id' => $reviewRun->getKey(), 'artifact_id' => $reviewArtifact->getKey(), 'decision' => ReviewDecision::Rewrite, 'findings' => [['dimension' => 'pacing', 'severity' => 'error', 'message' => '结尾节奏拖沓', 'evidence' => '末段重复']]]);

    return compact('novel', 'chapter', 'draft', 'review');
}

function rewriteResponse(string $content = '修订后的章节正文'): AiResponse
{
    $data = ['content' => $content, 'scene_coverage' => [], 'introduced_major_facts' => []];

    return new AiResponse(content: json_encode($data, JSON_UNESCAPED_UNICODE), structuredData: $data, inputTokens: 100, outputTokens: 100, cachedTokens: 0, latencyMs: 100, providerRequestId: 'rewrite', model: 'rewrite-test');
}

function truncatedRewriteResponse(): AiResponse
{
    return new AiResponse(
        content: '',
        structuredData: null,
        inputTokens: 100,
        outputTokens: 100,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'truncated-rewrite-request',
        model: 'rewrite-test',
        metadata: ['finish_reason' => 'length', 'refusal' => null, 'completion_limit_reason' => 'visible_output_truncated'],
    );
}

/** @param array<int, int>|null $sceneReferences */
function chapterRewriteCoverageResponse(Chapter $chapter, string $content = '修订后的完整章节', ?array $sceneReferences = null): AiResponse
{
    $fulfilled = ['status' => 'fulfilled', 'evidence' => $content];
    $data = [
        'content' => $content,
        'scene_coverage' => $chapter->scenes()->orderBy('sequence')->get()->values()->map(fn (Scene $scene, int $index): array => [
            'scene_id' => $sceneReferences[$index] ?? $scene->getKey(),
            'goal' => $fulfilled,
            'conflict' => $fulfilled,
            'turn' => $fulfilled,
            'outcome' => $fulfilled,
            'foreshadowing_coverage' => [],
        ])->all(),
        'introduced_major_facts' => [],
    ];

    return new AiResponse(content: json_encode($data, JSON_UNESCAPED_UNICODE), structuredData: $data, inputTokens: 100, outputTokens: 100, cachedTokens: 0, latencyMs: 100, providerRequestId: 'rewrite', model: 'rewrite-test');
}

/** @param array<int, array{search: string, replacement: string}> $edits */
function rewriteLengthPatchResponse(array $edits): AiResponse
{
    $data = ['edits' => $edits];

    return new AiResponse(
        content: json_encode($data, JSON_UNESCAPED_UNICODE),
        structuredData: $data,
        inputTokens: 50,
        outputTokens: 50,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'rewrite-length-patch',
        model: 'rewrite-test',
    );
}

function sceneRewriteResponse(string $content = '破门逆转', string $evidence = '破门'): AiResponse
{
    $selfCheck = collect(['goal', 'conflict', 'turn', 'outcome'])
        ->mapWithKeys(fn (string $element): array => [$element => [
            'status' => 'fulfilled',
            'evidence' => $evidence,
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

function sceneRewriteCoverageResponse(string $evidence): AiResponse
{
    $coverage = collect(['goal', 'conflict', 'turn', 'outcome'])
        ->mapWithKeys(fn (string $element): array => [$element => [
            'status' => 'fulfilled',
            'evidence' => $evidence,
        ]])
        ->all();

    return new AiResponse(
        content: json_encode($coverage, JSON_UNESCAPED_UNICODE),
        structuredData: $coverage,
        inputTokens: 50,
        outputTokens: 50,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'rewrite-coverage-repair',
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
    $fixture['draft'] = GenerationArtifact::factory()->for($fixture['draft']->generationRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'version' => 2,
        'content' => '原始章节正文',
        'data' => ['ordered_scene_checksums' => collect($scenes)->pluck('artifact.checksum')->all()],
        'checksum' => hash('sha256', '原始章节正文'),
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($fixture['review']->generationRun)->create([
        'type' => ArtifactType::ReviewResult,
        'version' => 2,
        'data' => ['source_artifact_id' => $fixture['draft']->getKey()],
    ]);
    $fixture['review']->update(['artifact_id' => $reviewArtifact->getKey()]);

    return [...$fixture, 'target' => $scenes[0], 'untouched' => $scenes[1]];
}

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
        ->and($artifact->data['foreshadowing_coverage'])->toBe([])
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

test('scene rewrite repairs invalid coverage evidence without rewriting its content', function () {
    $fixture = sceneRewriteFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(sceneRewriteResponse(evidence: '他成功破门'))
        ->enqueue(sceneRewriteCoverageResponse('破门'));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(ChapterRewriter::class)->rewrite(
        $fixture['chapter']->getKey(),
        $fixture['target']['scene']->getKey(),
    );

    expect($artifact->content)->toBe('破门逆转')
        ->and(data_get($artifact->data, 'self_check.outcome.evidence'))->toBe('破门')
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->promptVersion)->toBe('coverage-evidence-repair-v1')
        ->and(data_get($fake->requests()[1]->metadata, 'coverage_path'))->toBe('self_check');
});

test('rewrite job checkpoints a paid response and resumes repair in a new job attempt', function () {
    Queue::fake();
    $fixture = sceneRewriteFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(sceneRewriteResponse(evidence: '他成功破门'))
        ->enqueue(truncatedRewriteResponse())
        ->enqueue(sceneRewriteCoverageResponse('破门'));
    app()->instance(AiProvider::class, $fake);

    (new RewriteChapterJob(
        $fixture['chapter']->getKey(),
        $fixture['target']['scene']->getKey(),
    ))->handle(app(ChapterRewriter::class));

    $firstRun = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::Rewrite)->sole();
    expect($fake->requests())->toHaveCount(1)
        ->and($firstRun->status)->toBe(RunStatus::Failed)
        ->and($firstRun->error_code)->toBe('generation_stage_deferred')
        ->and($firstRun->artifacts()->where('type', ArtifactType::Context)->count())->toBe(1);
    Queue::assertPushed(RewriteChapterJob::class, 1);

    (new RewriteChapterJob(
        $fixture['chapter']->getKey(),
        $fixture['target']['scene']->getKey(),
    ))->handle(app(ChapterRewriter::class));

    expect($fake->requests())->toHaveCount(2)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::Rewrite)->latest('id')->first()->error_code)->toBe('generation_stage_deferred');

    (new RewriteChapterJob(
        $fixture['chapter']->getKey(),
        $fixture['target']['scene']->getKey(),
    ))->handle(app(ChapterRewriter::class));

    expect($fake->requests())->toHaveCount(3)
        ->and(data_get($fake->requests()[1]->metadata, 'coverage_repair_attempt'))->toBe(1)
        ->and(data_get($fake->requests()[2]->metadata, 'coverage_repair_attempt'))->toBe(2)
        ->and($fixture['target']['scene']->fresh()->currentArtifact?->type)->toBe(ArtifactType::RewriteDraft)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::Rewrite)->count())->toBe(3);
    Queue::assertPushed(AssembleChapterJob::class, 1);
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

test('paused novel cannot start rewrite', function () {
    $fixture = rewriteFixture();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);
    app()->instance(AiProvider::class, new FakeAiProvider);

    expect(fn () => app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');
});

test('chapter scoped finding is rejected without a provider call and requires plan or scene rebuild', function () {
    $fixture = rewriteFixture();
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '重建 Plan/Scene');
    expect($fake->requests())->toHaveCount(0);
});

test('paragraph patch must uniquely match the target scene and never mutates the chapter draft', function () {
    $fixture = sceneRewriteFixture();
    $fixture['review']->update(['findings' => [[
        'code' => 'STYLE_MISMATCH', 'dimension' => 'style', 'severity' => 'error',
        'scene_id' => $fixture['target']['scene']->getKey(), 'scope' => 'paragraph',
        'auto_fixable' => true, 'requires_human_decision' => false,
        'message' => '替换失速表达。', 'evidence' => '旧稿失速',
    ]]]);
    $coverage = collect(['goal', 'conflict', 'turn', 'outcome'])->mapWithKeys(fn (string $key): array => [$key => [
        'status' => 'fulfilled', 'evidence' => '破门逆转',
    ]])->all();
    $response = new AiResponse(
        content: '',
        structuredData: ['search' => '旧稿失速', 'replacement' => '破门逆转', 'self_check' => $coverage],
        inputTokens: 100, outputTokens: 50, cachedTokens: 0, latencyMs: 20,
        providerRequestId: 'paragraph-patch', model: 'rewrite-test',
    );
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue($response));

    $artifact = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey(), $fixture['target']['scene']->getKey());

    expect($artifact->data['scope'])->toBe('paragraph')
        ->and($artifact->content)->toBe('破门逆转')
        ->and(data_get($artifact->data, 'paragraph_patch.search'))->toBe('旧稿失速')
        ->and($fixture['target']['scene']->fresh()->current_artifact_id)->toBe($artifact->getKey())
        ->and($fixture['untouched']['scene']->fresh()->current_artifact_id)->toBe($fixture['untouched']['artifact']->getKey())
        ->and($fixture['draft']->fresh()->content)->toBe('原始章节正文');
});

test('paragraph patch rejects zero or multiple source matches', function (string $source, string $search) {
    $fixture = sceneRewriteFixture();
    if ($source !== $fixture['target']['artifact']->content) {
        $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
            'scene_id' => $fixture['target']['scene']->getKey(), 'scope_type' => 'scene',
            'scope_id' => $fixture['target']['scene']->getKey(), 'stage' => GenerationStage::SceneGeneration,
            'status' => RunStatus::Succeeded,
        ]);
        $sourceArtifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft, 'content' => $source, 'checksum' => hash('sha256', $source),
        ]);
        $fixture['target']['scene']->update(['current_artifact_id' => $sourceArtifact->getKey()]);
    }
    $fixture['review']->update(['findings' => [[
        'code' => 'STYLE_MISMATCH', 'dimension' => 'style', 'severity' => 'error',
        'scene_id' => $fixture['target']['scene']->getKey(), 'scope' => 'paragraph',
        'auto_fixable' => true, 'requires_human_decision' => false,
        'message' => '替换失速表达。', 'evidence' => '旧稿失速',
    ]]]);
    $coverage = collect(['goal', 'conflict', 'turn', 'outcome'])->mapWithKeys(fn (string $key): array => [$key => [
        'status' => 'missing', 'evidence' => null,
    ]])->all();
    $response = new AiResponse(content: '', structuredData: [
        'search' => $search, 'replacement' => '修复文本', 'self_check' => $coverage,
    ], inputTokens: 10, outputTokens: 10, cachedTokens: 0, latencyMs: 10, providerRequestId: 'patch-invalid', model: 'rewrite-test');
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue($response));

    expect(fn () => app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey(), $fixture['target']['scene']->getKey()))
        ->toThrow(AiProviderException::class, '唯一命中一次');
})->with([
    'zero match' => ['旧稿失速', '不存在证据'],
    'multiple matches' => ['旧稿失速。重复证据，重复证据', '重复证据'],
]);

test('multiple scene findings resolve to the earliest affected scene', function () {
    $fixture = sceneRewriteFixture();
    $fixture['review']->update(['findings' => [
        [
            'code' => 'STYLE_MISMATCH', 'dimension' => 'style', 'severity' => 'error',
            'scene_id' => $fixture['untouched']['scene']->getKey(), 'scope' => 'scene',
            'auto_fixable' => true, 'requires_human_decision' => false, 'message' => '第二场。', 'evidence' => '守军待命',
        ],
        [
            'code' => 'PACING_ISSUE', 'dimension' => 'pacing', 'severity' => 'error',
            'scene_id' => $fixture['target']['scene']->getKey(), 'scope' => 'scene',
            'auto_fixable' => true, 'requires_human_decision' => false, 'message' => '第一场。', 'evidence' => '旧稿失速',
        ],
    ]]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(sceneRewriteResponse()));

    $artifact = app(ChapterRewriter::class)->rewrite($fixture['chapter']->getKey());

    expect($artifact->generationRun->scene_id)->toBe($fixture['target']['scene']->getKey())
        ->and($fixture['untouched']['scene']->fresh()->current_artifact_id)->toBe($fixture['untouched']['artifact']->getKey());
});
