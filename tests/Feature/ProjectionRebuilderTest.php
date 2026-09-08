<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ForeshadowingStatus;
use App\Enums\WorldEntityType;
use App\Models\Character;
use App\Models\Foreshadowing;
use App\Models\Novel;
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
    $fixture['foreshadowing']->update(['status' => ForeshadowingStatus::Due]);

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
