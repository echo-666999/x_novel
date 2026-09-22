<?php

use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\StoryArc;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('outline models cast fields and expose version relationships', function () {
    $novel = Novel::factory()->create();
    $creator = User::factory()->create();
    $base = NovelOutline::factory()->for($novel)->create([
        'version' => 1,
        'source' => NovelOutlineSource::Ai,
    ]);
    $current = NovelOutline::factory()->for($novel)->create([
        'version' => 2,
        'status' => NovelOutlineStatus::Current,
        'source' => NovelOutlineSource::Revision,
        'based_on_outline_id' => $base->getKey(),
        'created_by' => $creator->getKey(),
        'applied_at' => now(),
    ]);
    $novel->update(['current_outline_id' => $current->getKey()]);

    $current->refresh();
    $novel->refresh();

    expect($current->status)->toBe(NovelOutlineStatus::Current)
        ->and($current->source)->toBe(NovelOutlineSource::Revision)
        ->and($current->content)->toBeArray()
        ->and($current->basedOn->is($base))->toBeTrue()
        ->and($current->creator->is($creator))->toBeTrue()
        ->and($base->revisions->sole()->is($current))->toBeTrue()
        ->and($novel->currentOutline->is($current))->toBeTrue()
        ->and($novel->outlines->pluck('version')->all())->toBe([2, 1]);
});

test('outline source fields connect projections without changing beat content', function () {
    $novel = Novel::factory()->create();
    $outline = NovelOutline::factory()->for($novel)->create();
    $volume = Volume::factory()->for($novel)->create(['outline_key' => 'volume-academy']);
    $arc = StoryArc::factory()->forVolume($volume)->create([
        'outline_key' => 'arc-academy-growth',
        'sequence' => 3,
        'beats' => ['旧节点原文'],
    ]);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create();
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'novel_outline_id' => $outline->getKey(),
        'character_candidates' => [['candidate_key' => 'character-professor']],
    ]);
    $character = Character::factory()->for($novel)->create([
        'source_chapter_id' => $chapter->getKey(),
        'source_candidate_key' => 'character-professor',
    ]);

    expect($volume->outline_key)->toBe('volume-academy')
        ->and($arc->sequence)->toBe(3)
        ->and($arc->beats)->toBe(['旧节点原文'])
        ->and($plan->novelOutline->is($outline))->toBeTrue()
        ->and($plan->character_candidates)->toBe([['candidate_key' => 'character-professor']])
        ->and($outline->chapterPlans->sole()->is($plan))->toBeTrue()
        ->and($character->sourceChapter->is($chapter))->toBeTrue();
});

test('a novel rejects duplicate outline versions', function () {
    $novel = Novel::factory()->create();
    NovelOutline::factory()->for($novel)->create([
        'version' => 1,
    ]);

    expect(fn () => NovelOutline::factory()->for($novel)->create([
        'version' => 1,
        'status' => NovelOutlineStatus::Draft,
    ]))->toThrow(QueryException::class);
});

test('a novel rejects multiple current outlines', function () {
    $novel = Novel::factory()->create();
    NovelOutline::factory()->for($novel)->create([
        'version' => 1,
        'status' => NovelOutlineStatus::Current,
    ]);

    expect(fn () => NovelOutline::factory()->for($novel)->create([
        'version' => 2,
        'status' => NovelOutlineStatus::Current,
    ]))->toThrow(QueryException::class);
});

test('volume outline keys are unique within a novel', function () {
    $novel = Novel::factory()->create();
    Volume::factory()->for($novel)->create([
        'outline_key' => 'volume-academy',
        'sequence' => 1,
    ]);

    expect(fn () => Volume::factory()->for($novel)->create([
        'outline_key' => 'volume-academy',
        'sequence' => 2,
    ]))->toThrow(QueryException::class);
});

test('story arc sequences are unique within a volume', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();
    StoryArc::factory()->forVolume($volume)->create([
        'outline_key' => 'arc-growth',
        'sequence' => 1,
    ]);

    expect(fn () => StoryArc::factory()->forVolume($volume)->create([
        'outline_key' => 'arc-other',
        'sequence' => 1,
    ]))->toThrow(QueryException::class);
});

test('story arc outline keys are unique within a novel', function () {
    $novel = Novel::factory()->create();
    $firstVolume = Volume::factory()->for($novel)->create(['sequence' => 1]);
    $secondVolume = Volume::factory()->for($novel)->create(['sequence' => 2]);
    StoryArc::factory()->forVolume($firstVolume)->create([
        'outline_key' => 'arc-growth',
    ]);

    expect(fn () => StoryArc::factory()->forVolume($secondVolume)->create([
        'outline_key' => 'arc-growth',
    ]))->toThrow(QueryException::class);
});

test('character candidate sources are unique within a novel', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->for($volume)->create();
    Character::factory()->for($novel)->create([
        'source_chapter_id' => $chapter->getKey(),
        'source_candidate_key' => 'character-professor',
    ]);

    expect(fn () => Character::factory()->for($novel)->create([
        'source_chapter_id' => $chapter->getKey(),
        'source_candidate_key' => 'character-professor',
    ]))->toThrow(QueryException::class);
});

test('deleting a novel cascades its outlines', function () {
    $novel = Novel::factory()->create();
    $outline = NovelOutline::factory()->for($novel)->create([
        'status' => NovelOutlineStatus::Current,
    ]);
    $novel->update(['current_outline_id' => $outline->getKey()]);

    $novel->delete();

    $this->assertDatabaseMissing('novel_outlines', ['id' => $outline->getKey()]);
});

test('chapter plans reject missing outline references', function () {
    expect(fn () => ChapterPlan::factory()->create([
        'novel_outline_id' => PHP_INT_MAX,
    ]))->toThrow(QueryException::class);
});

test('outline field migration backfills arc sequences without rewriting legacy beats', function () {
    $migration = require database_path('migrations/2026_09_22_101000_add_novel_outline_fields.php');
    $migration->down();

    $now = now();
    $novelId = DB::table('novels')->insertGetId([
        'title' => '旧小说',
        'genre' => '玄幻',
        'target_words' => 800_000,
        'status' => 'draft',
        'settings' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $volumeId = DB::table('volumes')->insertGetId([
        'novel_id' => $novelId,
        'sequence' => 1,
        'title' => '旧分卷',
        'goal' => '完成旧分卷',
        'climax' => '旧分卷高潮',
        'target_words' => 100_000,
        'status' => 'planned',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $arcIds = collect([
        ['title' => '第一故事线', 'beats' => ['第一旧节点']],
        ['title' => '第二故事线', 'beats' => ['第二旧节点']],
    ])->map(fn (array $arc): int => DB::table('story_arcs')->insertGetId([
        'novel_id' => $novelId,
        'volume_id' => $volumeId,
        'type' => 'main',
        'title' => $arc['title'],
        'goal' => '推进故事',
        'stakes' => '失败会付出代价',
        'beats' => json_encode($arc['beats'], JSON_THROW_ON_ERROR),
        'completion_conditions' => '[]',
        'progress' => 0,
        'status' => 'planned',
        'created_at' => $now,
        'updated_at' => $now,
    ]))->all();

    $migration->up();

    $rows = DB::table('story_arcs')
        ->whereIn('id', $arcIds)
        ->orderBy('id')
        ->get(['sequence', 'beats']);

    expect($rows->pluck('sequence')->map(fn (mixed $value): int => (int) $value)->all())->toBe([1, 2])
        ->and($rows->map(fn (object $row): array => json_decode($row->beats, true, 512, JSON_THROW_ON_ERROR))->all())
        ->toBe([['第一旧节点'], ['第二旧节点']]);
});

test('postgresql rejects invalid outline enum and positive constraint values', function (array $attributes) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => NovelOutline::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    'zero version' => [['version' => 0]],
    'zero schema version' => [['schema_version' => 0]],
    'invalid status' => [['status' => 'invalid']],
    'invalid source' => [['source' => 'invalid']],
    'non object content' => [['content' => ['not-an-object']]],
]);

test('postgresql rejects a non positive story arc sequence', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraint verification requires PostgreSQL.');
    }

    expect(fn () => StoryArc::factory()->create(['sequence' => 0]))
        ->toThrow(QueryException::class);
});
