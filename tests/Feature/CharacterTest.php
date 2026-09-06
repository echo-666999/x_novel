<?php

use App\Enums\CharacterStatus;
use App\Models\Character;
use App\Models\Novel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a character belongs to a novel and casts its domain fields', function () {
    $novel = Novel::factory()->create();
    $character = Character::factory()->for($novel)->create([
        'aliases' => ['阿澜', '守灯人'],
        'profile' => ['age' => '19'],
        'personality' => ['cautious' => '遇事先观察'],
        'abilities' => ['sailing' => '熟练'],
        'knowledge' => ['fog_tide' => '知道周期'],
        'current_state' => ['location' => '旧港'],
        'locked_fields' => ['profile.age'],
        'status' => CharacterStatus::Inactive,
    ])->fresh();

    expect($character->novel->is($novel))->toBeTrue()
        ->and($character->aliases)->toBe(['阿澜', '守灯人'])
        ->and($character->profile)->toBe(['age' => '19'])
        ->and($character->personality)->toBe(['cautious' => '遇事先观察'])
        ->and($character->abilities)->toBe(['sailing' => '熟练'])
        ->and($character->knowledge)->toBe(['fog_tide' => '知道周期'])
        ->and($character->current_state)->toBe(['location' => '旧港'])
        ->and($character->locked_fields)->toBe(['profile.age'])
        ->and($character->status)->toBe(CharacterStatus::Inactive);
});

test('a novel exposes its characters in name order', function () {
    $novel = Novel::factory()->create();
    Character::factory()->for($novel)->create(['name' => '周宁']);
    Character::factory()->for($novel)->create(['name' => '阿澜']);

    expect($novel->characters()->pluck('name')->all())->toBe(['周宁', '阿澜']);
});

test('deleting a novel deletes its characters', function () {
    $novel = Novel::factory()->create();
    $character = Character::factory()->for($novel)->create();

    $novel->delete();

    expect(Character::query()->find($character->getKey()))->toBeNull();
});

test('the database rejects an unsupported character status', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => DB::table('characters')->insert([
        'novel_id' => Novel::factory()->create()->getKey(),
        'name' => '无名者',
        'aliases' => '[]',
        'role' => '配角',
        'profile' => '{}',
        'motivation' => '未知',
        'personality' => '{}',
        'abilities' => '{}',
        'knowledge' => '{}',
        'current_state' => '{}',
        'locked_fields' => '[]',
        'status' => 'unsupported',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the characters migration can be rolled back', function () {
    expect(Schema::hasTable('characters'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_130000_create_characters_table.php');
    $migration->down();

    expect(Schema::hasTable('characters'))->toBeFalse();
});
