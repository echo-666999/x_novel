<?php

use App\Data\StateValidationResult;
use App\Enums\ArtifactType;
use App\Enums\EventType;
use App\Enums\FactStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Models\WorldEntity;
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
