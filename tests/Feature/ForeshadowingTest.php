<?php

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\StoryArc;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a foreshadowing belongs to its novel and optional owner arc and casts domain fields', function () {
    $novel = Novel::factory()->create();
    $arc = StoryArc::factory()->for($novel)->create();
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'owner_arc_id' => $arc->getKey(),
        'setup_chapter_id' => 3,
        'due_from_chapter' => 20,
        'due_to_chapter' => 30,
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Reinforced,
        'reinforce_count' => 2,
        'payoff_chapter_id' => 28,
    ])->fresh();

    expect($foreshadowing->novel->is($novel))->toBeTrue()
        ->and($foreshadowing->ownerArc->is($arc))->toBeTrue()
        ->and($foreshadowing->setup_chapter_id)->toBe(3)
        ->and($foreshadowing->due_from_chapter)->toBe(20)
        ->and($foreshadowing->due_to_chapter)->toBe(30)
        ->and($foreshadowing->importance)->toBe(ForeshadowingImportance::Critical)
        ->and($foreshadowing->status)->toBe(ForeshadowingStatus::Reinforced)
        ->and($foreshadowing->reinforce_count)->toBe(2)
        ->and($foreshadowing->payoff_chapter_id)->toBe(28);
});

test('due overdue and critical attention badges reflect the canonical chapter pointer', function () {
    $due = Foreshadowing::factory()->make([
        'status' => ForeshadowingStatus::Planted,
        'due_from_chapter' => 10,
        'due_to_chapter' => 20,
    ]);
    $overdue = Foreshadowing::factory()->make([
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 1,
        'due_to_chapter' => 9,
    ]);
    $critical = Foreshadowing::factory()->make([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Due,
    ]);
    $paidOff = Foreshadowing::factory()->make([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::PaidOff,
        'due_to_chapter' => 5,
    ]);

    expect($due->attentionBadges(15))->toBe(['Due'])
        ->and($overdue->attentionBadges(15))->toBe(['Overdue'])
        ->and($critical->attentionBadges(null))->toBe(['Critical', 'Due'])
        ->and($paidOff->attentionBadges(15))->toBe(['Critical']);
});

test('an owner arc must belong to the same novel', function () {
    $novel = Novel::factory()->create();
    $otherArc = StoryArc::factory()->create();

    expect(fn () => Foreshadowing::factory()->for($novel)->create([
        'owner_arc_id' => $otherArc->getKey(),
    ]))->toThrow(QueryException::class);
});

test('the database rejects an invalid due window', function (int $from, int $to) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => Foreshadowing::factory()->create([
        'due_from_chapter' => $from,
        'due_to_chapter' => $to,
    ]))->toThrow(QueryException::class);
})->with([
    'non-positive start' => [0, 10],
    'end before start' => [20, 19],
]);

test('the foreshadowings migration can be rolled back', function () {
    expect(Schema::hasTable('foreshadowings'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_150000_create_foreshadowings_table.php');
    $migration->down();

    expect(Schema::hasTable('foreshadowings'))->toBeFalse();
});
