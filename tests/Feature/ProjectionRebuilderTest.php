<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\EventType;
use App\Enums\ForeshadowingStatus;
use App\Enums\StoryEventStatus;
use App\Enums\WorldEntityType;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\WorldEntity;
use App\Services\ProjectionRebuilder;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function projectionRebuildFixture(): array
{
    $novel = Novel::factory()->create();
    $character = Character::factory()->for($novel)->create([
        'current_state' => ['location' => '长安', 'health' => '正常'],
    ]);
    $location = WorldEntity::factory()->for($novel)->create([
        'type' => WorldEntityType::Location,
        'current_state' => ['weather' => '晴'],
    ]);
    $faction = WorldEntity::factory()->for($novel)->create([
        'type' => WorldEntityType::Faction,
        'current_state' => ['influence' => 7],
    ]);
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'status' => ForeshadowingStatus::Planted,
    ]);
    $version = app(InitializeNovelStateAction::class)->handle($novel);

    return compact('novel', 'character', 'location', 'faction', 'foreshadowing', 'version');
}

test('it inspects projection health against current canonical state', function () {
    $fixture = projectionRebuildFixture();
    $fixture['character']->update(['current_state' => ['location' => '错误地点']]);
    $fixture['location']->update(['current_state' => ['weather' => '暴雨']]);
    $fixture['foreshadowing']->update(['status' => ForeshadowingStatus::Reinforced]);

    $health = app(ProjectionRebuilder::class)->inspect($fixture['novel']->fresh());

    expect($health->stateVersion)->toBe(0)
        ->and($health->checkedCount())->toBe(4)
        ->and($health->driftCount())->toBe(3)
        ->and($health->characterDriftIds)->toBe([$fixture['character']->getKey()])
        ->and($health->worldEntityDriftIds)->toBe([$fixture['location']->getKey()])
        ->and($health->foreshadowingDriftIds)->toBe([$fixture['foreshadowing']->getKey()])
        ->and($health->errors)->toBe([]);
});

test('it rebuilds all declared projections without changing canonical source', function () {
    $fixture = projectionRebuildFixture();
    $fixture['character']->update(['current_state' => ['location' => '错误地点']]);
    $fixture['location']->update(['current_state' => ['weather' => '暴雨']]);
    $fixture['faction']->update(['current_state' => ['influence' => 0]]);
    $fixture['foreshadowing']->update(['status' => ForeshadowingStatus::PaidOff]);
    $pointer = $fixture['novel']->fresh()->canonical_state_version_id;
    $checksum = $fixture['version']->checksum;

    $health = app(ProjectionRebuilder::class)->rebuild($fixture['novel']->fresh());

    expect($health->isHealthy())->toBeTrue()
        ->and($fixture['character']->fresh()->current_state)->toBe(['location' => '长安', 'health' => '正常'])
        ->and($fixture['location']->fresh()->current_state)->toBe(['weather' => '晴'])
        ->and($fixture['faction']->fresh()->current_state)->toBe(['influence' => 7])
        ->and($fixture['foreshadowing']->fresh()->status)->toBe(ForeshadowingStatus::Planted)
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($pointer)
        ->and($fixture['version']->fresh()->checksum)->toBe($checksum)
        ->and($fixture['novel']->storyStateVersions()->count())->toBe(1)
        ->and($fixture['novel']->storyEvents()->count())->toBe(0);
});

test('projection rebuild is idempotent', function () {
    $fixture = projectionRebuildFixture();
    $fixture['character']->update(['current_state' => ['location' => '错误地点']]);
    $service = app(ProjectionRebuilder::class);

    $service->rebuild($fixture['novel']->fresh());
    $updatedAt = $fixture['character']->fresh()->updated_at;
    $second = $service->rebuild($fixture['novel']->fresh());

    expect($second->isHealthy())->toBeTrue()
        ->and($second->driftCount())->toBe(0)
        ->and($fixture['character']->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('invalid canonical projection data aborts without partial projection updates', function () {
    $fixture = projectionRebuildFixture();
    $state = $fixture['version']->state;
    $state['characters'][(string) $fixture['character']->getKey()]['location'] = '洛阳';
    $state['foreshadowings'][(string) $fixture['foreshadowing']->getKey()]['status'] = 'invalid-status';
    $current = StoryStateVersion::factory()->for($fixture['novel'])->create([
        'version' => 1,
        'state' => $state,
        'checksum' => app(StoryStateService::class)->checksum($state),
    ]);
    $fixture['novel']->update(['canonical_state_version_id' => $current->getKey()]);

    expect(fn () => app(ProjectionRebuilder::class)->rebuild($fixture['novel']->fresh()))
        ->toThrow(ValidationException::class);

    expect($fixture['character']->fresh()->current_state['location'])->toBe('长安')
        ->and($fixture['foreshadowing']->fresh()->status)->toBe(ForeshadowingStatus::Planted)
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($current->getKey());
});

test('records absent from canonical state are not guessed or overwritten', function () {
    $fixture = projectionRebuildFixture();
    $lateCharacter = Character::factory()->for($fixture['novel'])->create([
        'current_state' => ['note' => '尚未进入正式状态'],
    ]);

    $health = app(ProjectionRebuilder::class)->rebuild($fixture['novel']->fresh());

    expect($health->charactersChecked)->toBe(1)
        ->and($lateCharacter->fresh()->current_state)->toBe(['note' => '尚未进入正式状态']);
});

test('newer direct world state takes precedence over initialization metadata', function () {
    $fixture = projectionRebuildFixture();
    $state = $fixture['version']->state;
    $state['world'][(string) $fixture['faction']->getKey()] = ['influence' => 12, 'stance' => '敌对'];
    $current = StoryStateVersion::factory()->for($fixture['novel'])->create([
        'version' => 1,
        'state' => $state,
        'checksum' => app(StoryStateService::class)->checksum($state),
    ]);
    $fixture['novel']->update(['canonical_state_version_id' => $current->getKey()]);

    app(ProjectionRebuilder::class)->rebuild($fixture['novel']->fresh());

    expect($fixture['faction']->fresh()->current_state)->toBe([
        'influence' => 12,
        'stance' => '敌对',
    ]);
});

test('it rebuilds the complete foreshadowing projection from baseline and active events', function () {
    $fixture = projectionRebuildFixture();
    $first = Chapter::factory()->for($fixture['novel'])->create(['sequence' => 1]);
    $second = Chapter::factory()->for($fixture['novel'])->create(['sequence' => 2]);
    $third = Chapter::factory()->for($fixture['novel'])->create(['sequence' => 3]);
    foreach ([
        [$first, EventType::ForeshadowingPlanted, 1],
        [$second, EventType::ForeshadowingReinforced, 2],
        [$third, EventType::ForeshadowingPaidOff, 3],
    ] as [$chapter, $type, $version]) {
        StoryEvent::factory()->for($fixture['novel'])->for($chapter)->create([
            'event_type' => $type,
            'subject_type' => 'foreshadowing',
            'subject_id' => (string) $fixture['foreshadowing']->getKey(),
            'state_version' => $version,
        ]);
    }
    $state = $fixture['version']->state;
    $state['foreshadowings'][(string) $fixture['foreshadowing']->getKey()] = [
        'status' => ForeshadowingStatus::PaidOff->value,
        'reinforce_count' => 1,
    ];
    $current = StoryStateVersion::factory()->for($fixture['novel'])->for($third)->create([
        'version' => 3,
        'state' => $state,
        'checksum' => app(StoryStateService::class)->checksum($state),
    ]);
    $fixture['novel']->update(['canonical_state_version_id' => $current->getKey()]);
    $fixture['foreshadowing']->update([
        'status' => ForeshadowingStatus::Idea,
        'reinforce_count' => 9,
        'setup_chapter_id' => $third->getKey(),
        'payoff_chapter_id' => $first->getKey(),
    ]);

    $health = app(ProjectionRebuilder::class)->rebuild($fixture['novel']->fresh());
    $projection = $fixture['foreshadowing']->fresh();

    expect($health->isHealthy())->toBeTrue()
        ->and($projection->status)->toBe(ForeshadowingStatus::PaidOff)
        ->and($projection->reinforce_count)->toBe(1)
        ->and($projection->setup_chapter_id)->toBe($first->getKey())
        ->and($projection->payoff_chapter_id)->toBe($third->getKey());
});

test('replaying the same foreshadowing projection never increments the stored count twice', function () {
    $fixture = projectionRebuildFixture();
    $chapter = Chapter::factory()->for($fixture['novel'])->create(['sequence' => 1]);
    StoryEvent::factory()->for($fixture['novel'])->for($chapter)->create([
        'event_type' => EventType::ForeshadowingReinforced,
        'subject_type' => 'foreshadowing',
        'subject_id' => (string) $fixture['foreshadowing']->getKey(),
        'state_version' => 1,
    ]);
    $state = $fixture['version']->state;
    $state['foreshadowings'][(string) $fixture['foreshadowing']->getKey()] = [
        'status' => ForeshadowingStatus::Reinforced->value,
        'reinforce_count' => 1,
    ];
    $current = StoryStateVersion::factory()->for($fixture['novel'])->for($chapter)->create([
        'version' => 1,
        'state' => $state,
        'checksum' => app(StoryStateService::class)->checksum($state),
    ]);
    $fixture['novel']->update(['canonical_state_version_id' => $current->getKey()]);

    $service = app(ProjectionRebuilder::class);
    $service->rebuild($fixture['novel']->fresh());
    $updatedAt = $fixture['foreshadowing']->fresh()->updated_at;
    $service->rebuild($fixture['novel']->fresh());

    expect($fixture['foreshadowing']->fresh()->reinforce_count)->toBe(1)
        ->and($fixture['foreshadowing']->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('invalidated foreshadowing events do not contribute to chapter references', function () {
    $fixture = projectionRebuildFixture();
    $chapter = Chapter::factory()->for($fixture['novel'])->create(['sequence' => 1]);
    StoryEvent::factory()->for($fixture['novel'])->for($chapter)->create([
        'event_type' => EventType::ForeshadowingPaidOff,
        'subject_type' => 'foreshadowing',
        'subject_id' => (string) $fixture['foreshadowing']->getKey(),
        'state_version' => 1,
        'status' => StoryEventStatus::Invalidated,
        'invalidated_at' => now(),
    ]);
    $fixture['foreshadowing']->update(['payoff_chapter_id' => $chapter->getKey()]);

    app(ProjectionRebuilder::class)->rebuild($fixture['novel']->fresh());

    expect($fixture['foreshadowing']->fresh()->payoff_chapter_id)->toBeNull();
});
