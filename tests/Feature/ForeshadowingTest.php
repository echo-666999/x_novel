<?php

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\ForeshadowingTimingStatus;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\StoryArc;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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

test('timing status uses the next chapter derived from the canonical pointer at every boundary', function () {
    $foreshadowing = Foreshadowing::factory()->make([
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 10,
        'due_to_chapter' => 20,
    ]);

    expect($foreshadowing->timingStatus(8))->toBe(ForeshadowingTimingStatus::Upcoming)
        ->and($foreshadowing->timingStatus(9))->toBe(ForeshadowingTimingStatus::Due)
        ->and($foreshadowing->timingStatus(19))->toBe(ForeshadowingTimingStatus::Due)
        ->and($foreshadowing->timingStatus(20))->toBe(ForeshadowingTimingStatus::Overdue)
        ->and($foreshadowing->timingStatusForTargetChapter(10))->toBe(ForeshadowingTimingStatus::Due)
        ->and($foreshadowing->timingStatusForTargetChapter(21))->toBe(ForeshadowingTimingStatus::Overdue);
});

test('terminal content has no timing state and legacy due remains readable without controlling timing', function () {
    $legacy = Foreshadowing::factory()->make([
        'status' => ForeshadowingStatus::Due,
        'due_from_chapter' => 10,
        'due_to_chapter' => 20,
    ]);
    $paidOff = Foreshadowing::factory()->make([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::PaidOff,
        'due_to_chapter' => 5,
    ]);

    expect($legacy->requiresLegacyStatusMigration())->toBeTrue()
        ->and($legacy->timingStatus(null))->toBe(ForeshadowingTimingStatus::Upcoming)
        ->and(ForeshadowingStatus::contentOptions())->not->toHaveKey(ForeshadowingStatus::Due->value)
        ->and($paidOff->timingStatus(20))->toBeNull()
        ->and($paidOff->attentionBadges(20))->toBe(['关键']);
});

test('new legacy due writes are rejected while an existing legacy row can be migrated', function () {
    expect(fn () => Foreshadowing::factory()->create(['status' => ForeshadowingStatus::Due]))
        ->toThrow(ValidationException::class, 'due 是待迁移的旧状态');

    $foreshadowing = Foreshadowing::factory()->create(['status' => ForeshadowingStatus::Idea]);
    DB::table($foreshadowing->getTable())->where('id', $foreshadowing->getKey())->update(['status' => 'due']);
    $legacy = $foreshadowing->fresh();

    expect($legacy->status)->toBe(ForeshadowingStatus::Due)
        ->and($legacy->requiresLegacyStatusMigration())->toBeTrue();

    $legacy->update(['status' => ForeshadowingStatus::Reinforced]);

    expect($legacy->fresh()->status)->toBe(ForeshadowingStatus::Reinforced);
});

test('timing query scopes use the same canonical boundaries as the model', function () {
    $novel = Novel::factory()->create();
    $upcoming = Foreshadowing::factory()->for($novel)->create(['due_from_chapter' => 11, 'due_to_chapter' => 20]);
    $due = Foreshadowing::factory()->for($novel)->create(['due_from_chapter' => 10, 'due_to_chapter' => 20]);
    $overdue = Foreshadowing::factory()->for($novel)->create(['due_from_chapter' => 1, 'due_to_chapter' => 9]);
    $terminal = Foreshadowing::factory()->for($novel)->create([
        'due_from_chapter' => 1,
        'due_to_chapter' => 9,
        'status' => ForeshadowingStatus::PaidOff,
    ]);

    expect(Foreshadowing::query()->withTimingStatusAt(ForeshadowingTimingStatus::Upcoming, 9)->pluck('id')->all())->toBe([$upcoming->getKey()])
        ->and(Foreshadowing::query()->withTimingStatusAt(ForeshadowingTimingStatus::Due, 9)->pluck('id')->all())->toBe([$due->getKey()])
        ->and(Foreshadowing::query()->withTimingStatusAt(ForeshadowingTimingStatus::Overdue, 9)->pluck('id')->all())->toBe([$overdue->getKey()])
        ->and(Foreshadowing::query()->requiringAttentionForTargetChapter(10)->pluck('id')->all())
        ->toEqualCanonicalizing([$due->getKey(), $overdue->getKey()])
        ->not->toContain($terminal->getKey());
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
