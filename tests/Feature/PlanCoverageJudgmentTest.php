<?php

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\AdjudicatePlanCoverageJob;
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
use App\Services\ChapterRewriter;
use App\Services\PlanCoverageJudgmentRepairer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * 建立含一个待复核 Coverage Finding 的最小真实来源链。
 *
 * @return array{novel: Novel, chapter: Chapter, scene: Scene, draft: GenerationArtifact, finding: array<string, mixed>, content: string}
 */
function planCoverageJudgmentFixture(string $findingCode = 'SCENE_PLAN_COVERAGE_EVIDENCE_UNVERIFIED'): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'scene_plans' => [[
            'goal' => '进入灯塔大厅',
            'conflict' => '守卫拔刀阻拦',
            'turn' => '熄灭火把绕行',
            'outcome' => '抵达大厅',
            'outcome_allowed' => ['抵达大厅'],
            'outcome_forbidden' => ['取得灯塔控制权'],
            'continuity_requirements' => [],
            'transition_from_previous' => null,
        ]],
    ]);
    freezeChapterRouteContractsForTest($plan);
    $scene = Scene::factory()->for($chapter)->create([
        'sequence' => 1,
        'goal' => '进入灯塔大厅',
        'conflict' => '守卫拔刀阻拦',
        'turn' => '熄灭火把绕行',
        'outcome' => '抵达大厅',
        'status' => SceneStatus::Draft,
    ]);
    $content = '林舟推开灯塔大门，守卫拔刀拦路。他熄灭火把绕到侧门，最终进入大厅。';
    $sceneRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => $scene->getKey(),
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $sceneArtifact = GenerationArtifact::factory()->for($sceneRun)->create([
        'type' => ArtifactType::SceneDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $scene->update(['current_artifact_id' => $sceneArtifact->getKey()]);
    $coverage = [
        'goal' => ['status' => 'missing', 'evidence' => null],
        'conflict' => ['status' => 'fulfilled', 'evidence' => '守卫拔刀拦路'],
        'turn' => ['status' => 'fulfilled', 'evidence' => '他熄灭火把绕到侧门'],
        'outcome' => ['status' => 'fulfilled', 'evidence' => '最终进入大厅'],
    ];
    $finding = [
        'code' => $findingCode,
        'dimension' => 'plan',
        'severity' => $findingCode === 'SCENE_PLAN_COVERAGE_EVIDENCE_UNVERIFIED' ? 'ambiguous' : 'error',
        'scene_id' => $scene->getKey(),
        'scope' => 'scene',
        'auto_fixable' => $findingCode !== 'SCENE_PLAN_COVERAGE_EVIDENCE_UNVERIFIED',
        'requires_human_decision' => false,
        'plan_element' => 'goal',
        'coverage_status' => $findingCode === 'SCENE_PLAN_COVERAGE_EVIDENCE_UNVERIFIED' ? 'unverified' : 'missing',
        'expected' => '进入灯塔大厅',
        'evidence' => null,
        'message' => '目标需要复核。',
        'source' => 'assembly_coverage',
    ];
    $assemblyRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
        'state_version' => $novel->fresh()->canonicalStateVersion->version,
    ]);
    $draft = GenerationArtifact::factory()->for($assemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $content,
        'data' => [
            'scene_coverage' => [[
                'scene_id' => $scene->getKey(),
                ...$coverage,
                'coverage_evidence_unverified' => [[
                    'element' => 'goal',
                    'reported_status' => 'fulfilled',
                    'reported_evidence' => '林舟推开灯塔大门……最终进入大厅',
                ]],
                'foreshadowing_coverage' => [],
            ]],
            'plan_findings' => [$finding],
            'ordered_scene_checksums' => [$sceneArtifact->checksum],
        ],
        'checksum' => hash('sha256', $content),
    ]);

    return compact('novel', 'chapter', 'scene', 'draft', 'finding', 'content');
}

/** 返回可通过逐字证据校验的 Coverage Judgment 响应。 */
function planCoverageJudgmentResponse(string $goalStatus = 'fulfilled'): AiResponse
{
    $coverage = [
        'goal' => ['status' => $goalStatus, 'evidence' => $goalStatus === 'missing' ? null : '最终进入大厅'],
        'conflict' => ['status' => 'fulfilled', 'evidence' => '守卫拔刀拦路'],
        'turn' => ['status' => 'fulfilled', 'evidence' => '他熄灭火把绕到侧门'],
        'outcome' => ['status' => 'fulfilled', 'evidence' => '最终进入大厅'],
    ];

    return new AiResponse(
        content: json_encode($coverage),
        structuredData: $coverage,
        inputTokens: 100,
        outputTokens: 80,
        cachedTokens: 0,
        latencyMs: 50,
        providerRequestId: 'coverage-judgment-request',
        model: 'review-test',
    );
}

/** 为回归测试保存一个早于 Judgment 的错误 REWRITE Review。 */
function createLegacyCoverageRewriteReview(array $fixture): Review
{
    $reviewRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create([
        'type' => ArtifactType::ReviewResult,
        'data' => [
            'source_artifact_id' => $fixture['draft']->getKey(),
            'findings' => [$fixture['finding']],
        ],
    ]);
    $review = Review::factory()->create([
        'generation_run_id' => $reviewRun->getKey(),
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Rewrite,
        'findings' => [$fixture['finding']],
    ]);
    $fixture['chapter']->update(['status' => ChapterStatus::Rewrite]);

    return $review;
}

test('pipeline dispatches independent coverage judgment before event extraction', function () {
    Queue::fake();
    $fixture = planCoverageJudgmentFixture();

    $stage = app(AdvanceChapterPipelineAction::class)->handle($fixture['chapter']->getKey());

    expect($stage)->toBe(GenerationStage::CoverageJudgment);
    Queue::assertPushed(AdjudicatePlanCoverageJob::class, function (AdjudicatePlanCoverageJob $job) use ($fixture): bool {
        return $job->chapterId === $fixture['chapter']->getKey()
            && $job->draftArtifactId === $fixture['draft']->getKey()
            && $job->sceneId === $fixture['scene']->getKey();
    });
});

test('fulfilled coverage judgment removes false rewrite source and reuses its artifact', function () {
    $fixture = planCoverageJudgmentFixture();
    $fake = (new FakeAiProvider)->enqueue(planCoverageJudgmentResponse());
    app()->instance(AiProvider::class, $fake);
    $repairer = app(PlanCoverageJudgmentRepairer::class);

    $artifact = $repairer->judge($fixture['chapter']->getKey(), $fixture['draft']->getKey(), $fixture['scene']->getKey());
    $reused = $repairer->judge($fixture['chapter']->getKey(), $fixture['draft']->getKey(), $fixture['scene']->getKey());
    $resolved = $repairer->resolveFindings($fixture['chapter'], $fixture['draft'], [$fixture['finding']]);
    $run = $artifact?->generationRun;

    expect($artifact?->getKey())->toBe($reused?->getKey())
        ->and($resolved)->toBe([])
        ->and($fake->requests())->toHaveCount(1)
        ->and($run?->stage)->toBe(GenerationStage::CoverageJudgment)
        ->and($run?->provider)->not->toBeEmpty()
        ->and($run?->model_policy)->not->toBeEmpty()
        ->and(data_get($run?->context_snapshot, 'generation_preferences.frozen_route'))->toHaveKey('reasoning_effort')
        ->and(data_get($run?->context_snapshot, 'generation_preferences.model_capacity.max_output_tokens'))->toBeGreaterThan(0)
        // Provider 实际上限必须包含 1,500 可见输出和 8,000 推理预留。
        ->and(data_get($run?->context_snapshot, 'generation_preferences.selected_request_budget.output_tokens'))->toBe(1_500)
        ->and(data_get($run?->context_snapshot, 'generation_preferences.selected_request_budget.reasoning_reserve_tokens'))->toBe(8_000)
        ->and(data_get($run?->context_snapshot, 'generation_preferences.selected_request_budget.max_completion_tokens'))->toBe(9_500)
        ->and(data_get($run?->context_snapshot, 'generation_preferences.provider_requests.0.max_completion_tokens'))->toBe(9_500);
});

test('legacy rewrite review is ignored after current coverage judgment', function () {
    $fixture = planCoverageJudgmentFixture('SCENE_PLAN_COVERAGE_MISSING');
    createLegacyCoverageRewriteReview($fixture);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(planCoverageJudgmentResponse()));
    app(PlanCoverageJudgmentRepairer::class)->judge(
        $fixture['chapter']->getKey(),
        $fixture['draft']->getKey(),
        $fixture['scene']->getKey(),
    );
    Queue::fake();

    $stage = app(AdvanceChapterPipelineAction::class)->handle($fixture['chapter']->getKey());

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review)
        ->and($stage)->toBe(GenerationStage::EventExtraction);
    Queue::assertPushed(ExtractStoryEventsJob::class);
    Queue::assertNotPushed(RewriteChapterJob::class);
});

test('rewrite job routes legacy coverage review back to judgment without provider request', function () {
    $fixture = planCoverageJudgmentFixture('SCENE_PLAN_COVERAGE_MISSING');
    createLegacyCoverageRewriteReview($fixture);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);
    Queue::fake();

    (new RewriteChapterJob($fixture['chapter']->getKey(), $fixture['scene']->getKey()))
        ->handle(app(ChapterRewriter::class));

    Queue::assertPushed(AdjudicatePlanCoverageJob::class);
    expect($fake->requests())->toBe([])
        ->and(GenerationRun::query()->where('stage', GenerationStage::Rewrite)->count())->toBe(0)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)->count())->toBe(0);
});

test('only judgment confirmed missing coverage becomes rewrite finding', function () {
    $fixture = planCoverageJudgmentFixture('SCENE_PLAN_COVERAGE_MISSING');
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(planCoverageJudgmentResponse('missing')));
    $repairer = app(PlanCoverageJudgmentRepairer::class);

    $artifact = $repairer->judge($fixture['chapter']->getKey(), $fixture['draft']->getKey(), $fixture['scene']->getKey());
    $resolved = $repairer->resolveFindings($fixture['chapter'], $fixture['draft'], [$fixture['finding']]);

    expect($resolved)->toHaveCount(1)
        ->and(data_get($resolved, '0.code'))->toBe('SCENE_PLAN_COVERAGE_MISSING')
        ->and(data_get($resolved, '0.source'))->toBe('coverage_judgment')
        ->and(data_get($resolved, '0.coverage_judgment_artifact_id'))->toBe($artifact?->getKey())
        ->and(data_get($resolved, '0.auto_fixable'))->toBeTrue();
});

test('coverage judgment technical failure persists failed run without review or rewrite', function () {
    $fixture = planCoverageJudgmentFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(new AiProviderException('provider_timeout', 'Provider timeout.', true))
        ->enqueue(planCoverageJudgmentResponse());
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(PlanCoverageJudgmentRepairer::class)->judge(
        $fixture['chapter']->getKey(),
        $fixture['draft']->getKey(),
        $fixture['scene']->getKey(),
    ))->toThrow(AiProviderException::class, 'Provider timeout.');

    $run = GenerationRun::query()->where('stage', GenerationStage::CoverageJudgment)->sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error_code)->toBe('provider_timeout')
        ->and(data_get($run->error_metadata, 'category'))->toBe('external_temporary')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review)
        ->and(Review::query()->count())->toBe(0)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)->count())->toBe(0)
        ->and($fake->requests())->toHaveCount(1);

    $artifact = app(PlanCoverageJudgmentRepairer::class)->judge(
        $fixture['chapter']->getKey(),
        $fixture['draft']->getKey(),
        $fixture['scene']->getKey(),
    );

    expect($artifact)->not->toBeNull()
        ->and(GenerationRun::query()->where('stage', GenerationStage::CoverageJudgment)->count())->toBe(2)
        ->and($artifact?->generationRun?->attempt)->toBe(2)
        ->and($artifact?->generationRun?->idempotency_key)->toEndWith(':attempt:2')
        ->and(data_get($artifact?->generationRun?->context_snapshot, 'generation_preferences.frozen_route'))
        ->toBe(data_get($run->context_snapshot, 'generation_preferences.frozen_route'));
});

test('coverage judgment reasoning exhaustion retries with the next frozen repair budget', function () {
    $fixture = planCoverageJudgmentFixture();
    $truncated = new AiResponse(
        content: '{"goal":',
        structuredData: null,
        inputTokens: 1_000,
        outputTokens: 9_500,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'coverage-judgment-truncated',
        model: 'review-test',
        metadata: ['finish_reason' => 'length'],
        reasoningTokens: 9_000,
    );
    $fake = (new FakeAiProvider)
        ->enqueue($truncated)
        ->enqueue(planCoverageJudgmentResponse());
    app()->instance(AiProvider::class, $fake);
    $repairer = app(PlanCoverageJudgmentRepairer::class);

    try {
        $repairer->judge($fixture['chapter']->getKey(), $fixture['draft']->getKey(), $fixture['scene']->getKey());
        $this->fail('首次推理预算耗尽必须抛出可重试异常。');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('coverage_judgment_reasoning_budget_exhausted')
            ->and($exception->retryable)->toBeTrue();
    }

    $artifact = $repairer->judge($fixture['chapter']->getKey(), $fixture['draft']->getKey(), $fixture['scene']->getKey());
    $runs = GenerationRun::query()->where('stage', GenerationStage::CoverageJudgment)->orderBy('id')->get();

    expect($artifact)->not->toBeNull()
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[0]->maxTokens)->toBe(9_500)
        ->and($fake->requests()[1]->maxTokens)->toBe(19_000)
        ->and(data_get($runs[0]->context_snapshot, 'generation_preferences.request_budget_tier'))->toBe('initial')
        ->and(data_get($runs[1]->context_snapshot, 'generation_preferences.request_budget_tier'))->toBe('retry')
        ->and(data_get($runs[1]->context_snapshot, 'generation_preferences.provider_requests.0.reasoning_reserve_tokens'))->toBe(16_000);
});
