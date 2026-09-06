<?php

use App\Enums\VolumeStatus;
use App\Models\Novel;
use App\Models\Volume;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a volume belongs to a novel and casts its domain fields', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create([
        'sequence' => 2,
        'target_words' => 240_000,
        'status' => VolumeStatus::Active,
    ])->fresh();

    expect($volume->novel->is($novel))->toBeTrue()
        ->and($volume->sequence)->toBe(2)
        ->and($volume->target_words)->toBe(240_000)
        ->and($volume->status)->toBe(VolumeStatus::Active);
});

test('novel volumes are resolved in sequence order', function () {
    $novel = Novel::factory()->create();
    Volume::factory()->for($novel)->create(['sequence' => 3]);
    Volume::factory()->for($novel)->create(['sequence' => 1]);
    Volume::factory()->for($novel)->create(['sequence' => 2]);

    expect($novel->volumes()->pluck('sequence')->all())->toBe([1, 2, 3]);
});

test('volume sequence is unique inside a novel', function () {
    $novel = Novel::factory()->create();
    Volume::factory()->for($novel)->create(['sequence' => 1]);

    expect(fn () => Volume::factory()->for($novel)->create(['sequence' => 1]))
        ->toThrow(QueryException::class);
});

test('different novels may use the same volume sequence', function () {
    Volume::factory()->create(['sequence' => 1]);
    Volume::factory()->create(['sequence' => 1]);

    expect(Volume::query()->count())->toBe(2);
});

test('the database rejects invalid volume numeric values', function (string $field, int $value) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => Volume::factory()->create([$field => $value]))
        ->toThrow(QueryException::class);
})->with([
    'non-positive sequence' => ['sequence', 0],
    'non-positive target words' => ['target_words', 0],
]);

test('the volumes migration can be rolled back', function () {
    expect(Schema::hasTable('volumes'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_110000_create_volumes_table.php');
    $migration->down();

    expect(Schema::hasTable('volumes'))->toBeFalse();
});
