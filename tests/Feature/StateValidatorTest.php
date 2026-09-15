<?php

use App\Data\StateValidationResult;
use App\Enums\ArtifactType;
use App\Enums\EventType;
use App\Enums\FactStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Models\StoryStateVersion;
use App\Models\WorldEntity;
use App\Services\ForeshadowingContextContract;
use App\Services\StatePatchBuilder;
use App\Services\StateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function validatorEvent(EventType $type, string $subjectType, string $subjectId, array $payload = []): array
{
    return [
        'event_type' => $type->value,
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'payload' => $payload,
        'evidence' => [[
            'artifact_id' => 99,
            'scene_id' => null,
            'quote' => '冲突证据',
            'start_offset' => null,
            'end_offset' => null,
        ]],
        'story_time' => null,
        'confidence' => 0.98,
    ];
}

function validatorFixture(array|callable $events, array|callable $operations, array|callable $changes, ?callable $stateFactory = null): array
{
    $novel = Novel::factory()->create();
    $character = Character::factory()->for($novel)->create();
    $world = WorldEntity::factory()->for($novel)->create();
    $events = is_callable($events) ? $events($character, $world) : $events;
    $operations = is_callable($operations) ? $operations($character, $world) : $operations;
    $changes = is_callable($changes) ? $changes($character, $world) : $changes;
    $state = StoryStateVersion::factory()->for($novel)->create([
        'version' => 0,
        'state' => $stateFactory === null ? StoryStateVersion::factory()->raw()['state'] : $stateFactory($character, $world),
    ]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);
    $chapter = Chapter::factory()->for($novel)->create();
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    $candidate = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::EventCandidate,
        'version' => 1,
        'data' => ['status' => 'candidate', 'events' => $events],
    ]);
    $patch = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::StatePatch,
        'version' => 1,
        'data' => [
            'status' => 'candidate',
            'source_artifact_id' => $candidate->getKey(),
            'expected_state_version' => 0,
            'operations' => $operations,
            'fact_changes' => [],
            'foreshadowing_changes' => [],
            'changes' => $changes,
        ],
    ]);

    return compact('novel', 'character', 'world', 'state', 'chapter', 'candidate', 'patch');
}

function foreshadowingValidatorFixture(ForeshadowingStatus $status, array $actions, array $coverages, array $eventTypes): array
{
    $novel = Novel::factory()->create();
    $bible = NovelBible::factory()->for($novel)->create();
    $foreshadowing = Foreshadowing::factory()->for($novel)->create(['status' => $status]);
    $stateData = StoryStateVersion::factory()->raw()['state'];
    data_set($stateData, "foreshadowings.{$foreshadowing->getKey()}.status", $status->value);
    $state = StoryStateVersion::factory()->for($novel)->create(['version' => 0, 'state' => $stateData]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 10]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'scene_plans' => [['goal' => '验证伏笔', 'conflict' => '阻力', 'turn' => '转折', 'outcome' => '结果']],
        'foreshadowing_actions' => collect($actions)->map(fn (array $action): array => [
            'foreshadowing_id' => $foreshadowing->getKey(),
            'target_scene_sequence' => 1,
            'acceptance_criteria' => '正文明确完成动作。',
            'reason' => null,
            ...$action,
        ])->all(),
    ]);
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 1]);
    $draftContent = '林舟摊开染血地图，地图最终指向潮汐门。';
    $draftRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $draft = GenerationArtifact::factory()->for($draftRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => $draftContent,
        'data' => ['scene_coverage' => [[
            'scene_id' => $scene->getKey(),
            'foreshadowing_coverage' => collect($coverages)->map(fn (array $coverage): array => [
                'foreshadowing_id' => $foreshadowing->getKey(),
                ...$coverage,
            ])->all(),
        ]]],
        'checksum' => hash('sha256', $draftContent),
    ]);
    $contract = app(ForeshadowingContextContract::class)->build($plan, $bible, $state);
    $events = collect($eventTypes)->map(fn (EventType $type): array => validatorEvent(
        $type,
        'foreshadowing',
        (string) $foreshadowing->getKey(),
    ))->values()->all();
    foreach ($events as $index => $event) {
        $events[$index]['evidence'][0]['artifact_id'] = $draft->getKey();
        $events[$index]['evidence'][0]['scene_id'] = $scene->getKey();
        $events[$index]['evidence'][0]['quote'] = $coverages[$index]['evidence'] ?? $draftContent;
    }
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => 0,
        'context_snapshot' => ['foreshadowing_contract' => $contract],
    ]);
    $candidate = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::EventCandidate,
        'version' => 1,
        'data' => [
            'status' => 'candidate',
            'source_artifact_id' => $draft->getKey(),
            'foreshadowing_contract_checksum' => $contract['checksum'],
            'events' => $events,
        ],
    ]);
    $patch = app(StatePatchBuilder::class)->build($chapter->getKey());

    return compact('novel', 'chapter', 'foreshadowing', 'state', 'draft', 'candidate', 'patch');
}

test('golden locked fact and locked world rule conflicts block validation', function () {
    $fixture = validatorFixture(
        fn (Character $character, WorldEntity $world): array => [
            validatorEvent(EventType::CharacterStatusChanged, 'character', (string) $character->getKey(), ['status' => 'alive']),
            validatorEvent(EventType::WorldRuleChanged, 'world_entity', (string) $world->getKey(), ['rule' => ['magic' => false]]),
        ],
        fn (Character $character, WorldEntity $world): array => [
            ['op' => 'set', 'path' => "characters.{$character->getKey()}.status", 'value' => 'alive', 'source_event_index' => 0],
            ['op' => 'set', 'path' => "world.{$world->getKey()}.rule.magic", 'value' => false, 'source_event_index' => 1],
        ],
        fn (Character $character, WorldEntity $world): array => [
            ['path' => "characters.{$character->getKey()}.status", 'after' => 'alive', 'source_event_index' => 0],
            ['path' => "world.{$world->getKey()}.rule.magic", 'after' => false, 'source_event_index' => 1],
        ],
    );
    $characterFact = Fact::factory()->for($fixture['novel'])->create([
        'subject_type' => 'character', 'subject_id' => $fixture['character']->getKey(),
        'predicate' => 'status', 'value' => ['value' => 'dead'], 'locked' => true, 'status' => FactStatus::Active,
    ]);
    $worldFact = Fact::factory()->for($fixture['novel'])->create([
        'subject_type' => 'world_entity', 'subject_id' => $fixture['world']->getKey(),
        'predicate' => 'rule.magic', 'value' => ['value' => true], 'locked' => true, 'status' => FactStatus::Active,
    ]);

    $result = app(StateValidator::class)->validate($fixture['chapter']->getKey());

    expect($result->decision())->toBe('BLOCK')
        ->and(array_column($result->findings, 'code'))->toContain('LOCKED_FACT_CONFLICT', 'WORLD_RULE_CONFLICT')
        ->and(collect($result->findings)->pluck('relatedFactId')->all())->toContain($characterFact->getKey(), $worldFact->getKey())
        ->and(fn () => $result->assertCanCommit())->toThrow(ValidationException::class);
});

test('dead character knowledge location item and ability conflicts are deterministic', function () {
    $fixture = validatorFixture(fn (Character $character): array => [validatorEvent(EventType::CharacterEmotionChanged, 'character', (string) $character->getKey(), [
        'emotion' => '愤怒', 'location' => '洛阳', 'required_knowledge' => '密令',
        'ability_used' => '御剑', 'item_id' => '玉玺', 'holder_id' => (string) $character->getKey(),
    ])], [], [], function (Character $character, WorldEntity $world): array {
        return [
            'schema_version' => 1,
            'characters' => [(string) $character->getKey() => [
                'status' => 'dead', 'location' => '长安',
                'knowledge' => ['密令' => false], 'abilities' => ['御剑' => false],
            ]],
            'relationships' => [], 'locations' => [],
            'items' => ['玉玺' => ['owner_id' => '99']],
            'world' => [], 'timeline' => [], 'open_threads' => [], 'foreshadowings' => [], 'reader_promises' => [],
        ];
    });

    $result = app(StateValidator::class)->validate($fixture['chapter']->getKey());
    $codes = array_column($result->findings, 'code');

    expect($result->isBlocked())->toBeTrue()
        ->and($codes)->toContain(
            'CHARACTER_DEAD_CONFLICT', 'KNOWLEDGE_CONFLICT', 'LOCATION_CONFLICT',
            'ITEM_OWNERSHIP_CONFLICT', 'ABILITY_CONFLICT',
        );
});

test('state version conflict and invalid event reference block validation', function () {
    $fixture = validatorFixture([
        validatorEvent(EventType::CharacterMoved, 'character', '999999', ['to' => '洛阳']),
    ], [], []);
    $next = StoryStateVersion::factory()->for($fixture['novel'])->create(['version' => 1]);
    $fixture['novel']->update(['canonical_state_version_id' => $next->getKey()]);

    $result = app(StateValidator::class)->validate($fixture['chapter']->getKey());

    expect(array_column($result->findings, 'code'))->toContain('STATE_VERSION_CONFLICT', 'INVALID_EVENT_REFERENCE')
        ->and($result->decision())->toBe('BLOCK');
});

test('a valid patch passes deterministic validation', function () {
    $fixture = validatorFixture([], [], []);
    $result = app(StateValidator::class)->validate($fixture['chapter']->getKey());

    expect($result)->toBeInstanceOf(StateValidationResult::class)
        ->and($result->decision())->toBe('PASS')
        ->and($result->findings)->toBe([]);
});

test('state validator accepts ordered plant and payoff lifecycle transitions', function () {
    $fixture = foreshadowingValidatorFixture(ForeshadowingStatus::Idea, [
        ['action' => 'plant'],
        ['action' => 'pay_off'],
    ], [
        ['action' => 'plant', 'status' => 'fulfilled', 'evidence' => '林舟摊开染血地图'],
        ['action' => 'pay_off', 'status' => 'fulfilled', 'evidence' => '地图最终指向潮汐门'],
    ], [EventType::ForeshadowingPlanted, EventType::ForeshadowingPaidOff]);

    $result = app(StateValidator::class)->validate($fixture['chapter']->getKey());

    expect($result->decision())->toBe('PASS')
        ->and($result->findings)->toBe([]);
});

test('state validator blocks invalid foreshadowing lifecycle even for a persisted candidate', function (ForeshadowingStatus $status, string $action, EventType $eventType, string $code) {
    $evidence = '林舟摊开染血地图';
    $fixture = foreshadowingValidatorFixture($status, [
        ['action' => $action],
    ], [
        ['action' => $action, 'status' => 'fulfilled', 'evidence' => $evidence],
    ], [$eventType]);

    $result = app(StateValidator::class)->validate($fixture['chapter']->getKey());

    expect($result->decision())->toBe('BLOCK')
        ->and(array_column($result->findings, 'code'))->toContain($code);
})->with([
    'idea cannot reinforce' => [ForeshadowingStatus::Idea, 'reinforce', EventType::ForeshadowingReinforced, 'FORESHADOWING_LIFECYCLE_INVALID'],
    'paid off cannot reinforce' => [ForeshadowingStatus::PaidOff, 'reinforce', EventType::ForeshadowingReinforced, 'FORESHADOWING_LIFECYCLE_INVALID'],
    'abandoned cannot pay off' => [ForeshadowingStatus::Abandoned, 'pay_off', EventType::ForeshadowingPaidOff, 'FORESHADOWING_LIFECYCLE_INVALID'],
]);

test('state validator rejects foreshadowing candidates without frozen contract lineage', function () {
    $fixture = foreshadowingValidatorFixture(ForeshadowingStatus::Planted, [
        ['action' => 'reinforce'],
    ], [
        ['action' => 'reinforce', 'status' => 'fulfilled', 'evidence' => '林舟摊开染血地图'],
    ], [EventType::ForeshadowingReinforced]);
    $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => 0,
        'context_snapshot' => [],
    ]);
    $candidate = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::EventCandidate,
        'version' => 2,
        'data' => [
            'status' => 'candidate',
            'source_artifact_id' => $fixture['draft']->getKey(),
            'events' => data_get($fixture['candidate']->data, 'events'),
        ],
    ]);
    app(StatePatchBuilder::class)->build($fixture['chapter']->getKey());

    $result = app(StateValidator::class)->validate($fixture['chapter']->getKey());

    expect($candidate)->not->toBeNull()
        ->and($result->decision())->toBe('BLOCK')
        ->and(array_column($result->findings, 'code'))->toContain('INVALID_FORESHADOWING_CONTRACT');
});

test('an older patch cannot validate a newer event candidate', function () {
    $fixture = validatorFixture([], [], []);
    GenerationArtifact::factory()->for($fixture['candidate']->generationRun)->create([
        'type' => ArtifactType::EventCandidate,
        'version' => 2,
        'data' => ['status' => 'candidate', 'events' => []],
    ]);

    $result = app(StateValidator::class)->validate($fixture['chapter']->getKey());

    expect($result->decision())->toBe('BLOCK')
        ->and(array_column($result->findings, 'code'))->toBe(['INVALID_STATE_PATCH'])
        ->and($result->findings[0]->message)->toBe('当前最新事件候选尚未生成匹配的 State Patch。');
});
