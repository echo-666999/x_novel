<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Services\StoryStateRebuilder;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('it rebuilds current story state from state zero and ordered active events', function () {
    $novel = Novel::factory()->create();
    $initial = app(InitializeNovelStateAction::class)->handle($novel);

    StoryEvent::factory()->for($novel)->create([
        'event_type' => EventType::CharacterMoved,
        'subject_id' => '42',
        'payload' => ['to' => '长安'],
        'state_version' => 1,
    ]);
    StoryEvent::factory()->for($novel)->create([
        'event_type' => EventType::CharacterMoved,
        'subject_id' => '42',
        'payload' => ['to' => '洛阳'],
        'state_version' => 2,
    ]);
    StoryEvent::factory()->for($novel)->create([
        'event_type' => EventType::CharacterMoved,
        'subject_id' => '42',
        'payload' => ['to' => '不应重放'],
        'state_version' => 2,
        'status' => StoryEventStatus::Invalidated,
        'invalidated_at' => now(),
    ]);

    $state = [...$initial->state, 'characters' => ['42' => ['location' => '洛阳']]];
    $current = StoryStateVersion::factory()->for($novel)->create([
        'version' => 2,
        'state' => $state,
        'checksum' => app(StoryStateService::class)->checksum($state),
    ]);
    $novel->update(['canonical_state_version_id' => $current->getKey()]);

    $result = app(StoryStateRebuilder::class)->rebuild($novel->fresh());

    expect($result->matches())->toBeTrue()
        ->and($result->replayedEventCount)->toBe(2)
        ->and($result->rebuiltState['characters']['42']['location'])->toBe('洛阳')
        ->and($result->changes)->toBe([]);
});

test('it replays manual correction events and reports drift without writing state', function () {
    $novel = Novel::factory()->create();
    $initial = app(InitializeNovelStateAction::class)->handle($novel);
    StoryEvent::factory()->for($novel)->create([
        'event_type' => EventType::ManualCorrection,
        'subject_type' => 'characters',
        'subject_id' => '42',
        'payload' => ['path' => 'characters.42.location', 'before' => null, 'after' => '洛阳', 'reason' => '修正地点'],
        'state_version' => 1,
    ]);
    $drifted = [...$initial->state, 'characters' => ['42' => ['location' => '长安']]];
    $current = StoryStateVersion::factory()->for($novel)->create([
        'version' => 1,
        'state' => $drifted,
        'checksum' => app(StoryStateService::class)->checksum($drifted),
    ]);
    $novel->update(['canonical_state_version_id' => $current->getKey()]);

    $result = app(StoryStateRebuilder::class)->rebuild($novel->fresh());

    expect($result->matches())->toBeFalse()
        ->and($result->changes)->toHaveCount(1)
        ->and($result->changes[0]['path'])->toBe('characters.42.location')
        ->and($result->changes[0]['after'])->toBe('洛阳')
        ->and($novel->storyStateVersions()->count())->toBe(2)
        ->and($novel->fresh()->canonical_state_version_id)->toBe($current->getKey());
});

test('it requires state version zero and a current canonical state', function () {
    $novel = Novel::factory()->create();

    expect(fn () => app(StoryStateRebuilder::class)->rebuild($novel))
        ->toThrow(ValidationException::class);
});

test('the rebuild command defaults to dry run and reports matching checksums', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);

    $this->artisan('story:rebuild-state', ['novel' => $novel->getKey()])
        ->expectsOutputToContain('校验通过')
        ->assertSuccessful();

    expect($novel->storyStateVersions()->count())->toBe(1)
        ->and($novel->storyEvents()->count())->toBe(0);
});

test('the rebuild command fails on checksum drift without replacing canonical state', function () {
    $novel = Novel::factory()->create();
    $initial = app(InitializeNovelStateAction::class)->handle($novel);
    $current = StoryStateVersion::factory()->for($novel)->create([
        'version' => 1,
        'state' => [...$initial->state, 'world' => ['weather' => '暴雨']],
        'checksum' => str_repeat('f', 64),
    ]);
    $novel->update(['canonical_state_version_id' => $current->getKey()]);

    $this->artisan('story:rebuild-state', ['novel' => $novel->getKey(), '--dry-run' => true])
        ->expectsOutputToContain('校验失败')
        ->expectsOutputToContain('未写入或覆盖')
        ->assertFailed();

    expect($novel->storyStateVersions()->count())->toBe(2)
        ->and($novel->fresh()->canonical_state_version_id)->toBe($current->getKey());
});
