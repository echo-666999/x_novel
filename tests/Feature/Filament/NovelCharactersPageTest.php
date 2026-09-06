<?php

use App\Enums\CharacterStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelCharacters;
use App\Models\Character;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the character workspace lists useful character state for the current novel', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $character = Character::factory()->for($novel)->create([
        'name' => '沈澜',
        'aliases' => ['阿澜'],
        'role' => '主角',
        'status' => CharacterStatus::Active,
        'current_state' => [
            'location' => '旧港',
            'flags' => ['injured' => true, 'disguised' => false],
        ],
        'locked_fields' => ['profile.age', 'abilities.sailing'],
    ]);
    $otherCharacter = Character::factory()->create(['name' => '不应出现']);

    Livewire::test(ManageNovelCharacters::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSeeTextInOrder([
            '角色管理',
            '雾海长明',
            '姓名',
            '角色定位',
            '状态',
            '当前位置',
            '重要标记',
            '沈澜',
            '阿澜',
            '主角',
            '活跃',
            '旧港',
            'injured',
            '锁定 2 项',
        ])
        ->assertCanSeeTableRecords([$character])
        ->assertCanNotSeeTableRecords([$otherCharacter])
        ->assertActionExists('create');
});

test('the owner can create and edit a character', function () {
    $novel = Novel::factory()->create();

    $component = Livewire::test(ManageNovelCharacters::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'name' => '沈澜',
            'role' => '主角',
            'aliases' => ['阿澜'],
            'status' => CharacterStatus::Active->value,
            'profile' => ['age' => '19'],
            'motivation' => '找回失踪的父亲。',
            'personality' => ['cautious' => '谨慎但不怯懦'],
            'abilities' => ['sailing' => '熟练'],
            'knowledge' => ['fog_tide' => '只知道基础周期'],
            'current_state' => ['location' => '旧港'],
            'locked_fields' => ['profile.age'],
        ])
        ->assertHasNoActionErrors();

    $character = $novel->characters()->sole();

    expect($character->name)->toBe('沈澜')
        ->and($character->profile)->toBe(['age' => '19'])
        ->and($character->current_state)->toBe(['location' => '旧港'])
        ->and($character->locked_fields)->toBe(['profile.age']);

    $component
        ->callTableAction('edit', $character, data: [
            'name' => '沈澜',
            'role' => '主角',
            'aliases' => ['阿澜', '守灯人'],
            'status' => CharacterStatus::Inactive->value,
            'profile' => ['age' => '20'],
            'motivation' => '带幸存者离开雾海。',
            'personality' => ['decisive' => '危机中果断'],
            'abilities' => ['sailing' => '精通'],
            'knowledge' => ['fog_tide' => '知道完整周期'],
            'current_state' => ['location' => '灯塔'],
            'locked_fields' => ['profile.age'],
        ])
        ->assertHasNoTableActionErrors();

    expect($character->refresh()->status)->toBe(CharacterStatus::Inactive)
        ->and($character->aliases)->toBe(['阿澜', '守灯人'])
        ->and($character->current_state)->toBe(['location' => '灯塔']);
});

test('the character form validates its required fields', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ManageNovelCharacters::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'name' => '',
            'role' => '',
            'status' => null,
            'motivation' => '',
        ])
        ->assertHasActionErrors([
            'name' => 'required',
            'role' => 'required',
            'status' => 'required',
            'motivation' => 'required',
        ]);
});

test('the character detail opens in a slideover with the complete profile', function () {
    $novel = Novel::factory()->create();
    $character = Character::factory()->for($novel)->create([
        'name' => '沈澜',
        'profile' => ['background' => '旧港守灯人之女'],
        'motivation' => '找回失踪的父亲。',
        'personality' => ['cautious' => '遇事先观察'],
        'abilities' => ['sailing' => '熟练'],
        'knowledge' => ['fog_tide' => '知道周期'],
        'current_state' => ['location' => '旧港'],
        'locked_fields' => ['profile.background'],
    ]);

    Livewire::test(ManageNovelCharacters::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionExists('view', fn ($action): bool => $action->isModalSlideOver())
        ->mountTableAction('view', $character)
        ->assertTableActionDataSet(function (array $data): bool {
            return $data['profile'] === ['background' => '旧港守灯人之女']
                && $data['motivation'] === '找回失踪的父亲。'
                && $data['personality'] === ['cautious' => '遇事先观察']
                && $data['abilities'] === ['sailing' => '熟练']
                && $data['knowledge'] === ['fog_tide' => '知道周期']
                && $data['current_state'] === ['location' => '旧港']
                && $data['locked_fields'] === ['profile.background'];
        });
});

test('the character workspace filters by status and role', function () {
    $novel = Novel::factory()->create();
    $protagonist = Character::factory()->for($novel)->create([
        'role' => '主角',
        'status' => CharacterStatus::Active,
    ]);
    $mentor = Character::factory()->for($novel)->create([
        'role' => '导师',
        'status' => CharacterStatus::Inactive,
    ]);

    Livewire::test(ManageNovelCharacters::class, ['record' => $novel->getRouteKey()])
        ->filterTable('status', CharacterStatus::Active->value)
        ->assertCanSeeTableRecords([$protagonist])
        ->assertCanNotSeeTableRecords([$mentor]);

    Livewire::test(ManageNovelCharacters::class, ['record' => $novel->getRouteKey()])
        ->filterTable('role', '导师')
        ->assertCanSeeTableRecords([$mentor])
        ->assertCanNotSeeTableRecords([$protagonist]);
});

test('the role filter distinct query does not inherit character name ordering', function () {
    $novel = Novel::factory()->create();
    Character::factory()->for($novel)->create(['role' => '主角']);
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Livewire::test(ManageNovelCharacters::class, ['record' => $novel->getRouteKey()])
        ->assertOk();

    $roleQuery = collect($queries)->first(
        fn (string $sql): bool => str_contains(strtolower($sql), 'select distinct')
            && str_contains($sql, '"role"'),
    );

    expect($roleQuery)
        ->not->toBeNull()
        ->not->toContain('order by "name"')
        ->toContain('order by "role" asc');
});

test('character management remains inside the novel workspace', function () {
    $novel = Novel::factory()->create();

    $this->get(NovelResource::getUrl('characters', ['record' => $novel]))
        ->assertOk()
        ->assertSee('角色管理')
        ->assertSee('小说')
        ->assertSee('概览')
        ->assertSee('角色');
});
