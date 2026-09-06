<?php

use App\Enums\ChapterStatus;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Models\Volume;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a chapter belongs to its novel and optional volume and casts domain fields', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create([
        'volume_id' => $volume->getKey(),
        'sequence' => 12,
        'status' => ChapterStatus::Review,
        'word_count' => 3_500,
    ])->fresh();
    $stateVersion = StoryStateVersion::factory()->for($novel)->create([
        'version' => 1,
        'chapter_id' => $chapter->getKey(),
    ]);

    expect($chapter->novel->is($novel))->toBeTrue()
        ->and($chapter->volume->is($volume))->toBeTrue()
        ->and($chapter->sequence)->toBe(12)
        ->and($chapter->status)->toBe(ChapterStatus::Review)
        ->and($chapter->word_count)->toBe(3_500)
        ->and($chapter->latestStateVersion->is($stateVersion))->toBeTrue()
        ->and($stateVersion->chapter->is($chapter))->toBeTrue();
});

test('novel and volume chapter relations are ordered by sequence', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();
    Chapter::factory()->for($novel)->create(['volume_id' => $volume->getKey(), 'sequence' => 3]);
    Chapter::factory()->for($novel)->create(['volume_id' => $volume->getKey(), 'sequence' => 1]);
    Chapter::factory()->for($novel)->create(['volume_id' => $volume->getKey(), 'sequence' => 2]);

    expect($novel->chapters()->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($volume->chapters()->pluck('sequence')->all())->toBe([1, 2, 3]);
});

test('chapter sequence is unique inside a novel but reusable by another novel', function () {
    $novel = Novel::factory()->create();
    Chapter::factory()->for($novel)->create(['sequence' => 1]);

    expect(fn () => Chapter::factory()->for($novel)->create(['sequence' => 1]))
        ->toThrow(QueryException::class);

    Chapter::factory()->create(['sequence' => 1]);

    expect(Chapter::query()->count())->toBe(2);
});

test('a chapter volume must belong to the same novel', function () {
    $novel = Novel::factory()->create();
    $otherVolume = Volume::factory()->create();

    expect(fn () => Chapter::factory()->for($novel)->create([
        'volume_id' => $otherVolume->getKey(),
    ]))->toThrow(QueryException::class);
});

test('postgresql rejects invalid chapter sequence and status', function (array $attributes) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => Chapter::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    'non-positive sequence' => [['sequence' => 0]],
    'unknown status' => [['status' => 'unknown']],
]);

test('deleting a novel deletes its chapters', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();

    $novel->delete();

    expect(Chapter::query()->find($chapter->getKey()))->toBeNull();
});

test('the chapters migration can be rolled back', function () {
    expect(Schema::hasTable('chapters'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_07_100000_create_chapters_table.php');
    $migration->down();

    expect(Schema::hasTable('chapters'))->toBeFalse();
});
