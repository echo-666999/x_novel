<?php

use App\Enums\WorldEntityType;
use App\Filament\Forms\Components\CharacterPicker;
use App\Filament\Forms\Components\WorldEntityPicker;
use App\Models\Character;
use App\Models\Novel;
use App\Models\WorldEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the character picker is searchable and scoped to the current novel', function () {
    $novel = Novel::factory()->create();
    $matching = Character::factory()->for($novel)->create([
        'name' => '沈澜',
        'role' => '主角',
    ]);
    $otherRole = Character::factory()->for($novel)->create([
        'name' => '顾舟',
        'role' => '领航员',
    ]);
    $otherNovel = Character::factory()->create([
        'name' => '沈澜同名者',
        'role' => '主角',
    ]);

    $picker = CharacterPicker::make('character_id')->novel($novel);

    expect($picker->isSearchable())->toBeTrue()
        ->and($picker->isPreloaded())->toBeTrue()
        ->and($picker->getOptions())->toHaveCount(2)
        ->and($picker->getOptions()[$otherRole->getKey()])->toBe('顾舟 · 领航员')
        ->and($picker->getOptions()[$matching->getKey()])->toBe('沈澜 · 主角')
        ->and($picker->getSearchResults('沈澜'))->toBe([
            $matching->getKey() => '沈澜 · 主角',
        ])
        ->and($picker->getSearchResults('领航'))->toBe([
            $otherRole->getKey() => '顾舟 · 领航员',
        ])
        ->and($picker->getOptions())->not->toHaveKey($otherNovel->getKey());
});

test('the character picker accepts a dynamic current novel without leaking another novel', function () {
    $novel = Novel::factory()->create();
    $character = Character::factory()->for($novel)->create([
        'name' => '沈澜',
        'role' => '主角',
    ]);
    $otherCharacter = Character::factory()->create();
    $picker = CharacterPicker::make('character_id')->novel(fn (): Novel => $novel);

    expect($picker->getOptions())->toHaveKey($character->getKey(), '沈澜 · 主角')
        ->not->toHaveKey($otherCharacter->getKey());
});

test('the world entity picker is novel scoped searchable and type aware', function () {
    $novel = Novel::factory()->create();
    $location = WorldEntity::factory()->for($novel)->create([
        'name' => '旧港',
        'description' => '雾潮中的避风港。',
        'type' => WorldEntityType::Location,
    ]);
    $item = WorldEntity::factory()->for($novel)->create([
        'name' => '潮汐罗盘',
        'description' => '指向隐藏航路。',
        'type' => WorldEntityType::Item,
    ]);
    $otherNovel = WorldEntity::factory()->create([
        'name' => '另一座旧港',
        'type' => WorldEntityType::Location,
    ]);

    $picker = WorldEntityPicker::make('world_entity_id')->novel($novel);

    expect($picker->isSearchable())->toBeTrue()
        ->and($picker->getOptions())->toBe([
            $location->getKey() => '旧港 · 地点',
            $item->getKey() => '潮汐罗盘 · 物品',
        ])
        ->and($picker->getSearchResults('隐藏航路'))->toBe([
            $item->getKey() => '潮汐罗盘 · 物品',
        ])
        ->and($picker->getOptions())->not->toHaveKey($otherNovel->getKey());
});

test('the world entity picker can restrict results to requested entity types', function () {
    $novel = Novel::factory()->create();
    $location = WorldEntity::factory()->for($novel)->create([
        'name' => '旧港',
        'type' => WorldEntityType::Location,
    ]);
    $item = WorldEntity::factory()->for($novel)->create([
        'name' => '潮汐罗盘',
        'type' => WorldEntityType::Item,
    ]);

    $picker = WorldEntityPicker::make('location_id')
        ->novel($novel->getKey())
        ->types([WorldEntityType::Location]);

    expect($picker->getOptions())->toBe([
        $location->getKey() => '旧港 · 地点',
    ])->not->toHaveKey($item->getKey());
});

test('a quick picker requires an explicit current novel', function () {
    CharacterPicker::make('character_id')->getOptions();
})->throws(LogicException::class, 'requires a current novel via novel()');
