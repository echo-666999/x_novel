<?php

use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\Volume;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a story arc belongs to its novel and optional volume and casts domain fields', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();
    $arc = StoryArc::factory()->forVolume($volume)->create([
        'type' => StoryArcType::Subplot,
        'beats' => ['相遇', '决裂'],
        'completion_conditions' => ['双方重新建立信任'],
        'progress' => 0.35,
        'status' => StoryArcStatus::Active,
    ])->fresh();

    expect($arc->novel->is($novel))->toBeTrue()
        ->and($arc->volume->is($volume))->toBeTrue()
        ->and($arc->type)->toBe(StoryArcType::Subplot)
        ->and($arc->beats)->toBe(['相遇', '决裂'])
        ->and($arc->completion_conditions)->toBe(['双方重新建立信任'])
        ->and($arc->progress)->toBe(0.35)
        ->and($arc->status)->toBe(StoryArcStatus::Active);
});

test('novel and volume expose their story arcs', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();
    $volumeArc = StoryArc::factory()->forVolume($volume)->create();
    $novelArc = StoryArc::factory()->for($novel)->create(['volume_id' => null]);

    expect($novel->storyArcs)->toHaveCount(2)
        ->and($volume->storyArcs)->toHaveCount(1)
        ->and($volume->storyArcs->sole()->is($volumeArc))->toBeTrue()
        ->and($novel->storyArcs->contains($novelArc))->toBeTrue();
});

test('a story arc cannot reference a volume from another novel', function () {
    $novel = Novel::factory()->create();
    $otherVolume = Volume::factory()->create();

    expect(fn () => StoryArc::factory()->for($novel)->create([
        'volume_id' => $otherVolume->getKey(),
    ]))->toThrow(QueryException::class);
});

test('the database rejects progress outside zero and one', function (float $progress) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => StoryArc::factory()->create(['progress' => $progress]))
        ->toThrow(QueryException::class);
})->with([
    'below zero' => -0.01,
    'above one' => 1.01,
]);

test('the story arcs migration can be rolled back', function () {
    expect(Schema::hasTable('story_arcs'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_120000_create_story_arcs_table.php');
    $migration->down();

    expect(Schema::hasTable('story_arcs'))->toBeFalse();
});
