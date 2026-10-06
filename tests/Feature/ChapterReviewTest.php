<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Data\StateFinding;
use App\Data\StateValidationResult;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\StateFindingSeverity;
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
use App\Services\OutlineCompletionService;
use App\Services\PlanningReviewAudit;
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

function reviewFixture(?Closure $draftDataFactory = null): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Review]);
    $content = '林舟守住城门，也兑现了向同伴作出的承诺。';
    ChapterPlan::factory()->for($chapter)->create(['target_words' => mb_strlen($content)]);
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
    expect(config('generation.review_max_output_tokens'))->toBe(12_000)
        ->and(config('generation'))->not->toHaveKeys(['review_retry_max_output_tokens', 'review_final_retry_max_output_tokens']);
});

test('review truncation keeps the fixed legal cap and a repeat enters capacity handling', function () {
    config()->set('generation.review_max_output_tokens', 4_000);
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(truncatedReviewResponse(4_000));
    app()->instance(AiProvider::class, $fake);
    $reviewer = app(ChapterReviewer::class);

    expect(fn () => $reviewer->review($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '可见输出');
    expect(Review::query()->count())->toBe(0)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)->count())->toBe(0)
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->maxTokens)->toBe(4_000);

    try {
        $reviewer->review($fixture['chapter']->getKey());
        $this->fail('Expected Reviewer capacity handling.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('review_capacity_mismatch')->and($exception->retryable)->toBeFalse();
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

    expect(fn () => app(PlanningReviewAudit::class)->validate($payload, $fixture['chapter']->fresh(), $fixture['draft']))
        ->toThrow(ValidationException::class, '规划契约验收的标识、顺序或目标 Scene 不一致');
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
    $firstRun = $fixture['draft']->generationRun;
    $secondRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Running,
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

    $failed = $repairer->repair($firstRun, $contract, $invalidAudits, $fixture['draft']->content, 'review-test');
    $succeeded = $repairer->repair($secondRun, $contract, $invalidAudits, $fixture['draft']->content, 'review-test');

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
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得复述数据库 ID');
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
    $data['arc_beat_audits'] = [['status' => 'fulfilled', 'evidence' => '林舟守住城门']];
    $data['arc_completion_audits'] = [['status' => 'not_met', 'evidence' => null]];
    $response = new AiResponse(content: json_encode($data), structuredData: $data, inputTokens: 100, outputTokens: 80, cachedTokens: 0, latencyMs: 100, providerRequestId: 'compact-order', model: 'review-test');
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue($response));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect(data_get($review->artifact->data, 'arc_beat_audits.0.arc_id'))->toBe($arc->getKey())
        ->and(data_get($review->artifact->data, 'arc_beat_audits.0.beat_key'))->toBe($plan->primaryOutlineBeat->beat_key)
        ->and(data_get($review->artifact->data, 'arc_beat_audits.0.scene_id'))->toBe($scene->getKey())
        ->and(data_get($review->artifact->data, 'arc_completion_audits.0.arc_id'))->toBe($arc->getKey());
});

test('deterministic review failure skips the reviewer and persists executable repair advice', function () {
    $fixture = reviewFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 1_000]);
    bindStateValidation(new StateValidationResult([]));
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($fake->requests())->toHaveCount(0)
        ->and($review->decision)->toBe(ReviewDecision::NeedsAttention)
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
        'scope' => 'chapter', 'auto_fixable' => true, 'requires_human_decision' => false,
        'message' => '章节功能与 Milestone 结果不一致。', 'evidence' => '林舟守住城门',
    ];
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(compactReviewResponse([$finding])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));
    $review = Review::query()->latest('id')->firstOrFail();

    expect($review->decision)->toBe(ReviewDecision::NeedsAttention)
        ->and(data_get($review->artifact->data, 'rewrite_scope'))->toBeNull()
        ->and(data_get($review->artifact->data, 'repair_advice.action'))->toBe('rebuild_plan_or_scenes');
    Queue::assertNotPushed(RewriteChapterJob::class);
});

test('one semantic scene finding is mapped from evidence to its only rewrite scope', function () {
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
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(compactReviewResponse([$finding])));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and(data_get($review->artifact->data, 'rewrite_scope.scope'))->toBe('scene')
        ->and(data_get($review->artifact->data, 'rewrite_scope.scene_id'))->toBe($scene->getKey());
});
