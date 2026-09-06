<?php

use App\Enums\PlanStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a chapter plan stores executable planning data and casts structured fields', function () {
    $chapter = Chapter::factory()->create();
    $pov = Character::factory()->for($chapter->novel)->create();
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'version' => 2,
        'pov_character_id' => $pov->getKey(),
        'must_reveal' => ['真相'],
        'required_facts' => [12],
        'due_foreshadowings' => [8],
        'status' => PlanStatus::Ready,
    ])->fresh();

    expect($plan->chapter->is($chapter))->toBeTrue()
        ->and($plan->povCharacter->is($pov))->toBeTrue()
        ->and($plan->version)->toBe(2)
        ->and($plan->target_words)->toBeInt()
        ->and($plan->must_reveal)->toBe(['真相'])
        ->and($plan->required_facts)->toBe([12])
        ->and($plan->due_foreshadowings)->toBe([8])
        ->and($plan->scene_plans)->toHaveCount(1)
        ->and($plan->status)->toBe(PlanStatus::Ready)
        ->and($chapter->fresh()->latestPlan->is($plan))->toBeTrue();
});

test('plan version is unique inside a chapter and reusable by another chapter', function () {
    $chapter = Chapter::factory()->create();
    ChapterPlan::factory()->for($chapter)->create(['version' => 1]);

    expect(fn () => ChapterPlan::factory()->for($chapter)->create(['version' => 1]))
        ->toThrow(QueryException::class);

    ChapterPlan::factory()->create(['version' => 1]);

    expect(ChapterPlan::query()->count())->toBe(2);
});

test('deleting a chapter deletes all of its plan versions', function () {
    $chapter = Chapter::factory()->create();
    ChapterPlan::factory()->for($chapter)->create(['version' => 1]);
    ChapterPlan::factory()->for($chapter)->create(['version' => 2]);

    $chapter->delete();

    expect(ChapterPlan::query()->count())->toBe(0);
});

test('postgresql rejects invalid plan versions target words and statuses', function (array $attributes) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => ChapterPlan::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    'non-positive version' => [['version' => 0]],
    'non-positive target words' => [['target_words' => 0]],
    'unknown status' => [['status' => 'unknown']],
]);

test('the chapter plans migration can be rolled back', function () {
    expect(Schema::hasTable('chapter_plans'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_07_110000_create_chapter_plans_table.php');
    $migration->down();

    expect(Schema::hasTable('chapter_plans'))->toBeFalse();
});
