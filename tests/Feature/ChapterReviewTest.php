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
use App\Enums\EventType;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\StateFindingSeverity;
use App\Enums\StoryArcStatus;
use App\Jobs\CommitChapterJob;
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
use App\Services\PlanningReviewAudit;
use App\Services\StateValidator;
use App\Services\StoryArcBeatContract;
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
    $data = ['recommended_decision' => $decision, 'scores' => ['continuity' => $score, 'plan' => $score, 'character' => $score, 'progress' => $score, 'repetition' => $score, 'pacing' => $score, 'style' => $score], 'dimension_audits' => $dimensionAudits, 'foreshadowing_audits' => $foreshadowingAudits, 'arc_beat_audits' => [], 'arc_completion_audits' => [], 'character_candidate_audits' => [], 'world_entity_candidate_audits' => [], 'unapproved_characters' => [], 'unapproved_world_entities' => [], 'findings' => $findings];

    return new AiResponse(content: json_encode($data), structuredData: $data, inputTokens: 100, outputTokens: 80, cachedTokens: 0, latencyMs: 100, providerRequestId: 'review-request', model: 'review-test');
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
        metadata: ['finish_reason' => 'length', 'refusal' => null],
    );
}

test('review escalates output budget after truncation and succeeds without repeating the same cap', function () {
    config()->set('generation.review_max_output_tokens', 4_000);
    config()->set('generation.review_retry_max_output_tokens', 8_000);
    config()->set('generation.review_final_retry_max_output_tokens', 12_000);
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)
        ->enqueue(truncatedReviewResponse(4_000))
        ->enqueue(truncatedReviewResponse(8_000))
        ->enqueue(reviewResponse());
    app()->instance(AiProvider::class, $fake);
    $reviewer = app(ChapterReviewer::class);

    expect(fn () => $reviewer->review($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '输出 Token 用尽');
    expect(fn () => $reviewer->review($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '输出 Token 用尽');
    $review = $reviewer->review($fixture['chapter']->getKey());

    $runs = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::Review)->orderBy('id')->get();
    expect($review->decision)->toBe(ReviewDecision::Pass)
        ->and($fake->requests())->toHaveCount(3)
        ->and($fake->requests()[0]->maxTokens)->toBe(4_000)
        ->and($fake->requests()[1]->maxTokens)->toBe(8_000)
        ->and($fake->requests()[2]->maxTokens)->toBe(12_000)
        ->and($runs->pluck('input_hash')->unique())->toHaveCount(1)
        ->and($runs->map(fn (GenerationRun $run): int => (int) data_get($run->context_snapshot, 'generation_preferences.review_retry_ordinal'))->all())->toBe([1, 2, 3]);
});

test('review stops before another provider request after the highest output budget was truncated', function () {
    config()->set('generation.review_max_output_tokens', 4_000);
    config()->set('generation.review_retry_max_output_tokens', 8_000);
    config()->set('generation.review_final_retry_max_output_tokens', 12_000);
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)
        ->enqueue(truncatedReviewResponse(4_000))
        ->enqueue(truncatedReviewResponse(8_000))
        ->enqueue(truncatedReviewResponse(12_000))
        ->enqueue(reviewResponse());
    app()->instance(AiProvider::class, $fake);
    $reviewer = app(ChapterReviewer::class);

    foreach ([4_000, 8_000, 12_000] as $budget) {
        try {
            $reviewer->review($fixture['chapter']->getKey());
            $this->fail("Expected review truncation at {$budget} tokens.");
        } catch (AiProviderException $exception) {
            expect($exception->errorCode)->toBe('review_output_truncated');
        }
    }

    try {
        $reviewer->review($fixture['chapter']->getKey());
        $this->fail('Expected exhausted review output budget.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('review_output_budget_exhausted')
            ->and($exception->retryable)->toBeFalse();
    }

    expect($fake->requests())->toHaveCount(3)
        ->and($fixture['chapter']->generationRuns()->latest('id')->first()->error_code)->toBe('review_output_budget_exhausted');
});

test('planning review audits require verbatim evidence and surface missing or unapproved world data', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $arc = StoryArc::factory()->for($fixture['novel'])->create([
        'status' => StoryArcStatus::Active,
        'beats' => ['守住城门'],
    ]);
    $beatKey = app(StoryArcBeatContract::class)->key('守住城门');
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
        'arc_beat_audits' => [[
            'arc_id' => $arc->getKey(), 'beat_key' => $beatKey, 'status' => 'fulfilled',
            'evidence' => '守住城门', 'scene_id' => $scene->getKey(),
        ]],
        'arc_completion_audits' => [[
            'arc_id' => $arc->getKey(), 'status' => 'not_met', 'evidence' => null,
        ]],
        'character_candidate_audits' => [[
            'candidate_key' => 'character-guide', 'status' => 'missing', 'evidence' => null,
            'scene_id' => $scene->getKey(),
        ]],
        'world_entity_candidate_audits' => [[
            'candidate_key' => 'wec-city-gate', 'status' => 'missing', 'evidence' => null,
            'scene_id' => $scene->getKey(),
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
        ->and($codes)->not->toContain('ARC_BEAT_NOT_FULFILLED');

    $payload['arc_beat_audits'][0]['evidence'] = '正文中不存在的证据';
    expect(fn () => app(PlanningReviewAudit::class)->validate($payload, $fixture['chapter']->fresh(), $fixture['draft']))
        ->toThrow(ValidationException::class, '正文逐字证据');
});

test('planning review audits normalize quoted evidence with an omission marker to a verbatim excerpt', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $arc = StoryArc::factory()->for($fixture['novel'])->create([
        'status' => StoryArcStatus::Active,
        'beats' => ['守住城门'],
    ]);
    $beatKey = app(StoryArcBeatContract::class)->key('守住城门');
    $fixture['chapter']->latestPlan->update(['arc_contributions' => [[
        'arc_id' => $arc->getKey(),
        'beat_key' => $beatKey,
        'beat_index' => 1,
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文明确守住城门。',
    ]]]);
    $payload = [
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
        ->and(data_get($review->generationRun->context_snapshot, 'foreshadowing_contract_checksum'))->toBe(data_get($review->generationRun->context_snapshot, 'foreshadowing_contract.checksum'))
        ->and(data_get($review->generationRun->context_snapshot, 'l4.primary_style.name'))->toBe('通俗爽快')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review)
        ->and($fake->requests()[0]->systemPrompt)->toContain('message 与 evidence 必须使用简体中文')
        ->and($fake->requests()[0]->systemPrompt)->toContain('state_findings 为空表示确定性检查未发现问题')
        ->and($fake->requests()[0]->systemPrompt)->toContain('may_hint 是可选提示')
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得发现一个问题后提前停止')
        ->and($fake->requests()[0]->systemPrompt)->toContain('七个维度逐项完成全量检查')
        ->and($fake->requests()[0]->systemPrompt)->toContain('foreshadowing_contract 是本章冻结的唯一伏笔动作契约')
        ->and($fake->requests()[0]->systemPrompt)->toContain('普通窗口、走廊、查阅区、训练位、报告、凭据、记录、清单和练习道具不是重大世界实体')
        ->and($fake->requests()[0]->systemPrompt)->toContain('明显的机器生成痕迹')
        ->and($fake->requests()[0]->systemPrompt)->toContain('STYLE_MISMATCH finding')
        ->and(data_get($review->generationRun->context_snapshot, 'existing_world_entities'))->toBe([])
        ->and(data_get($review->artifact->data, 'dimension_audits.continuity.status'))->toBe('pass');
});

test('review provides the ordered arc completion contract and requires one audit per arc', function () {
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $arc = StoryArc::factory()->for($fixture['novel'])->create([
        'status' => StoryArcStatus::Active,
        'beats' => ['守住城门'],
        'completion_conditions' => ['主角守住城门并解除围城危机。'],
    ]);
    $beatKey = app(StoryArcBeatContract::class)->key('守住城门');
    $fixture['chapter']->latestPlan->update(['arc_contributions' => [[
        'arc_id' => $arc->getKey(),
        'beat_key' => $beatKey,
        'beat_index' => 1,
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文明确守住城门。',
    ]]]);
    $base = reviewResponse();
    $payload = $base->structuredData;
    $payload['arc_beat_audits'] = [[
        'arc_id' => $arc->getKey(),
        'beat_key' => $beatKey,
        'status' => 'fulfilled',
        'evidence' => '守住城门',
        'scene_id' => $scene->getKey(),
    ]];
    $payload['arc_completion_audits'] = [
        ['arc_id' => $arc->getKey(), 'status' => 'fulfilled', 'evidence' => '守住城门'],
        ['arc_id' => $arc->getKey(), 'status' => 'fulfilled', 'evidence' => '兑现了向同伴作出的承诺'],
    ];
    $response = new AiResponse(
        content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: $base->inputTokens,
        outputTokens: $base->outputTokens,
        cachedTokens: $base->cachedTokens,
        latencyMs: $base->latencyMs,
        providerRequestId: $base->providerRequestId,
        model: $base->model,
    );
    bindStateValidation(new StateValidationResult([]));
    $repairResponse = new AiResponse(
        content: json_encode(['arc_completion_audits' => [[
            'arc_id' => $arc->getKey(),
            'status' => 'not_met',
            'evidence' => null,
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: ['arc_completion_audits' => [[
            'arc_id' => $arc->getKey(),
            'status' => 'not_met',
            'evidence' => null,
        ]]],
        inputTokens: 20,
        outputTokens: 10,
        cachedTokens: 0,
        latencyMs: 10,
        providerRequestId: 'arc-completion-repair-request',
        model: 'review-test',
    );
    $fake = (new FakeAiProvider)->enqueue($response)->enqueue($repairResponse);
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect(data_get($review->generationRun->context_snapshot, 'arc_completion_contract'))->toBe([[
        'arc_id' => $arc->getKey(),
        'title' => $arc->title,
        'completion_conditions' => ['主角守住城门并解除围城危机。'],
    ]])
        ->and($fake->requests()[0]->systemPrompt)->toContain('即使本章没有完成整个 Arc 也不能省略')
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->promptVersion)->toBe(ArcCompletionAuditRepairer::PROMPT_VERSION)
        ->and(data_get($review->artifact->data, 'schema_repairs.arc_completion.status'))->toBe('succeeded')
        ->and(data_get($review->artifact->data, 'arc_completion_audits.0.status'))->toBe('not_met');
});

test('review turns one foreshadowing root cause into one complete automatic rewrite finding', function () {
    $fixture = reviewForeshadowingFixture();
    bindStateValidation(new StateValidationResult([]));
    $audit = [[
        'foreshadowing_id' => $fixture['foreshadowing']->getKey(),
        'action' => 'pay_off',
        'target_scene_sequence' => 1,
        'status' => 'rewrite_required',
        'summary' => '正文只写了普通承诺，没有揭示地图指向潮汐门，也没有让林舟据此打开入口；应在同一次重写中补全兑现动作和结果。',
        'evidence' => '兑现了向同伴作出的承诺',
    ]];
    $fake = (new FakeAiProvider)->enqueue(reviewResponse(foreshadowingAudits: $audit));
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    $foreshadowingFindings = collect($review->findings)->filter(fn (array $finding): bool => (int) ($finding['foreshadowing_id'] ?? 0) === $fixture['foreshadowing']->getKey());
    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and($foreshadowingFindings)->toHaveCount(1)
        ->and($foreshadowingFindings->first()['code'])->toBe('FORESHADOWING_SEMANTIC_REWRITE_REQUIRED')
        ->and(data_get($review->artifact->data, 'rewrite_scope.scene_id'))->toBe($fixture['scene']->getKey())
        ->and(data_get($review->generationRun->context_snapshot, 'event_candidate.events'))->toBe([])
        ->and($fake->requests()[0]->systemPrompt)->toContain('仅提及关键词不能证明完成强化或兑现');
});

test('review routes a required foreshadowing promise or window change to needs attention', function () {
    $fixture = reviewForeshadowingFixture();
    bindStateValidation(new StateValidationResult([]));
    $audit = [[
        'foreshadowing_id' => $fixture['foreshadowing']->getKey(),
        'action' => 'pay_off',
        'target_scene_sequence' => 1,
        'status' => 'needs_attention',
        'summary' => '现有剧情无法在不改变兑现窗口或 promised payoff 的情况下完成，需要用户决定延期或修改承诺。',
        'evidence' => null,
    ]];
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(foreshadowingAudits: $audit)));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::NeedsAttention)
        ->and(collect($review->findings)->pluck('code'))->toContain('FORESHADOWING_DECISION_REQUIRED')
        ->and(data_get($review->artifact->data, 'rewrite_scope'))->toBeNull();
});

test('review cannot pass a foreshadowing action without coverage and matching event proof', function () {
    $fixture = reviewForeshadowingFixture(coverageStatus: 'fulfilled', withEvent: false);
    bindStateValidation(new StateValidationResult([]));
    $audit = [[
        'foreshadowing_id' => $fixture['foreshadowing']->getKey(),
        'action' => 'pay_off',
        'target_scene_sequence' => 1,
        'status' => 'fulfilled',
        'summary' => '已完成兑现。',
        'evidence' => '兑现了向同伴作出的承诺',
    ]];
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(foreshadowingAudits: $audit)));

    expect(fn () => app(ChapterReviewer::class)->review($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, '缺少匹配 Event Candidate');
});

test('review deterministically normalizes pass status when the finding set contains that dimension', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $finding = reviewFinding(autoFixable: true);
    $audits = collect(['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'])
        ->mapWithKeys(fn (string $dimension): array => [$dimension => [
            'status' => 'pass',
            'summary' => '未发现问题。',
        ]])
        ->all();
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [$finding], dimensionAudits: $audits)));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect(data_get($review->artifact->data, 'dimension_audits_raw.style.status'))->toBe('pass')
        ->and(data_get($review->artifact->data, 'dimension_audits.style.status'))->toBe('issues_found');
});

test('an issues found status without a finding is repaired once and normalized without blocking the chapter', function () {
    $incident = require base_path('tests/Fixtures/generation_workflow_quality_incidents.php');
    $recorded = $incident['run_350_review_response'];
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $warning = $recorded['findings'][0];
    $audits = collect(['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'])
        ->mapWithKeys(fn (string $dimension): array => [$dimension => [
            'status' => $recorded['dimension_statuses'][$dimension],
            'summary' => $dimension === 'style' ? '文风可能存在问题，需要复核。' : '已完成全量检查。',
        ]])->all();
    $fake = (new FakeAiProvider)
        ->enqueue(reviewResponse(score: $recorded['score'], findings: [$warning], dimensionAudits: $audits))
        ->enqueue(reviewSchemaRepairResponse());
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision->value)->toBe($recorded['expected_decision'])
        ->and(data_get($review->artifact->data, 'dimension_audits_raw.style.status'))->toBe('issues_found')
        ->and(data_get($review->artifact->data, 'dimension_audits.style.status'))->toBe($recorded['expected_style_status'])
        ->and(data_get($review->artifact->data, 'schema_repairs.style.status'))->toBe('succeeded')
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->systemPrompt)->toContain('明显的机器生成痕迹')
        ->and($fake->requests()[1]->promptVersion)->toBe('review-schema-repair-v3')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review);
});

test('a failed dimension repair becomes needs attention with its request log id', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $audits = collect(['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'])
        ->mapWithKeys(fn (string $dimension): array => [$dimension => [
            'status' => $dimension === 'style' ? 'issues_found' : 'pass',
            'summary' => $dimension === 'style' ? '文风存在未结构化的问题。' : '未发现问题。',
        ]])->all();
    $fake = (new FakeAiProvider)
        ->enqueue(reviewResponse(dimensionAudits: $audits))
        ->enqueue(new AiProviderException('provider_timeout', 'repair timeout', true));
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::NeedsAttention)
        ->and(collect($review->findings)->pluck('code'))->toContain('REVIEW_SCHEMA_REPAIR_FAILED')
        ->and(data_get($review->artifact->data, 'schema_repairs.style.ai_request_log_id'))->not->toBeNull()
        ->and($review->generationRun->status)->toBe(RunStatus::Succeeded)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review);
});

test('focused coverage adjudication can overturn a false missing result and is reused with the review', function () {
    $incident = require base_path('tests/Fixtures/generation_workflow_quality_incidents.php');
    $recorded = $incident['chapter_12_coverage_false_negative'];
    $scene = null;
    $content = $recorded['content'];
    $fixture = reviewFixture(function (Chapter $chapter) use (&$scene, $recorded): array {
        $scene = Scene::factory()->for($chapter)->create(['sequence' => 1]);
        $chapter->latestPlan->update(['scene_plans' => [[
            ...$recorded['scene_plan'],
            'outcome_allowed' => [], 'outcome_forbidden' => [], 'continuity_requirements' => [], 'transition_from_previous' => null,
        ]]]);

        return [
            'scene_coverage' => [[
                'scene_id' => $scene->getKey(),
                'goal' => ['status' => 'missing', 'evidence' => null],
                'conflict' => ['status' => 'fulfilled', 'evidence' => '守住城门'],
                'turn' => ['status' => 'fulfilled', 'evidence' => '兑现了向同伴作出的承诺'],
                'outcome' => ['status' => 'missing', 'evidence' => null],
                'foreshadowing_coverage' => [],
            ]],
            'plan_findings' => collect($recorded['reported_missing'])->map(fn (string $element): array => [
                'code' => 'SCENE_PLAN_COVERAGE_MISSING', 'dimension' => 'plan', 'severity' => 'error',
                'scene_id' => $scene->getKey(), 'scope' => 'scene', 'auto_fixable' => true,
                'requires_human_decision' => false, 'plan_element' => $element, 'coverage_status' => 'missing',
                'expected' => ['description' => $element === 'goal' ? '守住城门' : '城门守住'],
                'evidence' => null, 'message' => 'Coverage 自报缺失。', 'source' => 'assembly_coverage',
            ])->all(),
        ];
    });
    $fixture['chapter']->latestPlan->update(['target_words' => mb_strlen($content)]);
    bindStateValidation(new StateValidationResult([]));
    $coverage = $recorded['repaired_coverage'];
    $coverageResponse = new AiResponse(content: json_encode($coverage), structuredData: $coverage, inputTokens: 20, outputTokens: 20, cachedTokens: 0, latencyMs: 20, providerRequestId: 'coverage-repair', model: 'review-test');
    $fake = (new FakeAiProvider)->enqueue($coverageResponse)->enqueue(reviewResponse(score: 92));
    app()->instance(AiProvider::class, $fake);

    $first = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());
    $second = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($first->decision)->toBe(ReviewDecision::Pass)
        ->and(collect($first->findings)->pluck('code'))->not->toContain('SCENE_PLAN_COVERAGE_MISSING')
        ->and(data_get($first->artifact->data, 'coverage_repairs.0.status'))->toBe('succeeded')
        ->and($second?->is($first))->toBeTrue()
        ->and($fake->requests())->toHaveCount(2);
});

test('review after rewrite carries every prior actionable finding as a verification checklist', function () {
    $fixture = reviewFixture();
    $reviewRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create(['type' => ArtifactType::ReviewResult]);
    $priorFindings = [
        reviewFinding(code: 'PACING_ISSUE', dimension: 'pacing', autoFixable: true, message: '节奏拖沓。'),
        reviewFinding(autoFixable: true),
    ];
    Review::factory()->create([
        'generation_run_id' => $reviewRun->getKey(),
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Rewrite,
        'findings' => $priorFindings,
    ]);
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse());
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey(), regenerate: true, operationId: 'full-review-verification');

    expect(data_get($review->generationRun->context_snapshot, 'repair_verification.required_findings'))->toHaveCount(2)
        ->and(data_get($review->artifact->data, 'repair_verification'))->toHaveCount(2)
        ->and(collect(data_get($review->artifact->data, 'repair_verification'))->pluck('result')->unique()->all())->toBe(['resolved'])
        ->and($fake->requests()[0]->prompt)->toContain('节奏拖沓。')
        ->and($fake->requests()[0]->prompt)->toContain('主文风不匹配。')
        ->and($fake->requests()[0]->systemPrompt)->toContain('上一轮全部可修复问题是否已经消除');
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
        ->and(data_get($review->artifact->data, 'decision_basis.rule'))->toBe('score_and_advisory_findings');
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
    'auto fixable warning' => [reviewFinding(autoFixable: true), ReviewDecision::Pass, 'score_and_advisory_findings'],
    'non blocking warning' => [reviewFinding(), ReviewDecision::Pass, 'score_and_advisory_findings'],
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
        ->and(data_get($review->artifact->data, 'decision_basis.rule'))->toBe('below_threshold_actionable_warning');
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
    $finding = reviewFinding(severity: 'error', sceneId: $scene->getKey(), scope: 'scene', autoFixable: true);
    $fake = (new FakeAiProvider)->enqueue(reviewResponse(findings: [$finding]));
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and($review->findings)->toContainEqual([...$finding, 'source' => 'narrative_review'])
        ->and(data_get($review->artifact->data, 'rewrite_scope.scope'))->toBe('scene')
        ->and(data_get($review->artifact->data, 'rewrite_scope.scene_id'))->toBe($scene->getKey())
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.findings.items.properties.code.enum'))->toContain('STYLE_MISMATCH')
        ->and(data_get($review->generationRun->context_snapshot, 'scenes.0.id'))->toBe($scene->getKey());
});

test('review consumes structured plan findings and preserves their scene repair route', function () {
    $scene = null;
    $fixture = reviewFixture(function (Chapter $chapter) use (&$scene): array {
        $scene = Scene::factory()->for($chapter)->create(['sequence' => 1]);

        return ['plan_findings' => [[
            'code' => 'SCENE_PLAN_COVERAGE_MISSING',
            'dimension' => 'plan',
            'severity' => 'error',
            'scene_id' => $scene->getKey(),
            'scope' => 'scene',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'plan_element' => 'outcome',
            'coverage_status' => 'missing',
            'expected' => ['description' => '林舟守住城门'],
            'evidence' => null,
            'message' => '场景计划的结果未在正文中落实。',
            'source' => 'assembly_coverage',
        ]]];
    });
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse());
    app()->instance(AiProvider::class, $fake);

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision)->toBe(ReviewDecision::Rewrite)
        ->and(collect($review->findings)->pluck('code'))->toContain('SCENE_PLAN_COVERAGE_MISSING')
        ->and(data_get($review->artifact->data, 'rewrite_scope.scope'))->toBe('scene')
        ->and(data_get($review->artifact->data, 'rewrite_scope.scene_id'))->toBe($scene->getKey())
        ->and(data_get($review->generationRun->context_snapshot, 'plan_findings.0.plan_element'))->toBe('outcome')
        ->and($fake->requests()[0]->systemPrompt)->toContain('plan_findings 是上游结构化覆盖证据');
});

test('a below threshold score without an executable finding is rejected', function () {
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(score: 60)));

    expect(fn () => app(ChapterReviewer::class)->review($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, '没有提供可执行或需要人工决策的 Finding');
});

test('the review after the final rewrite moves unresolved findings to needs attention', function () {
    $incident = require base_path('tests/Fixtures/generation_workflow_quality_incidents.php');
    $recorded = $incident['chapter_11_rewrite_exhaustion'];
    $fixture = reviewFixture();
    foreach (range(1, $recorded['successful_automatic_attempts']) as $attempt) {
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
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse('PASS', 90, [$recorded['remaining_finding']])));

    $review = app(ChapterReviewer::class)->review($fixture['chapter']->getKey());

    expect($review->decision->value)->toBe($recorded['expected_decision'])
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
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(severity: 'error', autoFixable: true)])));

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
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(severity: 'error', autoFixable: true)])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertPushed(RewriteChapterJob::class, 1);
    Queue::assertPushed(RewriteChapterJob::class, fn (RewriteChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey() && $job->sceneId === null);
});

test('review job dispatches a scene rewrite for one local finding', function () {
    Queue::fake();
    $fixture = reviewFixture();
    $scene = Scene::factory()->for($fixture['chapter'])->create();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [
        reviewFinding(severity: 'error', sceneId: $scene->getKey(), scope: 'scene', autoFixable: true),
    ])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertPushed(RewriteChapterJob::class, 1);
    Queue::assertPushed(RewriteChapterJob::class, fn (RewriteChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey()
        && $job->sceneId === $scene->getKey());
});

test('review job upgrades findings from multiple scenes to chapter rewrite', function () {
    Queue::fake();
    $fixture = reviewFixture();
    $first = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $second = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 2]);
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [
        reviewFinding(severity: 'error', sceneId: $first->getKey(), scope: 'scene', autoFixable: true),
        reviewFinding(severity: 'error', sceneId: $second->getKey(), scope: 'scene', autoFixable: true),
    ])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertPushed(RewriteChapterJob::class, 1);
    Queue::assertPushed(RewriteChapterJob::class, fn (RewriteChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey()
        && $job->sceneId === null);
});

test('an unlocatable paragraph finding becomes needs attention without rewrite dispatch', function () {
    Queue::fake();
    $fixture = reviewFixture();
    Scene::factory()->for($fixture['chapter'])->create();
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [
        reviewFinding(severity: 'error', sceneId: null, scope: 'paragraph', autoFixable: true),
    ])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    $review = Review::query()->latest('id')->firstOrFail();
    expect($review->decision)->toBe(ReviewDecision::NeedsAttention)
        ->and(collect($review->findings)->pluck('code'))->toContain('REWRITE_SCOPE_UNRESOLVED')
        ->and(data_get($review->artifact->data, 'rewrite_scope'))->toBeNull()
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Review);
    Queue::assertNotPushed(RewriteChapterJob::class);
});

test('duplicate review job delivery does not dispatch the same rewrite twice', function () {
    Queue::fake();
    $fixture = reviewFixture();
    bindStateValidation(new StateValidationResult([]));
    $fake = (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(severity: 'error', autoFixable: true)]));
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
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(severity: 'error', autoFixable: true)])));
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
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse(findings: [reviewFinding(severity: 'error', autoFixable: true)])));

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

test('review job stops at pass even when legacy auto commit is enabled', function () {
    Queue::fake();
    $fixture = reviewFixture();
    $fixture['novel']->update(['settings' => ['auto_commit' => true]]);
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse()));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertNotPushed(CommitChapterJob::class);
});

test('a provider response is saved but pause prevents the review job from dispatching commit', function () {
    Queue::fake();
    $fixture = reviewFixture();
    $fixture['novel']->update(['settings' => ['auto_commit' => true, 'auto_commit_configured' => true]]);
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
    $fixture['novel']->update(['settings' => ['auto_commit' => true, 'auto_commit_configured' => true]]);
    bindStateValidation(new StateValidationResult([]));
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(reviewResponse('PASS', 60, [reviewFinding(autoFixable: true)])));

    (new ReviewChapterJob($fixture['chapter']->getKey()))->handle(app(ChapterReviewer::class));

    Queue::assertNotPushed(CommitChapterJob::class);
});
