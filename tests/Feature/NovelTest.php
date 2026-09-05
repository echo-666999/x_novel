<?php

use App\Enums\NovelStatus;
use App\Models\Novel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('a novel casts status settings and numeric fields', function () {
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['temperature' => 0.7],
        'target_words' => 1_000_000,
        'current_chapter_sequence' => 12,
    ])->fresh();

    expect($novel->status)->toBe(NovelStatus::Generating)
        ->and($novel->settings)->toBe(['temperature' => 0.7])
        ->and($novel->target_words)->toBe(1_000_000)
        ->and($novel->current_chapter_sequence)->toBe(12);
});

test('a novel defaults to the draft status and empty settings', function () {
    $novel = Novel::query()->create([
        'title' => '长夜将明',
        'genre' => '玄幻',
        'target_words' => 800_000,
    ])->fresh();

    expect($novel->status)->toBe(NovelStatus::Draft)
        ->and($novel->settings)->toBe([]);
});

test('the database rejects a non positive target word count', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => Novel::factory()->create(['target_words' => 0]))
        ->toThrow(QueryException::class);
});

test('the novels migration can be rolled back', function () {
    expect(Schema::hasTable('novels'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_022535_create_novels_table.php');
    $migration->down();

    expect(Schema::hasTable('novels'))->toBeFalse();
});
