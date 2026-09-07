<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Data\StatePatch;
use App\Enums\ArtifactType;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Services\StatePatchBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function statePatchFixture(array $events): array
{
    $novel = Novel::factory()->create();
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create();
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::EventExtraction,
        'status' => RunStatus::Succeeded,
        'state_version' => $state->version,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::EventCandidate,
        'data' => ['status' => 'candidate', 'source_artifact_id' => 999, 'events' => $events],
    ]);

    return compact('novel', 'state', 'chapter', 'run', 'artifact');
}

function statePatchEvent(EventType $type, string $subjectId, array $payload): array
{
    return [
        'event_type' => $type->value,
        'subject_type' => 'character',
        'subject_id' => $subjectId,
        'payload' => $payload,
        'evidence' => [[
            'artifact_id' => 999,
            'scene_id' => null,
            'quote' => '可追踪正文证据',
            'start_offset' => null,
            'end_offset' => null,
        ]],
        'story_time' => null,
        'confidence' => 0.95,
    ];
}

test('state patch only accepts declared operations and state domains', function () {
    expect(fn () => new StatePatch(0, [[
        'op' => 'set',
        'path' => 'unknown.hero.status',
        'value' => 'alive',
        'source_event_index' => 0,
    ]]))->toThrow(ValidationException::class);
});

test('builder deterministically previews event changes without mutating canonical state', function () {
    $characterId = (string) 42;
    $fixture = statePatchFixture([
        statePatchEvent(EventType::CharacterMoved, $characterId, ['from' => '长安', 'to' => '洛阳']),
        statePatchEvent(EventType::CharacterLearned, $characterId, ['key' => '密令', 'value' => true]),
    ]);

    $artifact = app(StatePatchBuilder::class)->build($fixture['chapter']->getKey());

    expect($artifact->type)->toBe(ArtifactType::StatePatch)
        ->and($artifact->data['status'])->toBe('candidate')
        ->and($artifact->data['expected_state_version'])->toBe(0)
        ->and($artifact->data['source_artifact_id'])->toBe($fixture['artifact']->getKey())
        ->and($artifact->data['operations'])->toHaveCount(2)
        ->and($artifact->data['changes'][0])->toMatchArray([
            'path' => "characters.{$characterId}.location",
            'operation' => 'set',
            'before' => null,
            'after' => '洛阳',
            'before_missing' => true,
            'source_event_index' => 0,
            'source_event_type' => EventType::CharacterMoved->value,
        ])
        ->and($artifact->data['changes'][1]['path'])->toBe("characters.{$characterId}.knowledge.密令")
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey())
        ->and($fixture['novel']->storyStateVersions()->count())->toBe(1)
        ->and($fixture['state']->fresh()->state['characters'])->toBe([]);
});

test('duplicate build reuses the same state patch artifact', function () {
    $fixture = statePatchFixture([
        statePatchEvent(EventType::CharacterMoved, '42', ['to' => '洛阳']),
    ]);
    $builder = app(StatePatchBuilder::class);

    $first = $builder->build($fixture['chapter']->getKey());
    $second = $builder->build($fixture['chapter']->getKey());

    expect($second->is($first))->toBeTrue()
        ->and(GenerationArtifact::query()->where('type', ArtifactType::StatePatch)->count())->toBe(1);
});

test('builder rejects candidates created from an obsolete state version', function () {
    $fixture = statePatchFixture([
        statePatchEvent(EventType::CharacterMoved, '42', ['to' => '洛阳']),
    ]);
    $next = StoryStateVersion::factory()->for($fixture['novel'])->create(['version' => 1]);
    $fixture['novel']->update(['canonical_state_version_id' => $next->getKey()]);

    expect(fn () => app(StatePatchBuilder::class)->build($fixture['chapter']->getKey()))
        ->toThrow(ValidationException::class)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::StatePatch)->count())->toBe(0);
});

test('events without a safe deterministic mapping create an empty candidate patch', function () {
    $fixture = statePatchFixture([
        statePatchEvent(EventType::ManualCorrection, '42', ['note' => '等待人工处理']),
    ]);

    $artifact = app(StatePatchBuilder::class)->build($fixture['chapter']->getKey());

    expect($artifact->data['operations'])->toBe([])
        ->and($artifact->data['changes'])->toBe([])
        ->and($artifact->data['before_checksum'])->toBe($artifact->data['after_checksum']);
});
