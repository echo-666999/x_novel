<?php

use App\Actions\Chapters\RegenerateSceneSequenceAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\ArtifactType;
use App\Enums\BibleStatus;
use App\Enums\ChapterStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\GenerateSceneJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Services\DraftLengthPolicy;
use App\Services\ForeshadowingCoverage;
use App\Services\ForeshadowingCoverageEvidenceRepairer;
use App\Services\SceneDraftPayload;
use App\Services\SceneDraftStructureRepairer;
use App\Services\SceneGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function sceneGenerationFixture(int $sceneCount = 2): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 20,
        'status' => ChapterStatus::Generating,
    ]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'must_not_reveal' => ['终局真相'],
        'scene_plans' => [],
        'target_words' => $sceneCount * 8,
        'status' => PlanStatus::Ready,
    ]);
    $scenes = collect(range(1, $sceneCount))->map(fn (int $sequence): Scene => Scene::factory()
        ->for($chapter)
        ->create([
            'sequence' => $sequence,
            'goal' => "Scene {$sequence} 目标",
            'status' => SceneStatus::Planned,
        ]));

    return compact('novel', 'chapter', 'plan', 'scenes');
}

function sceneSelfCheck(string $content, array $overrides = []): array
{
    $fulfilled = ['status' => 'fulfilled', 'evidence' => $content];

    return [
        'goal' => $fulfilled,
        'conflict' => $fulfilled,
        'turn' => $fulfilled,
        'outcome' => $fulfilled,
        ...$overrides,
    ];
}

function sceneResponse(string $content, array $delta = [], ?array $selfCheck = null, array $foreshadowingCoverage = []): AiResponse
{
    $payload = [
        'content' => $content,
        'temporary_state_delta' => $delta,
        'declared_events' => [],
        'uncertainties' => [],
        'self_check' => $selfCheck ?? sceneSelfCheck($content),
        'foreshadowing_coverage' => $foreshadowingCoverage,
    ];

    return new AiResponse(
        content: json_encode($payload, JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 100,
        outputTokens: 200,
        cachedTokens: 0,
        latencyMs: 350,
        providerRequestId: 'scene-request',
        model: 'writer-test',
    );
}

function sceneCoverageResponse(array $coverage): AiResponse
{
    return new AiResponse(
        content: json_encode($coverage, JSON_UNESCAPED_UNICODE),
        structuredData: $coverage,
        inputTokens: 50,
        outputTokens: 50,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'coverage-repair-request',
        model: 'writer-test',
    );
}

function sceneForeshadowingAction(array $fixture, int $sceneSequence = 1): Foreshadowing
{
    $foreshadowing = Foreshadowing::factory()->for($fixture['novel'])->create([
        'title' => '染血地图',
        'promised_payoff' => '地图最终指向潮汐门。',
        'status' => ForeshadowingStatus::Planted,
        'due_from_chapter' => 18,
        'due_to_chapter' => 20,
    ]);
    $fixture['plan']->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'pay_off',
        'target_scene_sequence' => $sceneSequence,
        'acceptance_criteria' => '正文明确地图指向潮汐门。',
        'reason' => null,
    ]]]);

    return $foreshadowing;
}

function sceneForeshadowingCoverage(Foreshadowing $foreshadowing, string $status, ?string $evidence): array
{
    return [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'pay_off',
        'status' => $status,
        'evidence' => $evidence,
    ]];
}

function truncatedCoverageResponse(): AiResponse
{
    return new AiResponse(
        content: '',
        structuredData: null,
        inputTokens: 100,
        outputTokens: 1_000,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'truncated-coverage-repair-request',
        model: 'writer-test',
        metadata: ['finish_reason' => 'length', 'refusal' => null],
    );
}

function sceneStructureResponse(string $temporaryStateDelta, array $declaredEvents = []): AiResponse
{
    $payload = [
        'temporary_state_delta' => $temporaryStateDelta,
        'declared_events' => $declaredEvents,
    ];

    return new AiResponse(
        content: json_encode($payload, JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 50,
        outputTokens: 50,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'scene-structure-repair-request',
        model: 'writer-test',
    );
}

test('scene allocation shares the chapter word budget across remaining scenes', function () {
    $policy = app(DraftLengthPolicy::class);

    expect($policy->sceneAllocation(3000, 0, 1)['scene_target_words'])->toBe(3000)
        ->and($policy->sceneAllocation(3000, 0, 4)['scene_target_words'])->toBe(750)
        ->and($policy->sceneAllocation(3000, 0, 4, 4)['minimum_scene_reserve_words'])->toBe(638)
        ->and($policy->sceneAllocation(3000, 0, 4, 4)['future_scene_word_reserve'])->toBe(1914)
        ->and($policy->sceneAllocation(3000, 0, 4, 4)['maximum_scene_words'])->toBe(1536)
        ->and($policy->sceneAllocation(3000, 1286, 3, 4)['maximum_scene_words'])->toBe(888)
        ->and($policy->sceneAllocation(3000, 300, 1)['scene_target_words'])->toBe(2700)
        ->and($policy->sceneAllocation(3000, 300, 1)['required_scene_words'])->toBe(2250);
});

test('draft length excludes spaces tabs and line breaks', function () {
    expect(app(DraftLengthPolicy::class)->count("甲 \n\t 乙\r\n丙"))->toBe(3);
});

test('scene draft schema fixes plan coverage while keeping dynamic state objects encoded', function () {
    $schema = SceneDraftPayload::schema();

    expect($schema['additionalProperties'])->toBeFalse()
        ->and(data_get($schema, 'properties.temporary_state_delta.type'))->toBe('string')
        ->and(data_get($schema, 'properties.declared_events.items.type'))->toBe('string')
        ->and(data_get($schema, 'properties.self_check.type'))->toBe('object')
        ->and(data_get($schema, 'properties.self_check.required'))->toBe(['goal', 'conflict', 'turn', 'outcome'])
        ->and(data_get($schema, 'properties.foreshadowing_coverage.items.required'))->toBe(['foreshadowing_id', 'action', 'status', 'evidence'])
        ->and(data_get($schema, 'properties.self_check.properties.outcome.properties.status.enum'))->toBe(['fulfilled', 'missing', 'contradicted']);

    $payload = SceneDraftPayload::validate([
        'content' => '林舟进入灯塔。',
        'temporary_state_delta' => '{"characters":{"lin_zhou":{"location":"灯塔"}}}',
        'declared_events' => ['{"type":"character_moved","subject":"lin_zhou"}'],
        'uncertainties' => [],
        'self_check' => sceneSelfCheck('林舟进入灯塔。'),
        'foreshadowing_coverage' => [],
    ]);

    expect(data_get($payload, 'temporary_state_delta.characters.lin_zhou.location'))->toBe('灯塔')
        ->and(data_get($payload, 'declared_events.0.type'))->toBe('character_moved')
        ->and(data_get($payload, 'self_check.outcome.status'))->toBe('fulfilled');
});

test('scene foreshadowing coverage validates the assigned action and exact evidence', function () {
    $content = '林舟摊开染血地图，暗红纹路最终指向潮汐门。';
    $expectations = [[
        'foreshadowing_id' => 31,
        'action' => 'pay_off',
        'acceptance_criteria' => '正文明确地图指向潮汐门。',
    ]];

    $coverage = ForeshadowingCoverage::validate([[
        'foreshadowing_id' => 31,
        'action' => 'pay_off',
        'status' => 'fulfilled',
        'evidence' => '暗红纹路最终指向潮汐门',
    ]], $content, $expectations, 'foreshadowing_coverage');

    expect(data_get($coverage, '0.evidence'))->toBe('暗红纹路最终指向潮汐门');
});

test('scene foreshadowing coverage rejects another scene or action target', function () {
    $content = '地图边缘泛起暗红微光。';
    $expectations = [['foreshadowing_id' => 31, 'action' => 'pay_off']];

    expect(fn () => ForeshadowingCoverage::validate([[
        'foreshadowing_id' => 32,
        'action' => 'reinforce',
        'status' => 'fulfilled',
        'evidence' => $content,
    ]], $content, $expectations, 'foreshadowing_coverage'))
        ->toThrow(ValidationException::class, '不能引用其他 Scene、其他伏笔或其他动作');
});

test('missing scene foreshadowing action creates an automatic rewrite finding', function () {
    $fixture = sceneGenerationFixture(1);
    $foreshadowing = sceneForeshadowingAction($fixture);
    $content = '地图边缘泛起暗红微光，但没有显出目的地。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(sceneResponse(
        $content,
        foreshadowingCoverage: sceneForeshadowingCoverage($foreshadowing, 'missing', null),
    )));

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());
    $finding = collect($artifact->data['plan_findings'])->firstWhere('code', 'FORESHADOWING_COVERAGE_MISSING');

    expect($finding)->not->toBeNull()
        ->and($finding['foreshadowing_id'])->toBe($foreshadowing->getKey())
        ->and($finding['foreshadowing_action'])->toBe('pay_off')
        ->and($finding['acceptance_criteria'])->toBe('正文明确地图指向潮汐门。')
        ->and($finding['auto_fixable'])->toBeTrue()
        ->and($finding['source'])->toBe('scene_foreshadowing_coverage');
});

test('scene generator repairs only invalid foreshadowing evidence', function () {
    $fixture = sceneGenerationFixture(1);
    $foreshadowing = sceneForeshadowingAction($fixture);
    $content = '林舟确认染血地图指向潮汐门。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    $invalid = sceneForeshadowingCoverage($foreshadowing, 'fulfilled', '地图指出了潮汐门');
    $valid = sceneForeshadowingCoverage($foreshadowing, 'fulfilled', '染血地图指向潮汐门');
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse($content, foreshadowingCoverage: $invalid))
        ->enqueue(sceneCoverageResponse($valid));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    expect(data_get($artifact->data, 'foreshadowing_coverage.0'))->toMatchArray([
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'pay_off',
        'status' => 'fulfilled',
        'evidence' => '染血地图指向潮汐门',
    ])->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->promptVersion)->toBe(ForeshadowingCoverageEvidenceRepairer::PROMPT_VERSION);
});

test('foreshadowing evidence repair cannot change the declared action result', function () {
    $fixture = sceneGenerationFixture(1);
    $canonicalStateVersionId = $fixture['novel']->fresh()->canonical_state_version_id;
    $foreshadowing = sceneForeshadowingAction($fixture);
    $content = '林舟确认染血地图指向潮汐门。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    $invalid = sceneForeshadowingCoverage($foreshadowing, 'fulfilled', '地图指出了潮汐门');
    $changed = sceneForeshadowingCoverage($foreshadowing, 'missing', null);
    app()->instance(AiProvider::class, (new FakeAiProvider)
        ->enqueue(sceneResponse($content, foreshadowingCoverage: $invalid))
        ->enqueue(sceneCoverageResponse($changed)));

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey()))
        ->toThrow(ValidationException::class, '不得改变数组顺序、伏笔 ID、动作或原 status');

    expect(GenerationArtifact::query()->where('type', ArtifactType::SceneDraft)->count())->toBe(0);
    expect($fixture['novel']->fresh()->canonical_state_version_id)->toBe($canonicalStateVersionId)
        ->and($fixture['novel']->storyEvents()->count())->toBe(0);
});

test('missing or contradicted scene coverage creates stable localized findings', function (string $status, string $code, ?string $evidence) {
    $fixture = sceneGenerationFixture(1);
    $content = '林舟在门前停下，没有进入灯塔。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    $selfCheck = sceneSelfCheck($content, [
        'outcome' => ['status' => $status, 'evidence' => $evidence],
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(sceneResponse($content, selfCheck: $selfCheck)));

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());
    $finding = data_get($artifact->data, 'plan_findings.0');

    expect($finding['code'])->toBe($code)
        ->and($finding['scene_id'])->toBe($fixture['scenes']->first()->getKey())
        ->and($finding['plan_element'])->toBe('outcome')
        ->and($finding['coverage_status'])->toBe($status)
        ->and($finding['expected'])->toBe([
            'description' => $fixture['scenes']->first()->outcome,
            'allowed' => [],
            'forbidden' => [],
        ])
        ->and($finding['source'])->toBe('scene_self_check');
})->with([
    'missing outcome' => ['missing', 'SCENE_PLAN_COVERAGE_MISSING', null],
    'contradicted outcome' => ['contradicted', 'SCENE_PLAN_COVERAGE_CONTRADICTED', '没有进入灯塔'],
]);

test('scene coverage evidence must quote the generated scene exactly', function () {
    $content = '林舟进入灯塔。';
    $selfCheck = sceneSelfCheck($content, [
        'outcome' => ['status' => 'fulfilled', 'evidence' => '他进入了灯塔'],
    ]);

    expect(fn () => SceneDraftPayload::validate([
        'content' => $content,
        'temporary_state_delta' => '{}',
        'declared_events' => [],
        'uncertainties' => [],
        'self_check' => $selfCheck,
        'foreshadowing_coverage' => [],
    ]))
        ->toThrow(ValidationException::class, '必须逐字来自当前正文');
});

test('scene coverage resolves whitespace-only formatting differences to an exact quote', function () {
    $content = "林舟推开门。\n\n塔内一片漆黑。";
    $payload = SceneDraftPayload::validate([
        'content' => $content,
        'temporary_state_delta' => '{}',
        'declared_events' => [],
        'uncertainties' => [],
        'self_check' => sceneSelfCheck($content, [
            'turn' => ['status' => 'fulfilled', 'evidence' => '林舟推开门。塔内一片漆黑。'],
        ]),
        'foreshadowing_coverage' => [],
    ]);

    expect(data_get($payload, 'self_check.turn.evidence'))->toBe($content)
        ->and(str_contains($content, data_get($payload, 'self_check.turn.evidence')))->toBeTrue();
});

test('scene coverage resolves a high confidence transcription difference to an exact quote', function () {
    $exact = '林舟沿着生锈的螺旋楼梯一步步走上灯塔顶层，始终没有松开手中的钥匙。';
    $content = '风雨拍打窗户。'.$exact.'门后传来钟声。';
    $payload = SceneDraftPayload::validate([
        'content' => $content,
        'temporary_state_delta' => '{}',
        'declared_events' => [],
        'uncertainties' => [],
        'self_check' => sceneSelfCheck($content, [
            'turn' => [
                'status' => 'fulfilled',
                'evidence' => '林舟沿着生锈的螺旋楼梯一步步走上灯塔顶层，始终没有松开手里的钥匙。',
            ],
        ]),
        'foreshadowing_coverage' => [],
    ]);

    expect(data_get($payload, 'self_check.turn.evidence'))->toBe('林舟沿着生锈的螺旋楼梯一步步走上灯塔顶层，始终没有松开手')
        ->and(str_contains($content, data_get($payload, 'self_check.turn.evidence')))->toBeTrue();
});

test('scene generator repairs invalid coverage evidence without rewriting the prose', function () {
    $fixture = sceneGenerationFixture(1);
    $content = '林舟进入灯塔。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    $invalidCoverage = sceneSelfCheck($content, [
        'outcome' => ['status' => 'fulfilled', 'evidence' => '他进入了灯塔'],
    ]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse($content, selfCheck: $invalidCoverage))
        ->enqueue(sceneCoverageResponse(sceneSelfCheck($content)));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    expect($artifact?->content)->toBe($content)
        ->and(data_get($artifact?->data, 'self_check.outcome.evidence'))->toBe($content)
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->promptVersion)->toBe('coverage-evidence-repair-v1')
        ->and($fake->requests()[1]->maxTokens)->toBe(1_000)
        ->and(data_get($fake->requests()[1]->metadata, 'coverage_repair_attempt'))->toBe(1);
});

test('scene generator repairs malformed support fields without rewriting the prose', function () {
    $fixture = sceneGenerationFixture(1);
    $content = '林舟进入灯塔。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    $invalidPayload = [
        'content' => $content,
        'temporary_state_delta' => '{"林舟仍在灯塔。"}',
        'declared_events' => ['{"event":"林舟进入灯塔"}'],
        'uncertainties' => [],
        'self_check' => sceneSelfCheck($content),
        'foreshadowing_coverage' => [],
    ];
    $fake = (new FakeAiProvider)
        ->enqueue(new AiResponse(
            content: json_encode($invalidPayload, JSON_UNESCAPED_UNICODE),
            structuredData: $invalidPayload,
            inputTokens: 100,
            outputTokens: 200,
            cachedTokens: 0,
            latencyMs: 350,
            providerRequestId: 'malformed-scene-request',
            model: 'writer-test',
        ))
        ->enqueue(sceneStructureResponse(
            '{"characters":{"lin_zhou":{"location":"灯塔"}}}',
            ['{"event":"林舟进入灯塔"}'],
        ));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    expect($artifact?->content)->toBe($content)
        ->and(data_get($artifact?->data, 'temporary_state_delta.characters.lin_zhou.location'))->toBe('灯塔')
        ->and(data_get($artifact?->data, 'declared_events.0.event'))->toBe('林舟进入灯塔')
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->promptVersion)->toBe(SceneDraftStructureRepairer::PROMPT_VERSION)
        ->and(data_get($fake->requests()[1]->metadata, 'scene_structure_repair_attempt'))->toBe(1);
});

test('scene support field repair and coverage evidence repair can run in sequence', function () {
    $fixture = sceneGenerationFixture(1);
    $content = '林舟进入灯塔。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    $invalidPayload = [
        'content' => $content,
        'temporary_state_delta' => '{"林舟仍在灯塔。"}',
        'declared_events' => [],
        'uncertainties' => [],
        'self_check' => sceneSelfCheck($content, [
            'outcome' => ['status' => 'fulfilled', 'evidence' => '他进入了灯塔'],
        ]),
        'foreshadowing_coverage' => [],
    ];
    $fake = (new FakeAiProvider)
        ->enqueue(new AiResponse(
            content: json_encode($invalidPayload, JSON_UNESCAPED_UNICODE),
            structuredData: $invalidPayload,
            inputTokens: 100,
            outputTokens: 200,
            cachedTokens: 0,
            latencyMs: 350,
            providerRequestId: 'malformed-scene-request',
            model: 'writer-test',
        ))
        ->enqueue(sceneStructureResponse('{}'))
        ->enqueue(sceneCoverageResponse(sceneSelfCheck($content)));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    expect($artifact?->content)->toBe($content)
        ->and(data_get($artifact?->data, 'self_check.outcome.evidence'))->toBe($content)
        ->and($fake->requests())->toHaveCount(3)
        ->and($fake->requests()[1]->promptVersion)->toBe(SceneDraftStructureRepairer::PROMPT_VERSION)
        ->and($fake->requests()[2]->promptVersion)->toBe('coverage-evidence-repair-v1');
});

test('coverage evidence repair cannot change the original status', function () {
    $fixture = sceneGenerationFixture(1);
    $content = '林舟进入灯塔。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    $invalidCoverage = sceneSelfCheck($content, [
        'outcome' => ['status' => 'fulfilled', 'evidence' => '他进入了灯塔'],
    ]);
    $changedCoverage = sceneSelfCheck($content, [
        'outcome' => ['status' => 'missing', 'evidence' => null],
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)
        ->enqueue(sceneResponse($content, selfCheck: $invalidCoverage))
        ->enqueue(sceneCoverageResponse($changedCoverage)));

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey()))
        ->toThrow(ValidationException::class, 'Coverage 证据修复不得改变原 status');

    expect(GenerationArtifact::query()->where('type', ArtifactType::SceneDraft)->count())->toBe(0);
});

test('coverage evidence repair retries with a larger budget after a truncated response', function () {
    $fixture = sceneGenerationFixture(1);
    $content = '林舟进入灯塔。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    $invalidCoverage = sceneSelfCheck($content, [
        'outcome' => ['status' => 'fulfilled', 'evidence' => '他进入了灯塔'],
    ]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse($content, selfCheck: $invalidCoverage))
        ->enqueue(truncatedCoverageResponse())
        ->enqueue(sceneCoverageResponse(sceneSelfCheck($content)));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    expect($artifact?->content)->toBe($content)
        ->and($fake->requests())->toHaveCount(3)
        ->and($fake->requests()[1]->maxTokens)->toBe(1_000)
        ->and(data_get($fake->requests()[1]->metadata, 'coverage_repair_attempt'))->toBe(1)
        ->and($fake->requests()[2]->maxTokens)->toBe(4_000)
        ->and(data_get($fake->requests()[2]->metadata, 'coverage_repair_attempt'))->toBe(2);
});

test('unverifiable coverage evidence becomes a rewrite finding after repair is exhausted', function () {
    $fixture = sceneGenerationFixture(1);
    $content = '林舟进入灯塔。';
    $fixture['plan']->update(['target_words' => mb_strlen($content)]);
    $invalidCoverage = sceneSelfCheck($content, [
        'outcome' => ['status' => 'fulfilled', 'evidence' => '这里没有任何可核对的原文'],
    ]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse($content, selfCheck: $invalidCoverage))
        ->enqueue(truncatedCoverageResponse())
        ->enqueue(truncatedCoverageResponse());
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    expect(data_get($artifact?->data, 'self_check.outcome'))->toBe([
        'status' => 'missing',
        'evidence' => null,
    ])->and(data_get($artifact?->data, 'plan_findings.0.code'))->toBe('SCENE_PLAN_COVERAGE_MISSING')
        ->and($fake->requests())->toHaveCount(3);
});

test('scene self check rejects an incomplete fixed schema', function () {
    $payload = [
        'content' => '林舟进入灯塔。',
        'temporary_state_delta' => '{}',
        'declared_events' => [],
        'uncertainties' => [],
        'self_check' => collect(sceneSelfCheck('林舟进入灯塔。'))->except('outcome')->all(),
        'foreshadowing_coverage' => [],
    ];

    expect(fn () => SceneDraftPayload::validate($payload))
        ->toThrow(ValidationException::class, '必须完整包含 goal、conflict、turn、outcome');
});

test('scene generator persists an immutable draft artifact and temporary state delta', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update([
        'target_words' => 11,
        'scene_plans' => [[
            'transition_from_previous' => '先写抵达学院和入住过程，再进入次日清晨。',
            'outcome_allowed' => ['进入灯塔大厅并保持警戒'],
            'outcome_forbidden' => ['直接取得灯塔控制权'],
        ]],
    ]);
    $fixture['novel']->update(['settings' => ['editorial' => ['primary_style' => 'light_humorous', 'secondary_styles' => [], 'style_parameters' => []]]]);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('雨幕中，林舟推开了门。', [
        'characters' => ['lin_zhou' => ['location' => '灯塔']],
    ]));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());
    $scene = $fixture['scenes']->first()->fresh();
    $run = $scene->generationRuns()->sole();
    $inputContext = $run->context_snapshot;
    unset($inputContext['regeneration_batch_id']);
    $expectedInputHash = hash('sha256', json_encode([
        'context' => $inputContext,
        'model' => $run->model_policy,
        'prompt_version' => $run->prompt_version,
        'regeneration_batch_id' => null,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

    expect($artifact->type)->toBe(ArtifactType::SceneDraft)
        ->and($artifact->content)->toBe('雨幕中，林舟推开了门。')
        ->and(data_get($artifact->data, 'temporary_state_delta.characters.lin_zhou.location'))->toBe('灯塔')
        ->and(data_get($artifact->data, 'word_count'))->toBe(11)
        ->and(data_get($artifact->data, 'target_words'))->toBe(11)
        ->and($scene->status)->toBe(SceneStatus::Draft)
        ->and($scene->current_artifact_id)->toBe($artifact->getKey())
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->stage)->toBe(GenerationStage::SceneGeneration)
        ->and($run->context_snapshot)->toHaveKeys(['l0', 'l1', 'l2', 'l4', 'style_contract_checksum', 'foreshadowing_contract_checksum', 'scene_task', 'temporary_state'])
        ->and($run->bible_version)->toBe(1)
        ->and($run->input_hash)->toBe($expectedInputHash)
        ->and(data_get($run->context_snapshot, 'l4.checksum'))->toBe(data_get($run->context_snapshot, 'style_contract_checksum'));
    expect(data_get($run->context_snapshot, 'writing_constraints.scene_target_words'))->toBe($fixture['plan']->target_words)
        ->and(data_get($run->context_snapshot, 'scene_task.transition_from_previous'))->toBe('先写抵达学院和入住过程，再进入次日清晨。')
        ->and(data_get($run->context_snapshot, 'scene_task.outcome_allowed'))->toBe(['进入灯塔大厅并保持警戒'])
        ->and(data_get($run->context_snapshot, 'scene_task.outcome_forbidden'))->toBe(['直接取得灯塔控制权'])
        ->and(data_get($run->context_snapshot, 'writing_constraints'))->not->toHaveKey('style_profile')
        ->and(data_get($run->context_snapshot, 'l4.primary_style.name'))->toBe('通俗爽快')
        ->and(data_get($run->context_snapshot, 'l4.primary_style.instruction'))->toContain('语言直接易读')
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得从上一章结尾直接跳到次日')
        ->and($fake->requests()[0]->systemPrompt)->toContain('l0.foreshadowing_contract 是本章冻结的唯一伏笔动作契约');
});

test('scene snapshots keep the bible version frozen for the current chapter plan', function () {
    $fixture = sceneGenerationFixture(2);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse(str_repeat('甲', 8)))
        ->enqueue(sceneResponse(str_repeat('乙', 8)));
    app()->instance(AiProvider::class, $fake);

    app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    $firstBible = $fixture['novel']->currentBible()->firstOrFail();
    $firstBible->update(['status' => BibleStatus::Superseded]);
    NovelBible::factory()->for($fixture['novel'])->create([
        'version' => 2,
        'tone' => '冷峻',
        'style_profile' => array_replace($firstBible->style_profile, ['primary_style' => 'austere']),
        'status' => BibleStatus::Current,
    ]);

    app(SceneGenerator::class)->generate($fixture['scenes']->last()->getKey());

    $runs = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::SceneGeneration)->oldest('id')->get();

    expect($runs->pluck('bible_version')->all())->toBe([1, 1])
        ->and($runs->pluck('context_snapshot')->map(fn (array $snapshot): mixed => data_get($snapshot, 'l4.bible_version'))->all())->toBe([1, 1])
        ->and($runs->pluck('context_snapshot')->map(fn (array $snapshot): mixed => data_get($snapshot, 'style_contract_checksum'))->unique()->count())->toBe(1)
        ->and(data_get($runs->last()->context_snapshot, 'l4.tone'))->toBe($firstBible->tone)
        ->and(data_get($runs->last()->context_snapshot, 'l4.primary_style.name'))->toBe('通俗爽快');
});

test('a final scene budget shortfall is expanded once before the chapter is blocked', function () {
    Queue::fake();
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse('过短场景'))
        ->enqueue(sceneResponse('仍然过短'));
    app()->instance(AiProvider::class, $fake);

    (new GenerateSceneJob($fixture['scenes']->first()->getKey()))->handle(app(SceneGenerator::class));

    Queue::assertNothingPushed();
    expect($fixture['scenes']->first()->fresh()->status)->toBe(SceneStatus::Failed)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked)
        ->and($fixture['scenes']->first()->generationRuns()->where('error_code', 'scene_budget_shortfall')->count())->toBe(1)
        ->and($fixture['scenes']->first()->generationRuns()->whereHas('artifacts')->count())->toBe(0);
    expect($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->systemPrompt)->toContain('场景扩写器')
        ->and($fake->requests()[1]->systemPrompt)->toContain('POV、时态、主文风')
        ->and($fake->requests()[1]->prompt)->toContain('"l4"')
        ->and($fake->requests()[1]->prompt)->toContain('通俗爽快');
});

test('a successful length expansion becomes the scene draft', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse(str_repeat('短', 20)))
        ->enqueue(sceneResponse(str_repeat('扩', 90)));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    expect($artifact?->content)->toBe(str_repeat('扩', 90))
        ->and($artifact?->data['word_count'])->toBe(90)
        ->and($fixture['scenes']->first()->fresh()->status)->toBe(SceneStatus::Draft)
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->prompt)->toContain('终局真相');
});

test('an overlength scene is compressed once before it becomes the current draft', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse(str_repeat('超', 120)))
        ->enqueue(sceneResponse(str_repeat('改', 100)));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey());

    expect($artifact->content)->toBe(str_repeat('改', 100))
        ->and($artifact->data['word_count'])->toBe(100)
        ->and($fixture['scenes']->first()->fresh()->current_artifact_id)->toBe($artifact->getKey())
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[1]->systemPrompt)->toContain('场景压缩器');
});

test('an overlength scene is rejected when compression still exceeds the hard maximum', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update(['target_words' => 100]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse(str_repeat('超', 120)))
        ->enqueue(sceneResponse(str_repeat('仍', 116)));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey()))
        ->toThrow(AiProviderException::class, '最多允许 115 字');

    expect($fixture['scenes']->first()->fresh()->current_artifact_id)->toBeNull()
        ->and($fixture['scenes']->first()->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($fixture['scenes']->first()->generationRuns()->whereHas('artifacts')->count())->toBe(0)
        ->and($fake->requests())->toHaveCount(2);
});

test('cascade generation shifts an early scene shortfall to the next scene', function () {
    Queue::fake();
    $fixture = sceneGenerationFixture(2);
    $fixture['plan']->update(['target_words' => 3000]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse(str_repeat('短', 300)))
        ->enqueue(sceneResponse(str_repeat('长', 2700)));
    app()->instance(AiProvider::class, $fake);
    $batchId = 'regenerate-batch-test';

    (new GenerateSceneJob(
        sceneId: $fixture['scenes']->first()->getKey(),
        cascade: true,
        regenerationBatchId: $batchId,
    ))->handle(app(SceneGenerator::class));

    $nextJob = Queue::pushed(GenerateSceneJob::class)->sole();
    expect($nextJob->sceneId)->toBe($fixture['scenes']->last()->getKey())
        ->and($nextJob->cascade)->toBeTrue()
        ->and($nextJob->regenerationBatchId)->toBe($batchId);

    $nextJob->handle(app(SceneGenerator::class));

    $firstConstraints = $fixture['scenes']->first()->generationRuns()->sole()->context_snapshot['writing_constraints'];
    $lastConstraints = $fixture['scenes']->last()->generationRuns()->sole()->context_snapshot['writing_constraints'];

    expect($firstConstraints['scene_target_words'])->toBe(1500)
        ->and($firstConstraints['required_scene_words'])->toBe(0)
        ->and($lastConstraints['allocated_scene_words'])->toBe(300)
        ->and($lastConstraints['remaining_scene_count'])->toBe(1)
        ->and($lastConstraints['scene_target_words'])->toBe(2700)
        ->and($lastConstraints['required_scene_words'])->toBe(2250)
        ->and($fixture['scenes']->sum(fn (Scene $scene): int => mb_strlen($scene->fresh()->currentArtifact->content)))->toBe(3000)
        ->and($fake->requests())->toHaveCount(2);
});

test('cascade regeneration resets the selected and later scenes while preserving history', function () {
    Queue::fake();
    $fixture = sceneGenerationFixture(3);
    $artifactIds = $fixture['scenes']->map(function (Scene $scene) use ($fixture): int {
        $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->for($scene)->create([
            'stage' => GenerationStage::SceneGeneration,
            'status' => RunStatus::Succeeded,
        ]);
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::SceneDraft,
            'content' => str_repeat((string) $scene->sequence, 10),
        ]);
        $scene->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);

        return $artifact->getKey();
    });
    $fixture['chapter']->update(['status' => ChapterStatus::Review]);

    $count = app(RegenerateSceneSequenceAction::class)->handle($fixture['scenes'][1]);

    expect($count)->toBe(2)
        ->and($fixture['scenes'][0]->fresh()->current_artifact_id)->toBe($artifactIds[0])
        ->and($fixture['scenes'][0]->fresh()->status)->toBe(SceneStatus::Draft)
        ->and($fixture['scenes'][1]->fresh()->current_artifact_id)->toBeNull()
        ->and($fixture['scenes'][1]->fresh()->status)->toBe(SceneStatus::Planned)
        ->and($fixture['scenes'][2]->fresh()->current_artifact_id)->toBeNull()
        ->and($fixture['scenes'][2]->fresh()->status)->toBe(SceneStatus::Planned)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and(GenerationArtifact::query()->whereKey($artifactIds)->count())->toBe(3);

    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $fixture['scenes'][1]->getKey()
        && $job->cascade
        && $job->regenerationBatchId !== null);
});

test('scene two cannot execute before scene one succeeds', function () {
    $fixture = sceneGenerationFixture();
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('不应生成'));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->last()->getKey()))
        ->toThrow(AiProviderException::class, '必须等待 Scene 1 成功');

    expect($fake->requests())->toHaveCount(0)
        ->and(GenerationRun::query()->count())->toBe(0);
});

test('the next scene receives previous temporary state and scene tail', function () {
    $fixture = sceneGenerationFixture();
    $fixture['plan']->update(['target_words' => 20]);
    $fake = (new FakeAiProvider)
        ->enqueue(sceneResponse('第一幕：门后传来钟声', ['items' => ['key' => ['owner' => '林舟']]]))
        ->enqueue(sceneResponse('第二幕承接钟声继续。'));
    app()->instance(AiProvider::class, $fake);
    $generator = app(SceneGenerator::class);

    $generator->generate($fixture['scenes']->first()->getKey());
    $generator->generate($fixture['scenes']->last()->getKey());
    $secondPrompt = $fake->requests()[1]->prompt;

    expect($secondPrompt)->toContain('门后传来钟声')
        ->and($secondPrompt)->toContain('"owner":"林舟"');
});

test('duplicate delivery reuses the successful scene artifact without another provider call', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update(['target_words' => 14]);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('唯一的 Scene Draft。'));
    app()->instance(AiProvider::class, $fake);
    $generator = app(SceneGenerator::class);
    $sceneId = $fixture['scenes']->first()->getKey();

    $first = $generator->generate($sceneId);
    $second = $generator->generate($sceneId);

    expect($second?->is($first))->toBeTrue()
        ->and($fake->requests())->toHaveCount(1)
        ->and(GenerationRun::query()->count())->toBe(1);
});

test('regenerating a scene preserves the previous artifact and returns the chapter to generation', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update(['target_words' => 11]);
    $scene = $fixture['scenes']->first();
    $previousRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->for($scene)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $previousArtifact = GenerationArtifact::factory()->for($previousRun)->create([
        'type' => ArtifactType::SceneDraft,
        'content' => '旧场景草稿内容。',
    ]);
    $scene->update([
        'status' => SceneStatus::Draft,
        'current_artifact_id' => $previousArtifact->getKey(),
    ]);
    $fixture['chapter']->update(['status' => ChapterStatus::Review]);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('重新生成后的场景正文。'));
    app()->instance(AiProvider::class, $fake);

    $newArtifact = app(SceneGenerator::class)->generate($scene->getKey(), true);

    expect($newArtifact?->getKey())->not->toBe($previousArtifact->getKey())
        ->and($previousArtifact->version)->toBe(1)
        ->and($newArtifact?->version)->toBe(2)
        ->and($scene->fresh()->current_artifact_id)->toBe($newArtifact?->getKey())
        ->and($previousArtifact->fresh())->not->toBeNull()
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($scene->generationRuns()->count())->toBe(2)
        ->and($fake->requests())->toHaveCount(1);
});

test('canonical chapter scenes cannot be regenerated directly', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['chapter']->update(['status' => ChapterStatus::Canonical]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey(), true))
        ->toThrow(AiProviderException::class, '正式章节不能直接重新生成场景');

    expect($fake->requests())->toHaveCount(0)
        ->and(GenerationRun::query()->count())->toBe(0);
});

test('a stale running scene is marked interrupted and resumed with a new run', function () {
    $fixture = sceneGenerationFixture(1);
    $scene = $fixture['scenes']->first();
    $stale = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->for($scene)->create([
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Running,
        'attempt' => 1,
        'updated_at' => now()->subSeconds((int) config('generation.stalled_run_after_seconds') + 1),
    ]);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('恢复后生成成功。'));
    app()->instance(AiProvider::class, $fake);

    app(SceneGenerator::class)->generate($scene->getKey());

    expect($stale->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->fresh()->error_code)->toBe('worker_interrupted')
        ->and($scene->generationRuns()->count())->toBe(2)
        ->and($scene->fresh()->status)->toBe(SceneStatus::Draft);
});

test('retryable provider failures are recorded and rethrown for queue retry', function () {
    Queue::fake();
    $fixture = sceneGenerationFixture(1);
    $fixture['plan']->update(['target_words' => 12]);
    $fake = (new FakeAiProvider)->enqueue(new AiProviderException('provider_timeout', 'timeout', true));
    app()->instance(AiProvider::class, $fake);
    $job = new GenerateSceneJob($fixture['scenes']->first()->getKey());

    expect(fn () => $job->handle(app(SceneGenerator::class)))
        ->toThrow(AiProviderException::class, 'timeout');

    expect($fixture['scenes']->first()->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($fixture['scenes']->first()->generationRuns()->sole()->error_code)->toBe('provider_timeout')
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating);

    $fake->enqueue(sceneResponse('重试后成功的 Scene。'));
    $job->handle(app(SceneGenerator::class));

    expect($fixture['scenes']->first()->fresh()->status)->toBe(SceneStatus::Draft)
        ->and($fixture['scenes']->first()->generationRuns()->count())->toBe(2)
        ->and($fixture['scenes']->first()->generationRuns()->latest('id')->first()->attempt)->toBe(2)
        ->and($fake->requests())->toHaveCount(2);
});

test('truncated structured scene output is retried as a technical failure', function () {
    Queue::fake();
    $fixture = sceneGenerationFixture(1);
    $fake = (new FakeAiProvider)->enqueue(new AiResponse(
        content: '',
        structuredData: null,
        inputTokens: 100,
        outputTokens: 4_000,
        cachedTokens: 0,
        latencyMs: 350,
        providerRequestId: 'truncated-scene-request',
        model: 'writer-test',
        metadata: ['finish_reason' => 'length', 'refusal' => null],
    ));
    app()->instance(AiProvider::class, $fake);
    $job = new GenerateSceneJob($fixture['scenes']->first()->getKey());

    expect(fn () => $job->handle(app(SceneGenerator::class)))
        ->toThrow(AiProviderException::class, '按技术故障重试');

    $run = $fixture['scenes']->first()->generationRuns()->sole();
    expect($run->error_code)->toBe('scene_output_truncated')
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating);
});

test('terminal failure blocks the scene and chapter while preserving earlier artifacts', function () {
    $fixture = sceneGenerationFixture();
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('已经完成的第一幕。'));
    app()->instance(AiProvider::class, $fake);
    $generator = app(SceneGenerator::class);
    $first = $fixture['scenes']->first();
    $second = $fixture['scenes']->last();
    $artifact = $generator->generate($first->getKey());

    (new GenerateSceneJob($second->getKey()))->failed(new AiProviderException('provider_timeout', 'timeout', true));

    expect($first->fresh()->current_artifact_id)->toBe($artifact?->getKey())
        ->and($second->fresh()->status)->toBe(SceneStatus::Failed)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Blocked);
});

test('paused novels cannot start a new scene generation stage', function () {
    $fixture = sceneGenerationFixture(1);
    $fixture['novel']->update(['status' => NovelStatus::Paused]);
    app()->instance(AiProvider::class, new FakeAiProvider);

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');

    expect(GenerationRun::query()->count())->toBe(0);
});

test('obvious must not reveal violations fail before persisting an artifact', function () {
    $fixture = sceneGenerationFixture(1);
    $fake = (new FakeAiProvider)->enqueue(sceneResponse('这一幕直接说出了终局真相。'));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(SceneGenerator::class)->generate($fixture['scenes']->first()->getKey()))
        ->toThrow(AiProviderException::class, '禁止揭示内容');

    expect($fixture['scenes']->first()->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($fixture['scenes']->first()->fresh()->current_artifact_id)->toBeNull();
});
