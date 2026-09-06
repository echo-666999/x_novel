<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Filament\Resources\Novels\Pages\ViewNovelStoryState;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the diff viewer defaults to the current and immediately preceding versions', function () {
    $novel = Novel::factory()->create();
    $versionZero = app(InitializeNovelStateAction::class)->handle($novel);
    $versionOne = createStateVersion($novel, 1, [
        ...$versionZero->state,
        'characters' => ['hero' => ['location' => '旧港']],
    ]);
    $versionTwo = createStateVersion($novel, 2, [
        ...$versionOne->state,
        'characters' => ['hero' => ['location' => '灯塔']],
    ]);
    $novel->update(['canonical_state_version_id' => $versionTwo->getKey()]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->assertSet('diffFromVersion', 1)
        ->assertSet('diffToVersion', 2)
        ->call('selectDomain', 'state_diff')
        ->assertSee('v1 → v2')
        ->assertSee('1 处变化')
        ->assertSee('characters.hero.location')
        ->assertSee('旧港')
        ->assertSee('灯塔')
        ->assertSee('Changed');
});

test('the diff viewer reports added removed and changed paths including null values', function () {
    $novel = Novel::factory()->create();
    $versionZero = app(InitializeNovelStateAction::class)->handle($novel);
    createStateVersion($novel, 1, [
        ...$versionZero->state,
        'characters' => [
            'hero' => [
                'location' => null,
                'title' => '守塔人',
                'obsolete_flag' => true,
            ],
        ],
    ]);
    createStateVersion($novel, 2, [
        ...$versionZero->state,
        'characters' => [
            'hero' => [
                'location' => '灯塔',
                'title' => null,
                'new_flag' => false,
            ],
        ],
    ]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectDomain', 'state_diff')
        ->call('selectDiffVersion', 'from', 1)
        ->call('selectDiffVersion', 'to', 2)
        ->assertSet('diffFromVersion', 1)
        ->assertSet('diffToVersion', 2)
        ->assertSee('characters.hero.location')
        ->assertSee('characters.hero.title')
        ->assertSee('characters.hero.obsolete_flag')
        ->assertSee('characters.hero.new_flag')
        ->assertSee('Added')
        ->assertSee('Removed')
        ->assertSee('Changed')
        ->assertSee('不存在');
});

test('the diff viewer has an explicit no changes state', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectDomain', 'state_diff')
        ->assertSee('v0 → v0')
        ->assertSee('0 处变化')
        ->assertSee('两个版本没有状态差异');
});

test('the diff selectors never load a version from another novel', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    StoryStateVersion::factory()->create(['version' => 99]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectDomain', 'state_diff')
        ->call('selectDiffVersion', 'from', 99)
        ->call('selectDiffVersion', 'to', 99)
        ->assertSet('diffFromVersion', 0)
        ->assertSet('diffToVersion', 0)
        ->assertSee('v0 → v0');
});

/** @param array<string, mixed> $state */
function createStateVersion(Novel $novel, int $version, array $state): StoryStateVersion
{
    return StoryStateVersion::factory()->for($novel)->create([
        'version' => $version,
        'state' => $state,
        'checksum' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    ]);
}
