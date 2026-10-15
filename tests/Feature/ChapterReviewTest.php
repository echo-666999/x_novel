<?php

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\JsonSchemaValidator;
use App\AI\Providers\FakeAiProvider;
use App\Data\StateFinding;
use App\Data\StateValidationResult;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\ContentRepairType;
use App\Enums\EventType;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\StateFindingSeverity;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Review;
use App\Models\Scene;
use App\Models\StoryArc;
use App\Models\StoryStateVersion;
use App\Services\ArcCompletionAuditRepairer;
use App\Services\ChapterReviewer;
use App\Services\CompactReviewPayload;
use App\Services\GenerationSettingsService;
use App\Services\OutlineCompletionService;
use App\Services\PlanningReviewAudit;
use App\Services\RewriteScopeResolver;
use App\Services\StateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function reviewFixture(?Closure $draftDataFactory = null): array
{
    $content = '林舟守住城门，也兑现了向同伴作出的承诺。';
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['generation' => ['chapter_target_words' => mb_strlen($content)]],
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    $plan = ChapterPlan::factory()->for($chapter)->create(['target_words' => mb_strlen($content)]);
    freezeChapterRouteContractsForTest($plan);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create(['scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded]);
    $draft = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $content,
        'data' => $draftDataFactory?->__invoke($chapter) ?? [],
        'checksum' => hash('sha256', $content),
    ]);

    return compact('novel', 'chapter', 'draft');
}

function reviewResponse(string $decision = 'PASS', int $score = 90, array $findings = [], ?array $dimensionAudits = null, array $foreshadowingAudits = []): AiResponse
{
    $dimensionAudits ??= collect(['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'])
        ->mapWithKeys(function (string $dimension) use ($findings): array {
            $hasFindings = collect($findings)->contains(fn (array $finding): bool => ($finding['dimension'] ?? null) === $dimension);

            return [$dimension => [
                'status' => $hasFindings ? 'issues_found' : 'pass',
                'summary' => $hasFindings ? '已一次列出该维度发现的全部问题。' : '全量检查未发现需要报告的问题。',
            ]];
        })
        ->all();
    $data = [
        'recommended_decision' => $decision,
        'scores' => ['continuity' => $score, 'plan' => $score, 'character' => $score, 'progress' => $score, 'repetition' => $score, 'pacing' => $score, 'style' => $score],
        'dimension_audits' => $dimensionAudits,
        'foreshadowing_audits' => $foreshadowingAudits,
        'chapter_plan_completion' => ['status' => 'fulfilled', 'evidence' => '林舟守住城门', 'scene_id' => null],
        'milestone_completion' => ['status' => 'not_met', 'criteria' => [[
            'criterion' => '完整父链存在。', 'status' => 'not_met', 'evidence' => null, 'scene_id' => null,
        ]]],
        'beat_exit' => ['status' => 'not_met', 'criteria' => [[
            'criterion' => '计划引用已保存。', 'status' => 'not_met', 'evidence' => null, 'scene_id' => null,
        ]]],
        'handoff_readiness' => ['status' => 'not_applicable', 'checks' => []],
        'arc_beat_audits' => [],
        'arc_completion_audits' => [],
        'character_candidate_audits' => [],
        'world_entity_candidate_audits' => [],
        'unapproved_characters' => [],
        'unapproved_world_entities' => [],
        'findings' => $findings,
    ];

    return new AiResponse(content: json_encode($data), structuredData: $data, inputTokens: 100, outputTokens: 80, cachedTokens: 0, latencyMs: 100, providerRequestId: 'review-request', model: 'review-test');
}

/** @return array<string, mixed> */
function outlineCompletionReviewDefaults(Chapter $chapter): array
{
    $contract = app(OutlineCompletionService::class)->contract($chapter);

    return [
        'chapter_plan_completion' => ['status' => 'fulfilled', 'evidence' => '林舟守住城门', 'scene_id' => null],
        'milestone_completion' => ['status' => 'not_met', 'criteria' => collect($contract['milestone_criteria'])->map(fn (string $criterion): array => ['criterion' => $criterion, 'status' => 'not_met', 'evidence' => null, 'scene_id' => null])->all()],
        'beat_exit' => ['status' => 'not_met', 'criteria' => collect($contract['beat_exit_criteria'])->map(fn (string $criterion): array => ['criterion' => $criterion, 'status' => 'not_met', 'evidence' => null, 'scene_id' => null])->all()],
        'handoff_readiness' => $contract['handoff_next_beat_id'] === null
            ? ['status' => 'not_applicable', 'checks' => []]
            : ['status' => 'not_ready', 'checks' => collect($contract['handoff_checks'])->map(fn (array $check): array => [
                ...$check, 'status' => 'not_met', 'evidence' => null, 'scene_id' => null,
            ])->all()],
    ];
}

function truncatedReviewResponse(int $outputTokens): AiResponse
{
    return new AiResponse(
        content: '',
        structuredData: null,
        inputTokens: 100,
        outputTokens: $outputTokens,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'truncated-review-request',
        model: 'review-test',
        metadata: ['finish_reason' => 'length', 'refusal' => null, 'completion_limit_reason' => 'visible_output_truncated'],
    );
}

test('reviewer uses one fixed legal output budget for compact review', function () {
    expect(app(GenerationSettingsService::class)->chapterRequestBudgets(AiStage::Reviewer))->toBe([
        'initial' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 8_000],
    ]);
});

test('review truncation is terminal when the frozen route has no higher budget', function () {
    setGenerationSettingsForTest([
        'chapter_request_budgets.reviewer.initial.output_tokens' => 4_000,
        'chapter_request_budgets.reviewer.initial.reasoning_reserve_tokens' => 0,
    ]);
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(truncatedReviewResponse(4_000));
    app()->instance(AiProvider::class, $fake);
    $reviewer = app(ChapterReviewer::class);

    try {
        $reviewer->review($fixture['chapter']->getKey());
        $this->fail('Expected Reviewer truncation.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('review_output_truncated')
            ->and($exception->retryable)->toBeFalse();
    }
    expect(Review::query()->count())->toBe(0)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)->count())->toBe(0)
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->maxTokens)->toBe(4_000);

    try {
        $reviewer->review($fixture['chapter']->getKey());
        $this->fail('Expected Reviewer truncation to remain terminal.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('review_output_truncated')->and($exception->retryable)->toBeFalse();
    }

    expect($fake->requests())->toHaveCount(1)
        ->and(Review::query()->count())->toBe(0)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)->count())->toBe(0);
});

test('planning review audits require verbatim evidence and surface missing or unapproved world data', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $plan = $fixture['chapter']->latestPlan;
    $arc = StoryArc::query()->where('source_outline_arc_id', $plan->primary_outline_arc_id)->firstOrFail();
    $beatKey = $plan->primaryOutlineBeat->beat_key;
    $fixture['chapter']->latestPlan->update([
        'arc_contributions' => [[
            'arc_id' => $arc->getKey(), 'beat_key' => $beatKey, 'beat_index' => 1,
            'target_scene_sequence' => 1, 'acceptance_criteria' => '正文明确守住城门。',
        ]],
        'world_entity_candidates' => [[
            'candidate_key' => 'wec-city-gate', 'type' => 'location', 'name' => '城门',
            'description' => '防守目标。', 'deduplication_basis' => '无同名地点。',
            'possible_duplicate_entity_ids' => [], 'introduction_reason' => '承载冲突。', 'target_scene_sequence' => 1,
        ]],
        'character_candidates' => [[
            'candidate_key' => 'character-guide', 'name' => '向导', 'role' => '向导', 'motivation' => '守住城门。',
            'profile' => [], 'personality' => [], 'abilities' => [], 'knowledge' => [],
            'deduplication_basis' => '无相同人物。', 'possible_duplicate_character_ids' => [],
            'introduction_reason' => '协助守城。', 'target_scene_sequence' => 1,
        ]],
    ]);
    $payload = [
        ...outlineCompletionReviewDefaults($fixture['chapter']),
        'arc_beat_audits' => [[
            'arc_id' => $arc->getKey(), 'beat_key' => $beatKey, 'status' => 'fulfilled',
            'evidence' => '守住城门', 'scene_id' => $scene->getKey(),
        ]],
        'arc_completion_audits' => [[
            'arc_id' => $arc->getKey(), 'status' => 'not_met', 'evidence' => null,
        ]],
        'character_candidate_audits' => [[
            'candidate_key' => 'character-guide', 'status' => 'missing', 'evidence' => null,
            'scene_id' => null,
        ]],
        'world_entity_candidate_audits' => [[
            'candidate_key' => 'wec-city-gate', 'status' => 'missing', 'evidence' => null,
            'scene_id' => null,
        ]],
        'unapproved_world_entities' => [[
            'name' => '黑塔', 'type' => 'location', 'evidence' => '城门', 'scene_id' => $scene->getKey(),
        ]],
        'unapproved_characters' => [[
            'name' => '陌生使者', 'evidence' => '林舟', 'scene_id' => $scene->getKey(),
        ]],
    ];

    $validated = app(PlanningReviewAudit::class)->validate($payload, $fixture['chapter']->fresh(), $fixture['draft']);
    $codes = collect(app(PlanningReviewAudit::class)->findings($validated))->pluck('code');

    expect($codes)->toContain(
        'CHARACTER_CANDIDATE_NOT_INTRODUCED',
        'UNAPPROVED_CHARACTER',
        'WORLD_ENTITY_CANDIDATE_NOT_INTRODUCED',
        'UNAPPROVED_WORLD_ENTITY',
    )
        ->and($codes)->not->toContain('ARC_BEAT_NOT_FULFILLED')
        ->and(data_get($validated, 'character_candidate_audits.0.scene_id'))->toBe($scene->getKey())
        ->and(data_get($validated, 'world_entity_candidate_audits.0.scene_id'))->toBe($scene->getKey());

    $payload['arc_beat_audits'][0]['evidence'] = '正文中不存在的证据';
    expect(fn () => app(PlanningReviewAudit::class)->validate($payload, $fixture['chapter']->fresh(), $fixture['draft']))
        ->toThrow(ValidationException::class, '正文逐字证据');
});

test('planning review binds a missing arc audit to its frozen target scene without accepting a wrong scene for fulfilled evidence', function () {
    $fixture = reviewFixture();
    $targetScene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $otherScene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 2]);
    $plan = $fixture['chapter']->latestPlan;
    $arc = StoryArc::query()->where('source_outline_arc_id', $plan->primary_outline_arc_id)->firstOrFail();
    $beatKey = $plan->primaryOutlineBeat->beat_key;
    $fixture['chapter']->latestPlan->update(['arc_contributions' => [[
        'arc_id' => $arc->getKey(),
        'beat_key' => $beatKey,
        'beat_index' => 1,
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文明确守住城门。',
    ]]]);
    $payload = [
        ...outlineCompletionReviewDefaults($fixture['chapter']),
        'arc_beat_audits' => [[
            'arc_id' => $arc->getKey(),
            'beat_key' => $beatKey,
            'status' => 'missing',
            'evidence' => null,
            'scene_id' => null,
        ]],
        'arc_completion_audits' => [[
            'arc_id' => $arc->getKey(),
            'status' => 'not_met',
            'evidence' => null,
        ]],
        'character_candidate_audits' => [],
        'world_entity_candidate_audits' => [],
        'unapproved_world_entities' => [],
        'unapproved_characters' => [],
    ];

    $validated = app(PlanningReviewAudit::class)->validate($payload, $fixture['chapter']->fresh(), $fixture['draft']);

    expect(data_get($validated, 'arc_beat_audits.0.scene_id'))->toBe($targetScene->getKey());

    $payload['arc_beat_audits'][0] = [
        'arc_id' => $arc->getKey(),
        'beat_key' => $beatKey,
        'status' => 'fulfilled',
        'evidence' => '守住城门',
        'scene_id' => $otherScene->getKey(),
    ];

    $downgraded = app(PlanningReviewAudit::class)->validate($payload, $fixture['chapter']->fresh(), $fixture['draft']);

    expect(data_get($downgraded, 'arc_beat_audits.0.status'))->toBe('missing')
        ->and(data_get($downgraded, 'arc_beat_audits.0.evidence'))->toBeNull()
        ->and(data_get($downgraded, 'arc_beat_audits.0.scene_id'))->toBe($targetScene->getKey());
});

test('planning review reuses an identical fulfilled outline criterion for the arc contribution', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create([
        'sequence' => 1,
        'current_artifact_id' => $fixture['draft']->getKey(),
    ]);
    $plan = $fixture['chapter']->latestPlan;
    $arc = StoryArc::query()->where('source_outline_arc_id', $plan->primary_outline_arc_id)->firstOrFail();
    $contract = app(OutlineCompletionService::class)->contract($fixture['chapter']);
    $criterion = $contract['milestone_criteria'][0];
    $plan->update(['arc_contributions' => [[
        'arc_id' => $arc->getKey(),
        'beat_key' => $plan->primaryOutlineBeat->beat_key,
        'beat_index' => 1,
        'target_scene_sequence' => 1,
        'acceptance_criteria' => $criterion,
    ]]]);
    $payload = [
        ...outlineCompletionReviewDefaults($fixture['chapter']),
        'arc_beat_audits' => [[
            'arc_id' => $arc->getKey(),
            'beat_key' => $plan->primaryOutlineBeat->beat_key,
            'status' => 'missing',
            'evidence' => null,
            'scene_id' => $scene->getKey(),
        ]],
        'arc_completion_audits' => [[
            'arc_id' => $arc->getKey(),
            'status' => 'not_met',
            'evidence' => null,
        ]],
        'character_candidate_audits' => [],
        'world_entity_candidate_audits' => [],
        'unapproved_characters' => [],
        'unapproved_world_entities' => [],
    ];
    $payload['milestone_completion']['criteria'][0] = [
        'criterion' => $criterion,
        'status' => 'fulfilled',
        'evidence' => '林舟守住城门',
        'scene_id' => $scene->getKey(),
    ];
    $payload['milestone_completion']['status'] = collect($payload['milestone_completion']['criteria'])
        ->every(fn (array $audit): bool => $audit['status'] === 'fulfilled') ? 'fulfilled' : 'not_met';

    $validated = app(PlanningReviewAudit::class)->validate(
        $payload,
        $fixture['chapter']->fresh(['latestPlan', 'scenes']),
        $fixture['draft'],
    );

    expect(data_get($validated, 'arc_beat_audits.0.status'))->toBe('fulfilled')
        ->and(data_get($validated, 'arc_beat_audits.0.evidence'))->toBe('林舟守住城门')
        ->and(data_get($validated, 'arc_beat_audits.0.scene_id'))->toBe($scene->getKey());
});

test('planning review audits normalize quoted evidence with an omission marker to a verbatim excerpt', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $plan = $fixture['chapter']->latestPlan;
    $arc = StoryArc::query()->where('source_outline_arc_id', $plan->primary_outline_arc_id)->firstOrFail();
    $beatKey = $plan->primaryOutlineBeat->beat_key;
    $fixture['chapter']->latestPlan->update(['arc_contributions' => [[
        'arc_id' => $arc->getKey(),
        'beat_key' => $beatKey,
        'beat_index' => 1,
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文明确守住城门。',
    ]]]);
    $payload = [
        ...outlineCompletionReviewDefaults($fixture['chapter']),
        'arc_beat_audits' => [[
            'arc_id' => $arc->getKey(),
            'beat_key' => $beatKey,
            'status' => 'fulfilled',
            'evidence' => '“林舟守住城门……向同伴作出的承诺。”',
            'scene_id' => $scene->getKey(),
        ]],
        'arc_completion_audits' => [[
            'arc_id' => $arc->getKey(),
            'status' => 'not_met',
            'evidence' => null,
        ]],
        'character_candidate_audits' => [],
        'world_entity_candidate_audits' => [],
        'unapproved_world_entities' => [],
        'unapproved_characters' => [],
    ];

    $validated = app(PlanningReviewAudit::class)->validate($payload, $fixture['chapter']->fresh(), $fixture['draft']);

    expect(data_get($validated, 'arc_beat_audits.0.evidence'))
        ->toBe('向同伴作出的承诺。');
});

test('arc completion repair retries a previously failed provider request instead of reusing it', function () {
    $fixture = reviewFixture();
    $fixture['chapter']->latestPlan->update(['admission_snapshot' => null, 'admitted_at' => null]);
    $plan = admitChapterPlanForTest($fixture['chapter']->latestPlan->fresh());
    $route = data_get($plan->admission_snapshot, 'routes.reviewer');
    $firstRun = $fixture['draft']->generationRun;
    $firstRun->update([
        'provider' => $route['provider'],
        'model_policy' => $route['model'],
        'context_snapshot' => ['generation_preferences' => [
            // 历史失败与新尝试必须共享同一份三元组预算，才能验证失败 Artifact 不会被复用。
            'repair_request_budgets' => ['arc_completion' => data_get($route, 'repair_request_budgets.arc_completion')],
        ]],
    ]);
    $secondRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Running,
        'provider' => $route['provider'],
        'model_policy' => $route['model'],
        'context_snapshot' => ['generation_preferences' => [
            'repair_request_budgets' => ['arc_completion' => data_get($route, 'repair_request_budgets.arc_completion')],
        ]],
    ]);
    $contract = [[
        'arc_id' => 1,
        'title' => '守城',
        'completion_conditions' => ['守住城门。'],
    ]];
    $invalidAudits = [[
        'arc_id' => 1,
        'status' => 'fulfilled',
        'evidence' => '守住城门；兑现承诺',
    ]];
    $repairResponse = new AiResponse(
        content: json_encode(['arc_completion_audits' => [[
            'arc_id' => 1,
            'status' => 'not_met',
            'evidence' => null,
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: ['arc_completion_audits' => [[
            'arc_id' => 1,
            'status' => 'not_met',
            'evidence' => null,
        ]]],
        inputTokens: 20,
        outputTokens: 10,
        cachedTokens: 0,
        latencyMs: 10,
        providerRequestId: 'arc-completion-retry-request',
        model: 'review-test',
    );
    $fake = (new FakeAiProvider)
        ->enqueue(new AiProviderException('provider_request_failed', 'temporary provider failure', true, 500))
        ->enqueue($repairResponse);
    app()->instance(AiProvider::class, $fake);
    $repairer = app(ArcCompletionAuditRepairer::class);

    $failed = $repairer->repair($firstRun->fresh(), $contract, $invalidAudits, $fixture['draft']->content, $route['model']);
    $succeeded = $repairer->repair($secondRun, $contract, $invalidAudits, $fixture['draft']->content, $route['model']);

    expect($failed['status'])->toBe('failed')
        ->and($succeeded['status'])->toBe('succeeded')
        ->and($fake->requests())->toHaveCount(2);
});

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

function reviewSchemaRepairResponse(array $findings = [], string $summary = '聚焦复核后未发现该维度的实质问题。'): AiResponse
{
    $data = compact('summary', 'findings');

    return new AiResponse(content: json_encode($data), structuredData: $data, inputTokens: 20, outputTokens: 20, cachedTokens: 0, latencyMs: 20, providerRequestId: 'review-repair-request', model: 'review-test');
}

function bindStateValidation(StateValidationResult $result): void
{
    $validator = Mockery::mock(StateValidator::class);
    $validator->shouldReceive('validate')->andReturn($result);
    app()->instance(StateValidator::class, $validator);
}

function reviewForeshadowingFixture(string $coverageStatus = 'missing', bool $withEvent = false): array
{
    $scene = null;
    $foreshadowing = null;
    $fixture = reviewFixture(function (Chapter $chapter) use (&$scene, &$foreshadowing, $coverageStatus): array {
        $scene = Scene::factory()->for($chapter)->create(['sequence' => 1]);
        $foreshadowing = Foreshadowing::factory()->for($chapter->novel)->create([
            'title' => '染血地图',
            'promised_payoff' => '地图最终指向潮汐门并让林舟据此打开入口。',
            'importance' => ForeshadowingImportance::Critical,
            'status' => ForeshadowingStatus::Planted,
            'due_from_chapter' => $chapter->sequence,
            'due_to_chapter' => $chapter->sequence,
        ]);
        $chapter->latestPlan->update(['foreshadowing_actions' => [[
            'foreshadowing_id' => $foreshadowing->getKey(),
            'action' => 'pay_off',
            'target_scene_sequence' => 1,
            'acceptance_criteria' => '正文明确地图指向潮汐门，并让林舟据此打开入口。',
            'reason' => null,
        ]]]);

        return [
            'scene_coverage' => [[
                'scene_id' => $scene->getKey(),
                'foreshadowing_coverage' => [[
                    'foreshadowing_id' => $foreshadowing->getKey(),
                    'action' => 'pay_off',
                    'status' => $coverageStatus,
                    'evidence' => $coverageStatus === 'fulfilled' ? '兑现了向同伴作出的承诺' : null,
                ]],
            ]],
            'plan_findings' => $coverageStatus === 'fulfilled' ? [] : [[
                'code' => 'FORESHADOWING_COVERAGE_MISSING',
                'dimension' => 'plan',
                'severity' => 'error',
                'scene_id' => $scene->getKey(),
                'scope' => 'scene',
                'auto_fixable' => true,
                'requires_human_decision' => false,
                'foreshadowing_id' => $foreshadowing->getKey(),
                'foreshadowing_action' => 'pay_off',
                'coverage_status' => 'missing',
                'evidence' => null,
                'message' => '伏笔没有在正文中完成兑现。',
                'source' => 'assembly_foreshadowing_coverage',
            ]],
        ];
    });
    $state = $fixture['novel']->fresh()->canonicalStateVersion;
    $stateData = $state->state;
    data_set($stateData, "foreshadowings.{$foreshadowing->getKey()}.status", ForeshadowingStatus::Planted->value);
    $state = StoryStateVersion::factory()->for($fixture['novel'])->create([
        'version' => $state->version + 1,
        'state' => $stateData,
    ]);
    $fixture['novel']->update(['canonical_state_version_id' => $state->getKey()]);

    $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => $state->version,
    ]);
    $events = $withEvent ? [[
        'event_type' => EventType::ForeshadowingPaidOff->value,
        'subject_type' => 'foreshadowing',
        'subject_id' => (string) $foreshadowing->getKey(),
        'payload' => [],
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => $scene->getKey(),
            'quote' => '兑现了向同伴作出的承诺',
            'start_offset' => null,
            'end_offset' => null,
        ]],
        'story_time' => null,
        'confidence' => .95,
    ]] : [];
    GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => ['source_artifact_id' => $fixture['draft']->getKey(), 'events' => $events],
    ]);

    return [...$fixture, 'scene' => $scene, 'foreshadowing' => $foreshadowing];
}

function compactReviewResponse(array $findings = [], int $score = 90): AiResponse
{
    $data = [
        'scores' => array_fill_keys(['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'], $score),
        'chapter_plan_completion' => ['status' => 'fulfilled', 'evidence' => '林舟守住城门'],
        'milestone_completion' => [['status' => 'not_met', 'evidence' => null]],
        'beat_exit' => [['status' => 'not_met', 'evidence' => null]],
        'handoff_readiness' => [],
        'foreshadowing_audits' => [],
        'arc_beat_audits' => [],
        'arc_completion_audits' => [],
        'character_candidate_audits' => [],
        'world_entity_candidate_audits' => [],
        'unapproved_characters' => [],
        'unapproved_world_entities' => [],
        'findings' => $findings,
    ];

    return new AiResponse(content: json_encode($data), structuredData: $data, inputTokens: 100, outputTokens: 80, cachedTokens: 0, latencyMs: 100, providerRequestId: 'compact-review', model: 'review-test');
}

test('compact narrative review schema excludes decisions database identities ordering echoes and deterministic conclusions', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(compactReviewResponse());
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());
    $schema = $fake->requests()[0]->responseSchema;
    $encoded = json_encode($schema, JSON_THROW_ON_ERROR);

    expect($review->decision)->toBe(ReviewDecision::Pass)
        ->and($review->artifact->data['decision'])->toBe('PASS')
        ->and($review->artifact->data)->not->toHaveKey('recommended_decision')
        ->and($schema['required'])->toContain('scores', 'findings', 'foreshadowing_audits')
        ->and(data_get($schema, 'properties.findings.items.required'))->not->toContain('scene_id')
        ->and($encoded)->not->toContain('recommended_decision')
        ->and($encoded)->not->toContain('dimension_audits')
        ->and($encoded)->not->toContain('arc_id')
        ->and($encoded)->not->toContain('scene_id')
        ->and($encoded)->not->toContain('target_scene_sequence')
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得返回推荐 Decision')
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得复述数据库 ID')
        ->and($fake->requests()[0]->systemPrompt)->toContain('单一 Scene 的表达与 Milestone 验收条件冲突')
        ->and($fake->requests()[0]->systemPrompt)->toContain('Milestone/Handoff 目标本身')
        ->and($fake->requests()[0]->systemPrompt)->toContain('必须区分“内容重复”和“文风失真”')
        ->and($fake->requests()[0]->systemPrompt)->toContain('EXCESSIVE_REPETITION')
        ->and($fake->requests()[0]->systemPrompt)->toContain('STYLE_MISMATCH');
});

test('compact review schema freezes the exact item count of every ordered audit contract', function () {
    $fixture = reviewFixture();
    $plan = $fixture['chapter']->latestPlan;
    $plan->update([
        'arc_contributions' => [
            ['arc_id' => 11, 'acceptance_criteria' => '完成主线转折。'],
            ['arc_id' => 12, 'acceptance_criteria' => '留下下一卷入口。'],
        ],
        'character_candidates' => [['name' => '新角色']],
        'world_entity_candidates' => [
            ['name' => '新地点'],
            ['name' => '新规则'],
        ],
    ]);
    $outline = app(OutlineCompletionService::class)->contract($fixture['chapter']->fresh());
    $counts = CompactReviewPayload::contractCounts(
        $plan->fresh(),
        $outline,
        ['actions' => [['action' => 'plant'], ['action' => 'reinforce']]],
    );
    $schema = CompactReviewPayload::schema($counts);

    expect($counts)->toMatchArray([
        'arc_beat_audits' => 2,
        'arc_completion_audits' => 2,
        'character_candidate_audits' => 1,
        'world_entity_candidate_audits' => 2,
        'foreshadowing_audits' => 2,
    ]);

    foreach ($counts as $field => $count) {
        expect(data_get($schema, "properties.{$field}.minItems"))->toBe($count)
            ->and(data_get($schema, "properties.{$field}.maxItems"))->toBe($count);
    }

    $otherwiseValid = compactReviewResponse()->structuredData;
    unset($otherwiseValid['arc_beat_audits']);
    expect(app(JsonSchemaValidator::class)->matches(
        $otherwiseValid,
        CompactReviewPayload::repairableSourceSchema(['arc_beat_audits']),
    ))->toBeTrue();
    $otherwiseValid['scores']['continuity'] = 'invalid';
    expect(app(JsonSchemaValidator::class)->matches(
        $otherwiseValid,
        CompactReviewPayload::repairableSourceSchema(['arc_beat_audits']),
    ))->toBeFalse();
});

test('review contract omission is checkpointed and repaired by one isolated provider request', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $sceneRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => $scene->getKey(),
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $sceneArtifact = GenerationArtifact::factory()->for($sceneRun)->create([
        'type' => ArtifactType::SceneDraft,
        'content' => $fixture['draft']->content,
        'checksum' => hash('sha256', $fixture['draft']->content),
    ]);
    $scene->update(['current_artifact_id' => $sceneArtifact->getKey()]);

    $plan = $fixture['chapter']->latestPlan;
    $arc = StoryArc::query()->where('source_outline_arc_id', $plan->primary_outline_arc_id)->firstOrFail();
    $plan->update(['arc_contributions' => [[
        'arc_id' => $arc->getKey(),
        'beat_key' => $plan->primaryOutlineBeat->beat_key,
        'beat_index' => 1,
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '守住城门。',
    ]]]);

    $invalid = compactReviewResponse()->structuredData;
    $invalid['arc_completion_audits'] = [['status' => 'not_met', 'evidence' => null]];
    $invalidResponse = new AiResponse(
        content: json_encode($invalid, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $invalid,
        inputTokens: 100,
        outputTokens: 80,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'review-contract-invalid',
        model: 'review-test',
    );
    $repairData = ['arc_beat_audits' => [[
        'status' => 'fulfilled',
        'evidence' => '林舟守住城门',
    ]]];
    $repairResponse = new AiResponse(
        content: json_encode($repairData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $repairData,
        inputTokens: 40,
        outputTokens: 20,
        cachedTokens: 0,
        latencyMs: 50,
        providerRequestId: 'review-contract-repair',
        model: 'review-test',
    );
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue($invalidResponse)->enqueue($repairResponse);
    app()->instance(AiProvider::class, $fake);
    $reviewer = app(ChapterReviewer::class);

    $advance = Mockery::mock(AdvanceChapterPipelineAction::class);
    $advance->shouldReceive('handle')
        ->once()
        ->with($fixture['chapter']->getKey())
        ->andReturn(GenerationStage::Review);
    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle($reviewer, $advance);
    expect(Review::query()->count())->toBe(0);

    $review = $reviewer->review($fixture['chapter']->getKey());
    $requests = $fake->requests();
    $reviewRuns = GenerationRun::query()->where('stage', GenerationStage::Review)->orderBy('id')->get();
    $repairRun = GenerationRun::query()->where('stage', GenerationStage::ReviewRepair)->sole();
    $checkpoint = GenerationArtifact::query()->get()->first(
        fn (GenerationArtifact $artifact): bool => data_get($artifact->data, 'role') === 'review_contract_payload',
    );
    $repairArtifact = GenerationArtifact::query()->get()->first(
        fn (GenerationArtifact $artifact): bool => data_get($artifact->data, 'role') === 'review_contract_repair',
    );

    expect($requests)->toHaveCount(2)
        ->and(data_get($requests[0]->responseSchema, 'properties.arc_beat_audits.minItems'))->toBe(1)
        ->and(data_get($requests[0]->responseSchema, 'properties.arc_beat_audits.maxItems'))->toBe(1)
        ->and($requests[1]->promptVersion)->toBe('review-contract-repair-v1')
        ->and($requests[1]->responseSchema['required'])->toBe(['arc_beat_audits'])
        ->and(data_get($requests[1]->responseSchema, 'properties.arc_beat_audits.minItems'))->toBe(1)
        ->and($requests[1]->prompt)->not->toContain('parent_review_input_hash')
        ->and($requests[1]->prompt)->not->toContain('source_artifact_id')
        ->and($reviewRuns)->toHaveCount(2)
        ->and($reviewRuns[0]->status)->toBe(RunStatus::Failed)
        ->and($reviewRuns[0]->error_code)->toBe('review_contract_output_invalid')
        ->and($reviewRuns[1]->status)->toBe(RunStatus::Succeeded)
        ->and($repairRun->status)->toBe(RunStatus::Succeeded)
        ->and($checkpoint)->not->toBeNull()
        ->and($repairArtifact)->not->toBeNull()
        ->and(data_get($review->artifact->data, 'arc_beat_audits.0.arc_id'))->toBe($arc->getKey())
        ->and(data_get($review->artifact->data, 'arc_beat_audits.0.scene_id'))->toBe($scene->getKey())
        ->and(data_get($review->artifact->data, 'schema_repairs.0.generation_run_id'))->toBe($repairRun->getKey())
        ->and(data_get($review->artifact->data, 'schema_repairs.0.fields'))->toBe(['arc_beat_audits']);
});

test('historical reviewer v16 admission keeps the frozen v16 system prompt', function () {
    $fixture = reviewFixture();
    $plan = $fixture['chapter']->latestPlan;
    $snapshot = $plan->admission_snapshot;
    data_set($snapshot, 'routes.reviewer.prompt_version', 'reviewer-v16+natural-reviewing-v3');
    $plan->update(['admission_snapshot' => $snapshot]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(compactReviewResponse());
    app()->instance(AiProvider::class, $fake);

    app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->promptVersion)->toBe('reviewer-v16+natural-reviewing-v3')
        ->and($fake->requests()[0]->systemPrompt)->toContain('章节功能、Milestone、Handoff、剧情结果')
        ->and($fake->requests()[0]->systemPrompt)->not->toContain('单一 Scene 的表达与 Milestone 验收条件冲突');
});

test('reviewer rejects a stale admission prompt capacity estimator before provider use', function () {
    $fixture = reviewFixture();
    $plan = $fixture['chapter']->latestPlan;
    $snapshot = $plan->admission_snapshot;
    data_set($snapshot, 'schema_version', 4);
    data_set($snapshot, 'capacity.review.estimator_version', 'review-prompt-capacity-stale');
    $plan->update(['admission_snapshot' => $snapshot]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(compactReviewResponse());
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterReviewer::class)->review($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '容量估算器与 Plan Admission 冻结版本不一致');

    expect($fake->requests())->toBe([]);
});

test('historical reviewer v17 admission keeps the frozen v17 prompt and context contract', function () {
    $fixture = reviewFixture();
    $plan = $fixture['chapter']->latestPlan;
    $snapshot = $plan->admission_snapshot;
    data_set($snapshot, 'routes.reviewer.prompt_version', 'reviewer-v17+natural-reviewing-v3');
    $plan->update(['admission_snapshot' => $snapshot]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(compactReviewResponse());
    app()->instance(AiProvider::class, $fake);

    app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->promptVersion)->toBe('reviewer-v17+natural-reviewing-v3')
        ->and($fake->requests()[0]->systemPrompt)->toContain('单一 Scene 的表达与 Milestone 验收条件冲突')
        ->and($fake->requests()[0]->systemPrompt)->not->toContain('audit_contract')
        ->and($fake->requests()[0]->prompt)->not->toContain('"audit_contract"');
});

test('reviewer v20 keeps full completion contracts locally but sends each milestone requirement once', function () {
    $fixture = reviewForeshadowingFixture('fulfilled', true);
    $candidate = GenerationArtifact::query()
        ->where('type', ArtifactType::EventCandidate)
        ->latest('id')
        ->firstOrFail();
    $candidateData = $candidate->data;
    data_set($candidateData, 'outline_completion.duplicated_evidence', str_repeat('重复里程碑证据', 6_000));
    GenerationArtifact::factory()->for($candidate->generationRun)->create([
        'type' => ArtifactType::EventCandidate,
        'version' => $candidate->version + 1,
        'content' => null,
        'data' => $candidateData,
        'checksum' => hash('sha256', json_encode($candidateData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
    ]);
    bindStateValidation(new StateValidationResult([]));
    $response = compactReviewResponse();
    $data = $response->structuredData;
    $data['foreshadowing_audits'] = [[
        'status' => 'fulfilled',
        'summary' => '伏笔已按计划兑现。',
        'evidence' => '兑现了向同伴作出的承诺',
    ]];
    $response = new AiResponse(
        content: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $data,
        inputTokens: 100,
        outputTokens: 80,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'review-provider-context',
        model: 'review-test',
    );
    $fake = (new FakeAiProvider)->enqueue($response);
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());
    $run = $review->generationRun;
    $milestoneCriterion = data_get($run->context_snapshot, 'outline_completion_contract.milestone_criteria.0');
    $reviewEstimate = data_get($run->context_snapshot, 'generation_preferences.review_prompt_capacity');
    $guardEstimate = data_get($run->context_snapshot, 'generation_preferences.provider_requests.0.estimated_input_tokens');

    expect($review->decision)->toBe(ReviewDecision::Pass)
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->promptVersion)->toBe('reviewer-v20+natural-reviewing-v3')
        ->and($fake->requests()[0]->prompt)->not->toContain('"event_candidate"')
        ->and($fake->requests()[0]->prompt)->not->toContain('"story_arc_beat_milestone_completed"')
        ->and($fake->requests()[0]->prompt)->not->toContain('重复里程碑证据')
        ->and($fake->requests()[0]->prompt)->not->toContain('"outline_completion_contract"')
        ->and($fake->requests()[0]->prompt)->not->toContain('"audit_contract"')
        ->and($fake->requests()[0]->prompt)->toContain('"outline_completion_requirements"')
        ->and($milestoneCriterion)->toBeString()
        ->and(substr_count($fake->requests()[0]->prompt, $milestoneCriterion))->toBe(1)
        ->and(data_get($run->context_snapshot, 'event_candidate.artifact_id'))->toBeInt()
        ->and(data_get($run->context_snapshot, 'event_candidate.events.0.event_type'))
        ->toBe(EventType::ForeshadowingPaidOff->value)
        ->and(mb_strlen((string) data_get($run->context_snapshot, 'event_candidate.outline_completion.duplicated_evidence')))
        ->toBeGreaterThan(30_000)
        ->and(data_get($run->context_snapshot, 'outline_completion_contract.checksum'))->toBeString()
        ->and(data_get($run->context_snapshot, 'audit_contract.arrays.milestone_completion.count'))->toBeInt()
        ->and(data_get($reviewEstimate, 'estimator_version'))->toBe('review-prompt-capacity-v1')
        // Reviewer 与通用 Provider 门禁必须对同一 AiRequest 得出完全相同的输入估算。
        ->and(data_get($reviewEstimate, 'estimated_input_tokens'))->toBe($guardEstimate)
        ->and(data_get($reviewEstimate, 'input_checksum'))->toHaveLength(64)
        ->and(data_get($reviewEstimate, 'input_breakdown.system_prompt_tokens'))->toBeGreaterThan(0)
        ->and(data_get($reviewEstimate, 'input_breakdown.user_instruction_tokens'))->toBeGreaterThan(0)
        ->and(data_get($reviewEstimate, 'input_breakdown.user_payload_tokens'))->toBeGreaterThan(0)
        ->and(data_get($reviewEstimate, 'input_breakdown.response_schema_tokens'))->toBeGreaterThan(0)
        ->and(data_get($reviewEstimate, 'visible_output_tokens'))->toBe(12_000)
        ->and(data_get($reviewEstimate, 'reasoning_reserve_tokens'))->toBe(8_000)
        ->and(data_get($reviewEstimate, 'max_completion_tokens'))->toBe(20_000)
        ->and(data_get($reviewEstimate, 'estimated_total_tokens'))->toBe(
            data_get($reviewEstimate, 'estimated_input_tokens')
            + data_get($reviewEstimate, 'reasoning_reserve_tokens')
            + data_get($reviewEstimate, 'visible_output_tokens'),
        );
});

test('reviewer v20 does not send a large event candidate when the plan has no foreshadowing actions', function () {
    $fixture = reviewFixture();
    $state = $fixture['novel']->fresh()->canonicalStateVersion;
    $marker = str_repeat('大型里程碑候选仅供本地校验', 4_000);
    $eventRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => $state->version,
    ]);
    GenerationArtifact::factory()->for($eventRun)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => [
            'source_artifact_id' => $fixture['draft']->getKey(),
            'outline_completion' => ['large_milestone_payload' => $marker],
            'events' => [],
        ],
    ]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(compactReviewResponse());
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Pass)
        ->and($fixture['chapter']->latestPlan->foreshadowing_actions ?? [])->toBe([])
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->prompt)->not->toContain('"event_candidate"')
        ->and($fake->requests()[0]->prompt)->not->toContain('大型里程碑候选仅供本地校验')
        ->and(data_get($review->generationRun->context_snapshot, 'event_candidate.outline_completion.large_milestone_payload'))
        ->toBe($marker);
});

test('reviewer v20 still rejects a fulfilled foreshadowing audit without a matching local event candidate', function () {
    $fixture = reviewForeshadowingFixture('fulfilled', false);
    bindStateValidation(new StateValidationResult([]));
    $response = compactReviewResponse();
    $data = $response->structuredData;
    $data['foreshadowing_audits'] = [[
        'status' => 'fulfilled',
        'summary' => '伏笔已按计划兑现。',
        'evidence' => '兑现了向同伴作出的承诺',
    ]];
    $response = new AiResponse(
        content: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $data,
        inputTokens: 100,
        outputTokens: 80,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'review-local-event-validation',
        model: 'review-test',
    );
    $fake = (new FakeAiProvider)->enqueue($response);
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterReviewer::class)->review($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, '缺少匹配 Event Candidate');

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->prompt)->not->toContain('"event_candidate"');
});

test('a three scene chapter completes review within the 48000 token strategy', function () {
    setGenerationSettingsForTest([
        'review_context_token_budget' => 48_000,
        'chapter_request_budgets.reviewer.initial.output_tokens' => 12_000,
        'chapter_request_budgets.reviewer.initial.reasoning_reserve_tokens' => 8_000,
    ]);
    $fixture = reviewFixture();
    $plan = $fixture['chapter']->latestPlan;
    $plan->update(['scene_plans' => collect(range(1, 3))->map(fn (int $sequence): array => [
        'goal' => "推进第 {$sequence} 场目标",
        'conflict' => "处理第 {$sequence} 场阻力",
        'turn' => "形成第 {$sequence} 场转折",
        'outcome' => "完成第 {$sequence} 场结果",
        'outcome_allowed' => [],
        'outcome_forbidden' => [],
        'continuity_requirements' => [],
        'transition_from_previous' => $sequence === 1 ? null : '承接上一场结果。',
    ])->all()]);
    foreach (range(1, 3) as $sequence) {
        Scene::factory()->for($fixture['chapter'])->create([
            'sequence' => $sequence,
            'goal' => "推进第 {$sequence} 场目标",
            'conflict' => "处理第 {$sequence} 场阻力",
            'turn' => "形成第 {$sequence} 场转折",
            'outcome' => "完成第 {$sequence} 场结果",
        ]);
    }
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(compactReviewResponse());
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());
    $capacity = data_get($review->generationRun->context_snapshot, 'generation_preferences.review_prompt_capacity');

    expect($review->decision)->toBe(ReviewDecision::Pass)
        ->and($fixture['chapter']->scenes()->count())->toBe(3)
        ->and($fake->requests())->toHaveCount(1)
        ->and(data_get($capacity, 'estimated_total_tokens'))->toBeLessThanOrEqual(48_000)
        ->and(data_get($review->generationRun->context_snapshot, 'generation_preferences.review_token_budget.context_token_budget'))
        ->toBe(48_000);
});

test('historical reviewer v19 keeps its frozen outline and audit provider context', function () {
    $fixture = reviewFixture();
    $plan = $fixture['chapter']->latestPlan;
    $snapshot = $plan->admission_snapshot;
    data_set($snapshot, 'routes.reviewer.prompt_version', 'reviewer-v19+natural-reviewing-v3');
    $plan->update(['admission_snapshot' => $snapshot]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(compactReviewResponse());
    app()->instance(AiProvider::class, $fake);

    app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->promptVersion)->toBe('reviewer-v19+natural-reviewing-v3')
        ->and($fake->requests()[0]->prompt)->not->toContain('"event_candidate"')
        ->and($fake->requests()[0]->prompt)->toContain('"outline_completion_contract"')
        ->and($fake->requests()[0]->prompt)->toContain('"audit_contract"')
        ->and($fake->requests()[0]->prompt)->not->toContain('"outline_completion_requirements"');
});

test('historical reviewer v18 keeps its frozen provider context contract', function () {
    $fixture = reviewFixture();
    $plan = $fixture['chapter']->latestPlan;
    $snapshot = $plan->admission_snapshot;
    data_set($snapshot, 'routes.reviewer.prompt_version', 'reviewer-v18+natural-reviewing-v3');
    $plan->update(['admission_snapshot' => $snapshot]);
    $state = $fixture['novel']->fresh()->canonicalStateVersion;
    $completion = [
        'milestone_completion' => ['status' => 'not_met'],
        'beat_exit' => ['status' => 'not_met'],
        'handoff_readiness' => ['status' => 'not_applicable'],
    ];
    $eventRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => $state->version,
    ]);
    GenerationArtifact::factory()->for($eventRun)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => [
            'source_artifact_id' => $fixture['draft']->getKey(),
            'outline_completion' => $completion,
            'events' => [],
        ],
    ]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(compactReviewResponse());
    app()->instance(AiProvider::class, $fake);

    app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->promptVersion)->toBe('reviewer-v18+natural-reviewing-v3')
        ->and($fake->requests()[0]->prompt)->toContain('"event_candidate"')
        ->and($fake->requests()[0]->prompt)->toContain('"outline_completion":{"milestone_completion":{"status":"not_met"');
});

test('compact review restores frozen contract order and database identities in Laravel', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $sceneRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => $scene->getKey(), 'scope_type' => 'scene', 'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration, 'status' => RunStatus::Succeeded,
    ]);
    $sceneArtifact = GenerationArtifact::factory()->for($sceneRun)->create([
        'type' => ArtifactType::SceneDraft, 'content' => $fixture['draft']->content,
        'checksum' => hash('sha256', $fixture['draft']->content),
    ]);
    $scene->update(['current_artifact_id' => $sceneArtifact->getKey()]);
    $plan = $fixture['chapter']->latestPlan;
    $arc = StoryArc::query()->where('source_outline_arc_id', $plan->primary_outline_arc_id)->firstOrFail();
    $plan->update(['arc_contributions' => [[
        'arc_id' => $arc->getKey(), 'beat_key' => $plan->primaryOutlineBeat->beat_key,
        'beat_index' => 1, 'target_scene_sequence' => 1, 'acceptance_criteria' => '守住城门。',
    ]]]);
    $response = compactReviewResponse();
    $data = $response->structuredData;
    $data['arc_beat_audits'] = [['status' => 'fulfilled', 'evidence' => "“林舟\n\n守住城门”"]];
    $data['arc_completion_audits'] = [['status' => 'not_met', 'evidence' => null]];
    $data['unapproved_characters'] = [['name' => '守门人', 'evidence' => "“林舟\n\n守住城门”"]];
    $response = new AiResponse(content: json_encode($data), structuredData: $data, inputTokens: 100, outputTokens: 80, cachedTokens: 0, latencyMs: 100, providerRequestId: 'compact-order', model: 'review-test');
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue($response));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect(data_get($review->artifact->data, 'arc_beat_audits.0.arc_id'))->toBe($arc->getKey())
        ->and(data_get($review->artifact->data, 'arc_beat_audits.0.beat_key'))->toBe($plan->primaryOutlineBeat->beat_key)
        ->and(data_get($review->artifact->data, 'arc_beat_audits.0.scene_id'))->toBe($scene->getKey())
        ->and(data_get($review->artifact->data, 'arc_completion_audits.0.arc_id'))->toBe($arc->getKey())
        ->and(data_get($review->artifact->data, 'unapproved_characters.0.evidence'))->toBe('林舟守住城门')
        ->and(data_get($review->artifact->data, 'unapproved_characters.0.scene_id'))->toBe($scene->getKey());
});

test('compact review downgrades fulfilled outline evidence that cannot bind one scene', function () {
    $fixture = reviewFixture();
    foreach ([1, 2] as $sequence) {
        $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => $sequence]);
        $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
            'scene_id' => $scene->getKey(), 'scope_type' => 'scene', 'scope_id' => $scene->getKey(),
            'stage' => GenerationStage::SceneGeneration, 'status' => RunStatus::Succeeded,
        ]);
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft, 'content' => $fixture['draft']->content,
            'checksum' => hash('sha256', $fixture['draft']->content),
        ]);
        $scene->update(['current_artifact_id' => $artifact->getKey()]);
    }

    $payload = compactReviewResponse()->structuredData;
    $payload['milestone_completion'][0] = ['status' => 'fulfilled', 'evidence' => '林舟守住城门'];
    $expanded = CompactReviewPayload::expand(
        $payload,
        $fixture['chapter']->fresh(['latestPlan', 'scenes.currentArtifact']),
        $fixture['draft'],
        app(OutlineCompletionService::class)->contract($fixture['chapter']),
        [],
    );

    expect(data_get($expanded, 'milestone_completion.status'))->toBe('not_met')
        ->and(data_get($expanded, 'milestone_completion.criteria.0.status'))->toBe('not_met')
        ->and(data_get($expanded, 'milestone_completion.criteria.0.evidence'))->toBeNull()
        ->and(data_get($expanded, 'milestone_completion.criteria.0.scene_id'))->toBeNull();
});

test('milestone contradiction uses its own evidence and routes the affected scene to rewrite', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => $scene->getKey(), 'scope_type' => 'scene', 'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration, 'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::SceneDraft, 'content' => $fixture['draft']->content,
        'checksum' => hash('sha256', $fixture['draft']->content),
    ]);
    $scene->update(['current_artifact_id' => $artifact->getKey()]);
    $payload = [
        'chapter_plan_completion' => ['status' => 'fulfilled', 'evidence' => '林舟守住城门', 'scene_id' => null],
        'milestone_completion' => ['status' => 'contradicted', 'criteria' => [
            ['status' => 'fulfilled', 'evidence' => '这条已满足的证据不应被当作冲突证据。', 'scene_id' => null],
            ['status' => 'contradicted', 'evidence' => '林舟守住城门', 'scene_id' => $scene->getKey()],
        ]],
    ];

    $findings = app(OutlineCompletionService::class)->findings($payload);
    $scope = app(RewriteScopeResolver::class)->resolve(
        $fixture['chapter']->fresh(['scenes.currentArtifact']),
        $findings,
    );

    expect($findings)->toHaveCount(1)
        ->and(data_get($findings, '0.code'))->toBe('MILESTONE_CONTRADICTED')
        ->and(data_get($findings, '0.evidence'))->toBe('林舟守住城门')
        ->and(data_get($findings, '0.scene_id'))->toBe($scene->getKey())
        ->and(data_get($findings, '0.scope'))->toBe('scene')
        ->and($scope->scope)->toBe('scene')
        ->and($scope->sceneId)->toBe($scene->getKey());
});

test('milestone contradiction shared by multiple scenes remains a chapter level attention case', function () {
    $fixture = reviewFixture();
    foreach ([1, 2] as $sequence) {
        $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => $sequence]);
        $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
            'scene_id' => $scene->getKey(), 'scope_type' => 'scene', 'scope_id' => $scene->getKey(),
            'stage' => GenerationStage::SceneGeneration, 'status' => RunStatus::Succeeded,
        ]);
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft, 'content' => $fixture['draft']->content,
            'checksum' => hash('sha256', $fixture['draft']->content),
        ]);
        $scene->update(['current_artifact_id' => $artifact->getKey()]);
    }

    $payload = compactReviewResponse()->structuredData;
    $payload['milestone_completion'][0] = ['status' => 'contradicted', 'evidence' => '林舟守住城门'];
    $chapter = $fixture['chapter']->fresh(['latestPlan', 'scenes.currentArtifact']);
    $service = app(OutlineCompletionService::class);
    $expanded = CompactReviewPayload::expand(
        $payload,
        $chapter,
        $fixture['draft'],
        $service->contract($chapter),
        [],
    );
    $validated = $service->validateReview($expanded, $chapter, $fixture['draft']);
    $findings = $service->findings($validated);
    $scope = app(RewriteScopeResolver::class)->resolve($chapter, $findings);

    expect(data_get($validated, 'milestone_completion.status'))->toBe('contradicted')
        ->and(data_get($validated, 'milestone_completion.criteria.0.evidence'))->toBe('林舟守住城门')
        ->and(data_get($validated, 'milestone_completion.criteria.0.scene_id'))->toBeNull()
        ->and(data_get($findings, '0.scope'))->toBe('chapter')
        ->and($scope->isResolved())->toBeFalse()
        ->and($scope->reason)->toBe('plan_or_scene_rebuild_required');
});

test('deterministic review failure skips the reviewer and persists executable repair advice', function () {
    $fixture = reviewFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 1_000]);
    bindStateValidation(new StateValidationResult([]));
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($fake->requests())->toHaveCount(0)
        ->and($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and(data_get($review->artifact->data, 'semantic_review_performed'))->toBeFalse()
        ->and(data_get($review->artifact->data, 'scores_status'))->toBe('not_evaluated')
        ->and(data_get($review->artifact->data, 'repair_advice.action'))->toBe('rebuild_plan_or_scenes')
        ->and(data_get($review->artifact->data, 'repair_advice.next_stage'))->toBe(GenerationStage::ChapterPlanning->value);
});

test('hard state or locked fact conflict skips semantic review and blocks', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([
        new StateFinding('LOCKED_FACT_CONFLICT', StateFindingSeverity::Hard, '正文与锁定事实冲突。', evidence: []),
    ]));
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($fake->requests())->toHaveCount(0)
        ->and($review->decision)->toBe(ReviewDecision::Block)
        ->and(data_get($review->artifact->data, 'semantic_review_performed'))->toBeFalse();
});

test('chapter structural finding produces plan scene repair advice and never dispatches whole chapter rewrite', function () {
    Queue::fake();
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $finding = [
        'code' => 'PLAN_DEVIATION', 'dimension' => 'plan', 'severity' => 'error',
        'scope' => 'chapter', 'auto_fixable' => false, 'requires_human_decision' => false,
        'message' => '章节功能与 Milestone 结果不一致。', 'evidence' => '林舟守住城门',
    ];
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(compactReviewResponse([$finding])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));
    $review = Review::query()->latest('id')->firstOrFail();

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and(data_get($review->artifact->data, 'findings.0.auto_fixable'))->toBeTrue()
        ->and(data_get($review->artifact->data, 'rewrite_scope'))->toBeNull()
        ->and(data_get($review->artifact->data, 'repair_advice.action'))->toBe('rebuild_plan_or_scenes');
    Queue::assertNotPushed(RewriteChapterJob::class);
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->regenerate
        && $job->contentRepairRunId !== null);
});

test('rewrite exhaustion escalates once to a full chapter rebuild', function () {
    Queue::fake();
    config()->set('generation.max_rewrite_attempts', 0);
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $finding = [
        'code' => 'STYLE_MISMATCH', 'dimension' => 'style', 'severity' => 'error',
        'scope' => 'chapter', 'auto_fixable' => true, 'requires_human_decision' => false,
        'message' => '全章叙述结构机械重复。', 'evidence' => '林舟守住城门',
    ];
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(compactReviewResponse([$finding])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    $repair = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::ContentRepair)->sole();
    expect(data_get($repair->context_snapshot, 'repair_type'))->toBe(ContentRepairType::RewriteExhaustion->value)
        ->and(data_get($repair->context_snapshot, 'diagnostics.finding_codes'))->toContain('REWRITE_EXHAUSTED')
        ->and($repair->status)->toBe(RunStatus::Running);
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->regenerate
        && $job->contentRepairRunId === $repair->getKey());
});

test('semantic and milestone findings in one scene share the automatic rewrite scope', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $sceneRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => $scene->getKey(), 'scope_type' => 'scene', 'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration, 'status' => RunStatus::Succeeded,
    ]);
    $sceneArtifact = GenerationArtifact::factory()->for($sceneRun)->create([
        'type' => ArtifactType::SceneDraft, 'content' => $fixture['draft']->content,
        'checksum' => hash('sha256', $fixture['draft']->content),
    ]);
    $scene->update(['current_artifact_id' => $sceneArtifact->getKey()]);
    $assemblyRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scope_type' => 'chapter', 'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded,
        'state_version' => $fixture['novel']->fresh()->canonicalStateVersion->version,
    ]);
    GenerationArtifact::factory()->for($assemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $fixture['draft']->content,
        'checksum' => hash('sha256', $fixture['draft']->content),
        'data' => [
            'assembly_strategy' => 'deterministic_v1',
            'ordered_scene_ids' => [$scene->getKey()],
            'source_artifact_ids' => [$sceneArtifact->getKey()],
            'source_checksums' => [$sceneArtifact->checksum],
            'scene_coverage' => [[
                'scene_id' => $scene->getKey(),
                ...collect(['goal', 'conflict', 'turn', 'outcome'])->mapWithKeys(fn (string $key): array => [$key => ['status' => 'fulfilled', 'evidence' => '林舟守住城门']])->all(),
                'foreshadowing_coverage' => [],
            ]],
            'plan_findings' => [],
        ],
    ]);
    bindStateValidation(new StateValidationResult([]));
    $finding = [
        'code' => 'STYLE_MISMATCH', 'dimension' => 'style', 'severity' => 'error',
        'scope' => 'scene', 'auto_fixable' => true, 'requires_human_decision' => false,
        'message' => '本 Scene 文风不一致。', 'evidence' => '林舟守住城门',
    ];
    $response = compactReviewResponse([$finding]);
    $data = $response->structuredData;
    $data['milestone_completion'][0] = ['status' => 'contradicted', 'evidence' => '林舟守住城门'];
    $response = new AiResponse(
        content: json_encode($data), structuredData: $data, inputTokens: 100, outputTokens: 80,
        cachedTokens: 0, latencyMs: 100, providerRequestId: 'scene-milestone-review', model: 'review-test',
    );
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue($response));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and(data_get($review->artifact->data, 'rewrite_scope.scope'))->toBe('scene')
        ->and(data_get($review->artifact->data, 'rewrite_scope.scene_id'))->toBe($scene->getKey())
        ->and(collect($review->findings)->pluck('code')->all())->toContain('MILESTONE_CONTRADICTED', 'STYLE_MISMATCH')
        ->and(collect($review->findings)->where('code', 'MILESTONE_CONTRADICTED')->first()['evidence'])->toBe('林舟守住城门');
});
