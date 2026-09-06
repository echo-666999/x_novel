<?php

use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Models\Fact;
use App\Models\Novel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a fact belongs to its novel and casts canonical fields', function () {
    $novel = Novel::factory()->create();
    $fact = Fact::factory()->for($novel)->create([
        'subject_type' => 'character',
        'subject_id' => 42,
        'predicate' => 'can_swim',
        'value' => ['value' => false, 'reason' => '童年事故'],
        'hardness' => FactHardness::Hard,
        'confidence' => 0.975,
        'status' => FactStatus::Active,
        'locked' => true,
        'source_type' => FactSourceType::Manual,
    ])->fresh();

    expect($fact->novel->is($novel))->toBeTrue()
        ->and($novel->facts()->first()->is($fact))->toBeTrue()
        ->and($fact->subject_id)->toBe(42)
        ->and($fact->value)->toBe(['value' => false, 'reason' => '童年事故'])
        ->and($fact->hardness)->toBe(FactHardness::Hard)
        ->and($fact->confidence)->toBe(0.975)
        ->and($fact->status)->toBe(FactStatus::Active)
        ->and($fact->locked)->toBeTrue()
        ->and($fact->source_type)->toBe(FactSourceType::Manual);
});

test('deleting a novel deletes its facts', function () {
    $novel = Novel::factory()->create();
    $fact = Fact::factory()->for($novel)->create();

    $novel->delete();

    expect(Fact::query()->find($fact->getKey()))->toBeNull();
});

test('postgresql enforces fact confidence', function (float $confidence) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => Fact::factory()->create(['confidence' => $confidence]))->toThrow(QueryException::class);
})->with([
    'below zero' => [-0.1],
    'above one' => [1.1],
]);

test('the facts migration can be rolled back', function () {
    expect(Schema::hasTable('facts'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_170000_create_facts_table.php');
    $migration->down();

    expect(Schema::hasTable('facts'))->toBeFalse();
});
