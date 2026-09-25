<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Data\StoryEventCandidate;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\ReviewChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Models\StoryStateVersion;
use App\Services\DeterministicStoryEventApplier;
use App\Services\StoryEventExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function eventExtractionFixture(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Generating]);
    ChapterPlan::factory()->for($chapter)->create();
    $character = Character::factory()->for($novel)->create(['name' => '林舟']);
    $assemblyRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $content = '林舟终于抵达洛阳城下。城门在身后关闭。';
    $draft = GenerationArtifact::factory()->for($assemblyRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);

    return compact('novel', 'state', 'chapter', 'character', 'draft');
}

function eventExtractionResponse(array $fixture, array $overrides = []): AiResponse
{
    $event = [
        'event_type' => EventType::CharacterMoved->value,
        'subject_type' => 'character',
        'subject_id' => (string) $fixture['character']->getKey(),
        'payload' => ['from' => '长安', 'to' => '洛阳'],
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => null,
            'quote' => '林舟终于抵达洛阳城下。',
            'start_offset' => 0,
            'end_offset' => 12,
        ]],
        'story_time' => '第三日黄昏',
        'confidence' => 0.96,
        ...$overrides,
    ];

    return new AiResponse(
        content: json_encode(['events' => [$event]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: ['events' => [$event]],
        inputTokens: 200,
        outputTokens: 100,
        cachedTokens: 0,
        latencyMs: 300,
        providerRequestId: 'event-request',
        model: 'extractor-test',
    );
}

function eventEvidenceRepairResponse(array $quotes): AiResponse
{
    $payload = ['quotes' => $quotes];

    return new AiResponse(
        content: json_encode($payload, JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 50,
        outputTokens: 50,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'event-evidence-repair-request',
        model: 'extractor-test',
    );
}

function truncatedEventEvidenceResponse(): AiResponse
{
    return new AiResponse(
        content: '',
        structuredData: null,
        inputTokens: 100,
        outputTokens: 1_000,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'truncated-event-evidence-repair-request',
        model: 'extractor-test',
        metadata: ['finish_reason' => 'length', 'refusal' => null],
    );
}

function truncatedEventExtractionResponse(): AiResponse
{
    return new AiResponse(
        content: '',
        structuredData: null,
        inputTokens: 100,
        outputTokens: 100,
        cachedTokens: 0,
        latencyMs: 100,
        providerRequestId: 'truncated-event-extraction-request',
        model: 'extractor-test',
        metadata: ['finish_reason' => 'length', 'refusal' => null],
    );
}

function eventForeshadowingFixture(ForeshadowingStatus $status, array $actions, array $coverages): array
{
    $fixture = eventExtractionFixture();
    $foreshadowing = Foreshadowing::factory()->for($fixture['novel'])->create([
        'title' => '染血地图',
        'promised_payoff' => '地图最终指向潮汐门。',
        'status' => $status,
    ]);
    $stateData = $fixture['state']->state;
    data_set($stateData, "foreshadowings.{$foreshadowing->getKey()}.status", $status->value);
    $fixture['state'] = StoryStateVersion::factory()->for($fixture['novel'])->create([
        'version' => $fixture['state']->version + 1,
        'state' => $stateData,
    ]);
    $fixture['novel']->update(['canonical_state_version_id' => $fixture['state']->getKey()]);
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $actions = collect($actions)->map(fn (array $action): array => [
        'foreshadowing_id' => $foreshadowing->getKey(),
        'target_scene_sequence' => 1,
        'acceptance_criteria' => $action['acceptance_criteria'] ?? '正文明确完成伏笔动作。',
        'reason' => null,
        ...$action,
    ])->all();
    $fixture['chapter']->latestPlan->update(['foreshadowing_actions' => $actions]);
    $data = ['scene_coverage' => [[
        'scene_id' => $scene->getKey(),
        'foreshadowing_coverage' => collect($coverages)->map(fn (array $coverage): array => [
            'foreshadowing_id' => $foreshadowing->getKey(),
            ...$coverage,
        ])->all(),
    ]]];
    $content = '林舟终于抵达洛阳城下。林舟摊开染血地图，地图边缘显出旧王印记。地图最终指向潮汐门。';
    $fixture['draft'] = GenerationArtifact::factory()->for($fixture['draft']->generationRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'version' => 2,
        'content' => $content,
        'data' => $data,
        'checksum' => hash('sha256', $content),
    ]);
    $fixture['foreshadowing'] = $foreshadowing;
    $fixture['scene'] = $scene;

    return $fixture;
}

function foreshadowingEventResponse(array $fixture, array $events): AiResponse
{
    $payload = ['events' => collect($events)->map(fn (array $event): array => [
        'event_type' => $event['event_type'],
        'subject_type' => 'foreshadowing',
        'subject_id' => (string) $fixture['foreshadowing']->getKey(),
        'payload' => [],
        'evidence' => $event['evidence'] ?? [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => array_key_exists('scene_id', $event) ? $event['scene_id'] : $fixture['scene']->getKey(),
            'quote' => $event['quote'],
            'start_offset' => null,
            'end_offset' => null,
        ]],
        'story_time' => null,
        'confidence' => 0.98,
    ])->all()];

    return new AiResponse(
        content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 200,
        outputTokens: 100,
        cachedTokens: 0,
        latencyMs: 300,
        providerRequestId: 'foreshadowing-event-request',
        model: 'extractor-test',
    );
}

test('story event candidate validates the documented event shape', function () {
    $fixture = eventExtractionFixture();
    $event = eventExtractionResponse($fixture)->structuredData['events'][0];
    $candidate = StoryEventCandidate::fromArray($event);

    expect($candidate->eventType)->toBe(EventType::CharacterMoved)
        ->and($candidate->subjectId)->toBe((string) $fixture['character']->getKey())
        ->and($candidate->confidence)->toBe(0.96)
        ->and($candidate->evidence[0]['artifact_id'])->toBe($fixture['draft']->getKey());
});

test('story event candidate schema supports strict output and restores a JSON payload', function () {
    $fixture = eventExtractionFixture();
    $event = eventExtractionResponse($fixture)->structuredData['events'][0];
    $event['payload'] = '{"from":"长安","to":"洛阳"}';

    $candidate = StoryEventCandidate::fromArray($event);

    expect(data_get(StoryEventCandidate::schema(), 'properties.payload.type'))->toBe('string')
        ->and(data_get(StoryEventCandidate::schema(), 'properties.event_type.enum'))->not->toContain(EventType::ForeshadowingDue->value)
        ->and(data_get(StoryEventCandidate::schema(), 'properties.subject_type.enum'))->toContain('world_entity', null)
        ->and(data_get(StoryEventCandidate::schema(), 'properties.subject_type.enum'))->not->toContain('concept')
        ->and($candidate->payload)->toBe(['from' => '长安', 'to' => '洛阳']);
});

test('extractor accepts a character introduced event only for a frozen plan candidate', function () {
    $fixture = eventExtractionFixture();
    $fixture['chapter']->latestPlan->update(['character_candidates' => [[
        'candidate_key' => 'character-guide', 'name' => '林舟', 'role' => '向导', 'motivation' => '进入洛阳。',
        'profile' => [], 'personality' => [], 'abilities' => [], 'knowledge' => [],
        'deduplication_basis' => '测试候选。', 'possible_duplicate_character_ids' => [],
        'introduction_reason' => '推动主线。', 'target_scene_sequence' => 1,
    ]]]);
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'event_type' => EventType::CharacterIntroduced->value,
        'subject_type' => 'character',
        'subject_id' => 'character-guide',
        'payload' => ['candidate_key' => 'character-guide'],
    ]));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact?->data, 'events.0.event_type'))->toBe(EventType::CharacterIntroduced->value)
        ->and(data_get($artifact?->data, 'events.0.subject_id'))->toBe('character-guide')
        ->and($fake->requests()[0]->systemPrompt)->toContain('character_introduced');
});

test('a historical due event remains readable but cannot overwrite foreshadowing content state', function () {
    $candidate = StoryEventCandidate::fromArray([
        'event_type' => EventType::ForeshadowingDue->value,
        'subject_type' => 'foreshadowing',
        'subject_id' => '1',
        'payload' => '{}',
        'evidence' => [[
            'artifact_id' => 1,
            'scene_id' => null,
            'quote' => '旧时限标记',
            'start_offset' => null,
            'end_offset' => null,
        ]],
        'story_time' => null,
        'confidence' => 1,
    ]);

    expect($candidate->eventType)->toBe(EventType::ForeshadowingDue)
        ->and(app(DeterministicStoryEventApplier::class)->operations($candidate))->toBe([]);
});

test('extractor accepts ordered plant and payoff events backed by fulfilled action coverage', function () {
    $fixture = eventForeshadowingFixture(ForeshadowingStatus::Idea, [
        ['action' => 'plant'],
        ['action' => 'pay_off'],
    ], [
        ['action' => 'plant', 'status' => 'fulfilled', 'evidence' => '林舟摊开染血地图'],
        ['action' => 'pay_off', 'status' => 'fulfilled', 'evidence' => '地图最终指向潮汐门'],
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(foreshadowingEventResponse($fixture, [
        ['event_type' => EventType::ForeshadowingPlanted->value, 'quote' => '林舟摊开染血地图'],
        ['event_type' => EventType::ForeshadowingPaidOff->value, 'quote' => '地图最终指向潮汐门'],
    ])));

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'events.*.event_type'))->toBe([
        EventType::ForeshadowingPlanted->value,
        EventType::ForeshadowingPaidOff->value,
    ])->and(data_get($artifact->data, 'foreshadowing_contract_checksum'))->toHaveLength(64);
});

test('extractor accepts ordered plant and reinforce events in the same chapter', function () {
    $fixture = eventForeshadowingFixture(ForeshadowingStatus::Idea, [
        ['action' => 'plant'],
        ['action' => 'reinforce'],
    ], [
        ['action' => 'plant', 'status' => 'fulfilled', 'evidence' => '林舟摊开染血地图'],
        ['action' => 'reinforce', 'status' => 'fulfilled', 'evidence' => '地图边缘显出旧王印记'],
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(foreshadowingEventResponse($fixture, [
        ['event_type' => EventType::ForeshadowingPlanted->value, 'quote' => '林舟摊开染血地图'],
        ['event_type' => EventType::ForeshadowingReinforced->value, 'quote' => '地图边缘显出旧王印记'],
    ])));

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'events.*.event_type'))->toBe([
        EventType::ForeshadowingPlanted->value,
        EventType::ForeshadowingReinforced->value,
    ]);
});

test('extractor rejects unselected mismatched or unfulfilled foreshadowing events', function (array $actions, array $coverages, EventType $eventType, string $message) {
    $fixture = eventForeshadowingFixture(ForeshadowingStatus::Planted, $actions, $coverages);
    $canonicalStateVersionId = $fixture['novel']->fresh()->canonical_state_version_id;
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(foreshadowingEventResponse($fixture, [[
        'event_type' => $eventType->value,
        'quote' => '林舟终于抵达洛阳城下。',
    ]])));

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, $message);

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole()->status)->toBe(RunStatus::Failed)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)->count())->toBe(0)
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($canonicalStateVersionId)
        ->and($fixture['novel']->storyEvents()->count())->toBe(0);
})->with([
    'unselected future foreshadowing' => [[], [], EventType::ForeshadowingReinforced, '不在本章冻结动作契约中'],
    'event type differs from plan action' => [
        [['action' => 'pay_off']],
        [['action' => 'pay_off', 'status' => 'fulfilled', 'evidence' => '林舟终于抵达洛阳城下。']],
        EventType::ForeshadowingReinforced,
        '不在本章冻结动作契约中',
    ],
    'coverage is missing' => [
        [['action' => 'reinforce']],
        [['action' => 'reinforce', 'status' => 'missing', 'evidence' => null]],
        EventType::ForeshadowingReinforced,
        '没有通过当前草稿 Coverage',
    ],
    'abandon is missing manual authorization' => [
        [['action' => 'abandon']],
        [['action' => 'abandon', 'status' => 'fulfilled', 'evidence' => '林舟终于抵达洛阳城下。']],
        EventType::ForeshadowingAbandoned,
        '缺少与冻结 State Version 一致的人工授权',
    ],
]);

test('extractor attaches fulfilled coverage evidence while preserving complementary event evidence', function () {
    $fixture = eventForeshadowingFixture(ForeshadowingStatus::Planted, [
        ['action' => 'reinforce'],
    ], [
        ['action' => 'reinforce', 'status' => 'fulfilled', 'evidence' => '地图最终指向潮汐门'],
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(foreshadowingEventResponse($fixture, [[
        'event_type' => EventType::ForeshadowingReinforced->value,
        'evidence' => [
            [
                'artifact_id' => $fixture['draft']->getKey(),
                'scene_id' => $fixture['scene']->getKey(),
                'quote' => '林舟终于抵达洛阳城下。',
                'start_offset' => 0,
                'end_offset' => 12,
            ],
            [
                'artifact_id' => $fixture['draft']->getKey(),
                'scene_id' => $fixture['scene']->getKey(),
                'quote' => '地图边缘显出旧王印记。',
                'start_offset' => null,
                'end_offset' => null,
            ],
        ],
    ]])));

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'events.0.evidence.*.quote'))->toBe([
        '林舟终于抵达洛阳城下。',
        '地图边缘显出旧王印记。',
        '地图最终指向潮汐门',
    ]);
});

test('extractor binds inherited foreshadowing coverage evidence to the action target scene', function () {
    $fixture = eventForeshadowingFixture(ForeshadowingStatus::Planted, [
        ['action' => 'reinforce'],
    ], [
        ['action' => 'reinforce', 'status' => 'fulfilled', 'evidence' => '地图最终指向潮汐门'],
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(foreshadowingEventResponse($fixture, [[
        'event_type' => EventType::ForeshadowingReinforced->value,
        'quote' => '地图最终指向潮汐门',
        'scene_id' => null,
    ]])));

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'events.0.evidence'))->toHaveCount(2)
        ->and(data_get($artifact->data, 'events.0.evidence.1.scene_id'))->toBe($fixture['scene']->getKey())
        ->and(data_get($artifact->data, 'events.0.evidence.1.quote'))->toBe('地图最终指向潮汐门');
});

test('extractor enforces same chapter foreshadowing event order and terminal states', function (ForeshadowingStatus $status, array $actions, array $coverages, array $events, string $message) {
    $fixture = eventForeshadowingFixture($status, $actions, $coverages);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(foreshadowingEventResponse($fixture, $events)));

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, $message);
})->with([
    'idea cannot jump to reinforced' => [
        ForeshadowingStatus::Idea,
        [['action' => 'reinforce']],
        [['action' => 'reinforce', 'status' => 'fulfilled', 'evidence' => '林舟终于抵达洛阳城下。']],
        [['event_type' => EventType::ForeshadowingReinforced->value, 'quote' => '林舟终于抵达洛阳城下。']],
        '不能从 idea 执行 foreshadowing_reinforced',
    ],
    'payoff cannot precede plant' => [
        ForeshadowingStatus::Idea,
        [['action' => 'plant'], ['action' => 'pay_off']],
        [
            ['action' => 'plant', 'status' => 'fulfilled', 'evidence' => '林舟摊开染血地图'],
            ['action' => 'pay_off', 'status' => 'fulfilled', 'evidence' => '地图最终指向潮汐门'],
        ],
        [
            ['event_type' => EventType::ForeshadowingPaidOff->value, 'quote' => '地图最终指向潮汐门'],
            ['event_type' => EventType::ForeshadowingPlanted->value, 'quote' => '林舟摊开染血地图'],
        ],
        '不能从 idea 执行 foreshadowing_paid_off',
    ],
    'terminal foreshadowing cannot reopen' => [
        ForeshadowingStatus::PaidOff,
        [['action' => 'reinforce']],
        [['action' => 'reinforce', 'status' => 'fulfilled', 'evidence' => '林舟终于抵达洛阳城下。']],
        [['event_type' => EventType::ForeshadowingReinforced->value, 'quote' => '林舟终于抵达洛阳城下。']],
        '不能从 paid_off 执行 foreshadowing_reinforced',
    ],
]);

test('extractor does not treat a non-idea domain projection as canonical lifecycle evidence', function () {
    $fixture = eventForeshadowingFixture(ForeshadowingStatus::Planted, [
        ['action' => 'reinforce'],
    ], [
        ['action' => 'reinforce', 'status' => 'fulfilled', 'evidence' => '地图边缘显出旧王印记'],
    ]);
    $stateWithoutForeshadowing = StoryStateVersion::factory()->raw()['state'];
    $state = StoryStateVersion::factory()->for($fixture['novel'])->create([
        'version' => $fixture['state']->version + 1,
        'state' => $stateWithoutForeshadowing,
    ]);
    $fixture['novel']->update(['canonical_state_version_id' => $state->getKey()]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(foreshadowingEventResponse($fixture, [[
        'event_type' => EventType::ForeshadowingReinforced->value,
        'quote' => '地图边缘显出旧王印记',
    ]])));

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, '缺少可验证的 Canonical 内容生命周期状态');
});

test('story event candidate rejects world entity subtypes with an actionable message', function () {
    $fixture = eventExtractionFixture();
    $event = eventExtractionResponse($fixture)->structuredData['events'][0];
    $event['event_type'] = EventType::WorldRuleRevealed->value;
    $event['subject_type'] = 'concept';

    expect(fn () => StoryEventCandidate::fromArray($event))
        ->toThrow(ValidationException::class, 'subject_type [concept] 不在允许范围内')
        ->and(fn () => StoryEventCandidate::fromArray($event))
        ->toThrow(ValidationException::class, '必须使用 world_entity');
});

test('story event candidate rejects an event and subject type mismatch', function () {
    $fixture = eventExtractionFixture();
    $event = eventExtractionResponse($fixture)->structuredData['events'][0];
    $event['event_type'] = EventType::ForeshadowingReinforced->value;

    expect(fn () => StoryEventCandidate::fromArray($event))
        ->toThrow(ValidationException::class, 'event_type [foreshadowing_reinforced] 要求 subject_type 为 [foreshadowing]，实际为 [character]');
});

test('extractor creates a candidate artifact without changing canonical story state', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());
    $run = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole();

    expect($artifact->type)->toBe(ArtifactType::EventCandidate)
        ->and($artifact->data['status'])->toBe('candidate')
        ->and($artifact->data['source_artifact_id'])->toBe($fixture['draft']->getKey())
        ->and($artifact->data['events'][0]['event_type'])->toBe(EventType::CharacterMoved->value)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->idempotency_key)->toStartWith('events:'.$fixture['draft']->checksum.':'.$fixture['state']->version.':event-extractor-v8')
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.events.items.additionalProperties'))->toBeFalse()
        ->and(data_get($fake->requests()[0]->responseSchema, 'properties.events.items.properties.payload.type'))->toBe('string')
        ->and($fake->requests()[0]->systemPrompt)->toContain('内部 type（例如 concept、rule、location、faction）不能作为 subject_type')
        ->and($fake->requests()[0]->systemPrompt)->toContain('foreshadowing_contract 是本章冻结的唯一伏笔动作契约')
        ->and($fake->requests()[0]->systemPrompt)->toContain('没有有效主体时必须省略该事件')
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得把 sequence 当作 scene_id')
        ->and(data_get($run->context_snapshot, 'event_subject_type_rules.promise_made'))->toBe(['relationship'])
        ->and(data_get($run->context_snapshot, 'foreshadowing_contract_checksum'))->toBe(data_get($run->context_snapshot, 'foreshadowing_contract.checksum'))
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey())
        ->and($fixture['novel']->storyStateVersions()->count())->toBe(1);
});

test('extractor repairs a foreign scene id when the verbatim evidence uniquely identifies a current scene', function () {
    $fixture = eventExtractionFixture();
    $otherChapter = Chapter::factory()->for($fixture['novel'])->create();
    $foreignScene = Scene::factory()->for($otherChapter)->create(['sequence' => 1]);
    $nonMatchingScene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 1]);
    $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => 2]);
    $nonMatchingRun = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => $nonMatchingScene->getKey(),
        'scope_type' => 'scene',
        'scope_id' => $nonMatchingScene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $nonMatchingArtifact = GenerationArtifact::factory()->for($nonMatchingRun)->create([
        'type' => ArtifactType::SceneDraft,
        'content' => '这个场景不包含目标引文。',
        'checksum' => hash('sha256', '这个场景不包含目标引文。'),
    ]);
    $nonMatchingScene->update(['current_artifact_id' => $nonMatchingArtifact->getKey()]);
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
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => $foreignScene->getKey(),
            'quote' => '林舟终于抵达洛阳城下。',
            'start_offset' => 0,
            'end_offset' => 12,
        ]],
    ]));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect($foreignScene->getKey())->toBe($nonMatchingScene->sequence)
        ->and(data_get($artifact->data, 'events.0.evidence.0.scene_id'))->toBe($scene->getKey())
        ->and(data_get($fake->requests()[0]->metadata, 'chapter_id'))->toBe($fixture['chapter']->getKey())
        ->and(data_get($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole()->context_snapshot, 'current_scene_references.1'))->toBe([
            'scene_id' => $scene->getKey(),
            'sequence' => 2,
            'source_artifact_id' => $sceneArtifact->getKey(),
        ]);
});

test('extractor does not guess a foreign scene id when the evidence quote matches multiple current scenes', function () {
    $fixture = eventExtractionFixture();
    $otherChapter = Chapter::factory()->for($fixture['novel'])->create();
    $foreignScene = Scene::factory()->for($otherChapter)->create(['sequence' => 1]);

    foreach ([1, 2] as $sequence) {
        $scene = Scene::factory()->for($fixture['chapter'])->create(['sequence' => $sequence]);
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
    }

    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => $foreignScene->getKey(),
            'quote' => '林舟终于抵达洛阳城下。',
            'start_offset' => 0,
            'end_offset' => 12,
        ]],
    ])));

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, 'Candidate Evidence 引用了其他 Chapter 的 Scene');

    expect(GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole()->status)->toBe(RunStatus::Failed);
});

test('the event workflow automatically builds the matching state patch before review', function () {
    Queue::fake();
    $fixture = eventExtractionFixture();
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture)));

    (new ExtractStoryEventsJob($fixture['chapter']->getKey(), continueRewrite: true))
        ->handle(app(StoryEventExtractor::class));

    $candidate = GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)->sole();
    $patch = GenerationArtifact::query()->where('type', ArtifactType::StatePatch)->sole();

    expect((int) data_get($patch->data, 'source_artifact_id'))->toBe($candidate->getKey())
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey())
        ->and($fixture['novel']->storyStateVersions()->count())->toBe(1);
    Queue::assertPushed(ReviewChapterJob::class, fn (ReviewChapterJob $job): bool => $job->chapterId === $fixture['chapter']->getKey() && ! $job->regenerate);
});

test('extractor binds evidence to the authoritative chapter draft instead of trusting a model artifact id', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'evidence' => [[
            'artifact_id' => 999999,
            'scene_id' => null,
            'quote' => '林舟终于抵达洛阳城下。',
            'start_offset' => 0,
            'end_offset' => 12,
        ]],
    ]));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'events.0.evidence.0.artifact_id'))->toBe($fixture['draft']->getKey());
});

test('extractor resolves harmless evidence formatting differences to an exact draft quote', function (string $quote) {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'evidence' => [[
            'artifact_id' => $fixture['draft']->getKey(),
            'scene_id' => null,
            'quote' => $quote,
            'start_offset' => null,
            'end_offset' => null,
        ]],
    ]));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'events.0.evidence.0.quote'))->toBe('林舟终于抵达洛阳城下。城门在身后关闭。');
})->with([
    'outer quotation marks' => '“林舟终于抵达洛阳城下。城门在身后关闭。”',
    'ellipsis excerpt' => '林舟终于抵达洛阳城下。……城门在身后关闭。',
]);

test('duplicate extraction reuses the successful candidate artifact', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture));
    app()->instance(AiProvider::class, $fake);
    $extractor = app(StoryEventExtractor::class);

    $first = $extractor->extract($fixture['chapter']->getKey());
    $second = $extractor->extract($fixture['chapter']->getKey());

    expect($second?->is($first))->toBeTrue()
        ->and($fake->requests())->toHaveCount(1)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(1);
});

test('retrying extraction recovers a blocked chapter before starting a new run', function () {
    $fixture = eventExtractionFixture();
    $fixture['chapter']->update(['status' => ChapterStatus::Blocked]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture)));

    app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey(), true);

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole()->status)->toBe(RunStatus::Succeeded);
});

test('invalid event evidence is rejected before artifact persistence', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(eventExtractionResponse($fixture, [
            'evidence' => [[
                'artifact_id' => $fixture['draft']->getKey(),
                'scene_id' => null,
                'quote' => '正文中不存在的句子',
                'start_offset' => null,
                'end_offset' => null,
            ]],
        ]))
        ->enqueue(eventEvidenceRepairResponse(['修复后仍不存在的句子']))
        ->enqueue(eventEvidenceRepairResponse(['第二次修复仍不存在的句子']));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, 'Evidence quote 必须逐字来自当前 Chapter Draft');

    expect(GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole()->status)->toBe(RunStatus::Failed);
});

test('extractor repairs invalid event quotes without changing the event', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(eventExtractionResponse($fixture, [
            'evidence' => [[
                'artifact_id' => $fixture['draft']->getKey(),
                'scene_id' => null,
                'quote' => '林舟抵达洛阳城下。',
                'start_offset' => null,
                'end_offset' => null,
            ]],
        ]))
        ->enqueue(truncatedEventEvidenceResponse())
        ->enqueue(eventEvidenceRepairResponse(['林舟终于抵达洛阳城下。']));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact?->data, 'events.0.event_type'))->toBe(EventType::CharacterMoved->value)
        ->and(data_get($artifact?->data, 'events.0.payload.to'))->toBe('洛阳')
        ->and(data_get($artifact?->data, 'events.0.evidence.0.quote'))->toBe('林舟终于抵达洛阳城下。')
        ->and($fake->requests())->toHaveCount(3)
        ->and($fake->requests()[1]->promptVersion)->toBe('event-evidence-repair-v1')
        ->and($fake->requests()[1]->maxTokens)->toBe(1_000)
        ->and(data_get($fake->requests()[1]->metadata, 'event_evidence_repair_attempt'))->toBe(1)
        ->and($fake->requests()[2]->maxTokens)->toBe(4_000)
        ->and(data_get($fake->requests()[2]->metadata, 'event_evidence_repair_attempt'))->toBe(2)
        ->and(data_get($fake->requests()[2]->metadata, 'event_index'))->toBe(0);
});

test('extractor keeps only repaired evidence with a high confidence exact overlap', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(eventExtractionResponse($fixture, [
            'evidence' => [
                [
                    'artifact_id' => $fixture['draft']->getKey(),
                    'scene_id' => null,
                    'quote' => '林舟抵达洛阳城下。城门在身后关闭。',
                    'start_offset' => null,
                    'end_offset' => null,
                ],
                [
                    'artifact_id' => $fixture['draft']->getKey(),
                    'scene_id' => null,
                    'quote' => '正文完全不存在的补充证据',
                    'start_offset' => null,
                    'end_offset' => null,
                ],
            ],
        ]))
        ->enqueue(eventEvidenceRepairResponse([
            '林舟终于抵达洛阳城下。城门在身后关闭。',
            '正文完全不存在的补充证据',
        ]));
    app()->instance(AiProvider::class, $fake);

    $artifact = app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect(data_get($artifact->data, 'events.0.evidence'))->toHaveCount(1)
        ->and(data_get($artifact->data, 'events.0.evidence.0.quote'))->toBe('林舟终于抵达洛阳城下。城门在身后关闭。');
});

test('extractor records the candidate index and invalid value for validation failures', function () {
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture, [
        'event_type' => EventType::WorldRuleRevealed->value,
        'subject_type' => 'concept',
    ]));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class, '第 1 个事件字段 subject_type：subject_type [concept] 不在允许范围内');

    $run = $fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error_code)->toBe('event_validation_failed')
        ->and($run->error_message)->toContain('第 1 个事件字段 subject_type')
        ->and($run->error_message)->toContain('subject_type [concept]')
        ->and(GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)->count())->toBe(0);
});

test('retryable provider failure is recorded and retried from event extraction', function () {
    Queue::fake();
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(new AiProviderException('provider_timeout', 'timeout', true))
        ->enqueue(eventExtractionResponse($fixture));
    app()->instance(AiProvider::class, $fake);
    $job = new ExtractStoryEventsJob($fixture['chapter']->getKey());

    expect(fn () => $job->handle(app(StoryEventExtractor::class)))
        ->toThrow(AiProviderException::class, 'timeout');

    $job->handle(app(StoryEventExtractor::class));

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(2)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->latest('id')->first()->status)->toBe(RunStatus::Succeeded);
});

test('event extraction increases frozen output budgets and stops before a fourth provider call', function () {
    config()->set('generation.event_extraction_max_output_tokens', 100);
    config()->set('generation.event_extraction_retry_max_output_tokens', 200);
    config()->set('generation.event_extraction_final_retry_max_output_tokens', 300);
    $fixture = eventExtractionFixture();
    $fake = (new FakeAiProvider)
        ->enqueue(truncatedEventExtractionResponse())
        ->enqueue(truncatedEventExtractionResponse())
        ->enqueue(truncatedEventExtractionResponse());
    app()->instance(AiProvider::class, $fake);
    $extractor = app(StoryEventExtractor::class);

    foreach ([100, 200, 300] as $expectedBudget) {
        expect(fn () => $extractor->extract($fixture['chapter']->getKey()))
            ->toThrow(AiProviderException::class, '因输出 Token 用尽而被截断');
        expect($fake->requests()[array_key_last($fake->requests())]->maxTokens)->toBe($expectedBudget);
    }

    expect(fn () => $extractor->extract($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '已在冻结的最高输出预算 300 Token 下被截断');
    expect($fake->requests())->toHaveCount(3)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->latest('id')->first()->error_code)
        ->toBe('event_output_budget_exhausted');
});

test('stale event extraction run is marked interrupted before recovery', function () {
    $fixture = eventExtractionFixture();
    $stale = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $fixture['chapter']->getKey(),
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Running,
        'updated_at' => now()->subSeconds((int) config('generation.stalled_run_after_seconds') + 1),
    ]);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(eventExtractionResponse($fixture)));

    app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey());

    expect($stale->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->fresh()->error_code)->toBe('worker_interrupted')
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(2);
});

test('paused novel cannot start event extraction', function () {
    $fixture = eventExtractionFixture();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);
    app()->instance(AiProvider::class, new FakeAiProvider);

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');

    expect($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->count())->toBe(0);
});

test('state version conflict prevents candidate artifact persistence', function () {
    $fixture = eventExtractionFixture();
    $response = eventExtractionResponse($fixture);
    app()->instance(AiProvider::class, new class($fixture['novel'], $response) implements AiProvider
    {
        public function __construct(private Novel $novel, private AiResponse $response) {}

        public function generate(AiRequest $request): AiResponse
        {
            $version = StoryStateVersion::factory()->for($this->novel)->create(['version' => 1]);
            $this->novel->update(['canonical_state_version_id' => $version->getKey()]);

            return $this->response;
        }
    });

    expect(fn () => app(StoryEventExtractor::class)->extract($fixture['chapter']->getKey()))
        ->toThrow(AiProviderException::class, 'Story State 已变化');

    expect(GenerationArtifact::query()->where('type', ArtifactType::EventCandidate)->count())->toBe(0)
        ->and($fixture['chapter']->generationRuns()->where('stage', GenerationStage::EventExtraction)->sole()->status)->toBe(RunStatus::Failed);
});
