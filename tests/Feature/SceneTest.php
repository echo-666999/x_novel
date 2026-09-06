<?php

use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\Scene;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a scene belongs to a chapter and stores generation planning fields', function () {
    $chapter = Chapter::factory()->create();
    $pov = Character::factory()->for($chapter->novel)->create();
    $scene = Scene::factory()->for($chapter)->create([
        'sequence' => 2,
        'pov_character_id' => $pov->getKey(),
        'status' => SceneStatus::Draft,
    ])->fresh();

    expect($scene->chapter->is($chapter))->toBeTrue()
        ->and($scene->povCharacter->is($pov))->toBeTrue()
        ->and($scene->sequence)->toBe(2)
        ->and($scene->status)->toBe(SceneStatus::Draft)
        ->and($chapter->scenes()->pluck('sequence')->all())->toBe([2]);
});

test('scene sequence is unique inside a chapter and reusable by another chapter', function () {
    $chapter = Chapter::factory()->create();
    Scene::factory()->for($chapter)->create(['sequence' => 1]);

    expect(fn () => Scene::factory()->for($chapter)->create(['sequence' => 1]))
        ->toThrow(QueryException::class);

    Scene::factory()->create(['sequence' => 1]);

    expect(Scene::query()->count())->toBe(2);
});

test('deleting a chapter deletes its scenes', function () {
    $chapter = Chapter::factory()->create();
    Scene::factory()->for($chapter)->create();

    $chapter->delete();

    expect(Scene::query()->count())->toBe(0);
});

test('postgresql rejects invalid scene sequence and status', function (array $attributes) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => Scene::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    'non-positive sequence' => [['sequence' => 0]],
    'unknown status' => [['status' => 'unknown']],
]);

test('the scenes migration can be rolled back', function () {
    expect(Schema::hasTable('scenes'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_07_120000_create_scenes_table.php');
    $migration->down();

    expect(Schema::hasTable('scenes'))->toBeFalse();
});
