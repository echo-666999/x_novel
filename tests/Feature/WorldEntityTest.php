<?php

use App\Enums\WorldEntityStatus;
use App\Enums\WorldEntityType;
use App\Models\Novel;
use App\Models\WorldEntity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a world entity belongs to a novel and casts its domain fields', function () {
    $novel = Novel::factory()->create();
    $entity = WorldEntity::factory()->for($novel)->create([
        'type' => WorldEntityType::Rule,
        'attributes' => ['scope' => '雾海'],
        'rules' => ['trigger' => '午夜潮汐'],
        'current_state' => ['intensity' => '上升'],
        'locked_fields' => ['rules.trigger'],
        'status' => WorldEntityStatus::Inactive,
    ])->fresh();

    expect($entity->novel->is($novel))->toBeTrue()
        ->and($entity->type)->toBe(WorldEntityType::Rule)
        ->and($entity->attributes)->toBe(['scope' => '雾海'])
        ->and($entity->rules)->toBe(['trigger' => '午夜潮汐'])
        ->and($entity->current_state)->toBe(['intensity' => '上升'])
        ->and($entity->locked_fields)->toBe(['rules.trigger'])
        ->and($entity->status)->toBe(WorldEntityStatus::Inactive);
});

test('a novel exposes its world entities in name order', function () {
    $novel = Novel::factory()->create();
    WorldEntity::factory()->for($novel)->create(['name' => '灯塔']);
    WorldEntity::factory()->for($novel)->create(['name' => '旧港']);

    expect($novel->worldEntities()->pluck('name')->all())->toBe(['旧港', '灯塔']);
});

test('deleting a novel deletes its world entities', function () {
    $novel = Novel::factory()->create();
    $entity = WorldEntity::factory()->for($novel)->create();

    $novel->delete();

    expect(WorldEntity::query()->find($entity->getKey()))->toBeNull();
});

test('the database rejects unsupported world entity enum values', function (string $column) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    $data = WorldEntity::factory()->make()->getAttributes();
    $data['novel_id'] = Novel::factory()->create()->getKey();
    $data[$column] = 'unsupported';
    $data['created_at'] = now();
    $data['updated_at'] = now();

    expect(fn () => DB::table('world_entities')->insert($data))
        ->toThrow(QueryException::class);
})->with(['type', 'status']);

test('the world entities migration can be rolled back', function () {
    expect(Schema::hasTable('world_entities'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_140000_create_world_entities_table.php');
    $migration->down();

    expect(Schema::hasTable('world_entities'))->toBeFalse();
});
