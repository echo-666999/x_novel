<?php

use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Enums\NovelOutlineStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\StoryArc;
use App\Models\Volume;
use Database\Factories\Support\NormalizedOutlineDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('outline header exposes immutable normalized relationships and casts', function () {
    $novel = Novel::factory()->create();
    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)
        ->handle($novel, NormalizedOutlineDefinition::make());

    expect($outline->novel->is($novel))->toBeTrue()
        ->and($outline->version)->toBe(1)
        ->and($outline->status)->toBe(NovelOutlineStatus::Draft)
        ->and($outline->must_include)->toBeArray()
        ->and($outline->volumes)->toHaveCount(1)
        ->and($outline->arcs)->toHaveCount(1)
        ->and($outline->beats)->toHaveCount(1)
        ->and($outline->milestones)->toHaveCount(1);
});

test('runtime volume and arc reference normalized source definitions', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();
    $arc = StoryArc::factory()->forVolume($volume)->create();

    expect($volume->sourceOutlineVolume->outline->novel->is($novel))->toBeTrue()
        ->and($arc->sourceOutlineArc->volume->is($volume->sourceOutlineVolume))->toBeTrue()
        ->and($arc->sourceOutlineArc->beats)->toHaveCount(1);
});

test('outline version is unique within one novel', function () {
    $novel = Novel::factory()->create();
    NovelOutline::factory()->for($novel)->create(['version' => 1]);

    expect(fn () => NovelOutline::factory()->for($novel)->create(['version' => 1]))
        ->toThrow(QueryException::class);
});

test('chapter plans reject null normalized outline references at the database boundary', function () {
    $chapter = Chapter::factory()->create();
    $attributes = ChapterPlan::factory()->raw(['chapter_id' => $chapter->getKey()]);
    $attributes['novel_outline_id'] = null;
    $attributes['primary_outline_arc_id'] = null;
    $attributes['primary_outline_beat_id'] = null;
    $attributes['primary_outline_milestone_id'] = null;

    expect(fn () => DB::table('chapter_plans')->insert($attributes))->toThrow(QueryException::class);
});

test('normalized outline source ids are unique for runtime projections', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();

    expect(fn () => Volume::factory()->for($novel)->create([
        'sequence' => 2,
        'source_outline_volume_id' => $volume->source_outline_volume_id,
    ]))->toThrow(QueryException::class);
});
