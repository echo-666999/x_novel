<?php

use App\Actions\Novels\DeleteNovelAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\FactSourceType;
use App\Enums\ForeshadowingStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Jobs\EndingAuditJob;
use App\Jobs\FinalizeNovelOutlineJob;
use App\Jobs\GenerateNovelBeatDetailJob;
use App\Jobs\GenerateNovelFoundationJob;
use App\Jobs\GenerateNovelOutlineJob;
use App\Jobs\GenerateNovelOutlineSkeletonJob;
use App\Jobs\RefreshNovelProjectionJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Review;
use App\Models\Scene;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\UsageRecord;
use App\Models\WorldEntity;
use App\Services\EndingAuditService;
use App\Services\NovelOutlinePipeline;
use App\Services\ProjectionRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('it deletes an empty paused novel', function () {
    $novel = Novel::factory()->create([
        'title' => '空白长篇',
        'status' => NovelStatus::Paused,
    ]);

    $impact = app(DeleteNovelAction::class)->impact($novel);
    $result = app(DeleteNovelAction::class)->execute($novel, '空白长篇', '删除误建项目', 7);

    expect($impact)->toMatchArray([
        'status' => 'ready',
        'chapters' => 0,
        'outlines' => 0,
        'runs' => 0,
        'usage' => 0,
    ])->and($result['status'])->toBe('deleted')
        ->and($result['novel_id'])->toBe($novel->getKey())
        ->and(Novel::query()->find($novel->getKey()))->toBeNull();
});

test('it deletes a complete novel graph without orphaned tracking records', function () {
    $fixture = fullNovelDeletionFixture();
    $novel = $fixture['novel'];
    $impact = app(DeleteNovelAction::class)->impact($novel);

    expect($impact['bibles'])->toBe(1)
        ->and($impact['outlines'])->toBeGreaterThan(0)
        ->and($impact['outline_volumes'])->toBeGreaterThan(0)
        ->and($impact['outline_arcs'])->toBeGreaterThan(0)
        ->and($impact['outline_beats'])->toBeGreaterThan(0)
        ->and($impact['outline_milestones'])->toBeGreaterThan(0)
        ->and($impact)->toMatchArray([
            'chapters' => 1,
            'plans' => 1,
            'scenes' => 1,
            'characters' => 1,
            'world_entities' => 1,
            'foreshadowings' => 1,
            'state_versions' => 2,
            'facts' => 1,
            'events' => 1,
            'memories' => 1,
            'runs' => 1,
            'artifacts' => 1,
            'reviews' => 1,
            'usage' => 1,
        ]);

    app(DeleteNovelAction::class)->execute($novel, $novel->title, '完整删除测试');

    foreach (novelOwnedTables() as $table) {
        expect(DB::table($table)->where('novel_id', $novel->getKey())->count(), $table)->toBe(0);
    }

    expect(UsageRecord::query()->find($fixture['usage']->getKey()))->toBeNull()
        ->and(GenerationArtifact::query()->find($fixture['artifact']->getKey()))->toBeNull()
        ->and(Review::query()->find($fixture['review']->getKey()))->toBeNull()
        ->and(StoryStateVersion::query()->find($fixture['state']->getKey()))->toBeNull()
        ->and(Memory::query()->find($fixture['memory']->getKey()))->toBeNull()
        ->and(DB::table('novel_outline_volumes')->whereIn('novel_outline_id', $fixture['outline_ids'])->exists())->toBeFalse()
        ->and(DB::table('novel_outline_arcs')->whereIn('novel_outline_id', $fixture['outline_ids'])->exists())->toBeFalse()
        ->and(DB::table('novel_outline_beats')->whereIn('novel_outline_id', $fixture['outline_ids'])->exists())->toBeFalse()
        ->and(DB::table('novel_outline_milestones')->whereIn('novel_outline_id', $fixture['outline_ids'])->exists())->toBeFalse();
});

test('deleting one novel leaves every other novel record untouched', function () {
    $target = fullNovelDeletionFixture();
    $other = fullNovelDeletionFixture('保留小说');

    app(DeleteNovelAction::class)->execute($target['novel'], $target['novel']->title, '只删除目标小说');

    expect($other['novel']->fresh())->not->toBeNull()
        ->and($other['chapter']->fresh())->not->toBeNull()
        ->and($other['run']->fresh())->not->toBeNull()
        ->and($other['artifact']->fresh())->not->toBeNull()
        ->and($other['usage']->fresh())->not->toBeNull()
        ->and($other['memory']->fresh())->not->toBeNull();
});

test('it rejects deletion while any novel run is queued or running', function (RunStatus $status) {
    $novel = Novel::factory()->create(['title' => '运行中的书', 'status' => NovelStatus::Paused]);
    GenerationRun::factory()->for($novel)->create(['status' => $status]);

    expect(fn () => app(DeleteNovelAction::class)->execute($novel, '运行中的书', '尝试删除'))
        ->toThrow(ValidationException::class, '排队中或运行中')
        ->and($novel->fresh())->not->toBeNull();
})->with([RunStatus::Queued, RunStatus::Running]);

test('it rejects an incorrect confirmation title and requires a paused novel', function () {
    $novel = Novel::factory()->create(['title' => '必须准确输入', 'status' => NovelStatus::Paused]);

    expect(fn () => app(DeleteNovelAction::class)->execute($novel, '必须准确', '确认文本错误'))
        ->toThrow(ValidationException::class, '完整标题不一致');

    $novel->update(['status' => NovelStatus::Draft]);

    expect(fn () => app(DeleteNovelAction::class)->execute($novel, '必须准确输入', '尚未暂停'))
        ->toThrow(ValidationException::class, '必须先暂停')
        ->and($novel->fresh())->not->toBeNull();
});

test('it rolls back the entire graph when post deletion verification fails', function () {
    $fixture = fullNovelDeletionFixture();
    $action = new class extends DeleteNovelAction
    {
        protected function assertDeleted(array $context): void
        {
            throw new RuntimeException('forced deletion verification failure');
        }
    };

    expect(fn () => $action->execute($fixture['novel'], $fixture['novel']->title, '验证事务回滚'))
        ->toThrow(RuntimeException::class, 'forced deletion verification failure')
        ->and($fixture['novel']->fresh())->not->toBeNull()
        ->and($fixture['chapter']->fresh())->not->toBeNull()
        ->and($fixture['run']->fresh())->not->toBeNull()
        ->and($fixture['usage']->fresh())->not->toBeNull();
});

test('repeating deletion with the same stale novel is idempotent', function () {
    $novel = Novel::factory()->create(['title' => '重复删除', 'status' => NovelStatus::Paused]);
    $action = app(DeleteNovelAction::class);

    expect($action->execute($novel, '重复删除', '第一次')['status'])->toBe('deleted')
        ->and($action->execute($novel, '重复删除', '重复投递')['status'])->toBe('already_deleted');
});

test('queued novel jobs safely stop after their novel or batch run was deleted', function () {
    $pipeline = Mockery::mock(NovelOutlinePipeline::class);
    $pipeline->shouldNotReceive('startOrResume', 'generateFoundation', 'generateSkeleton', 'generateBeatDetail', 'finalize', 'dispatchNext');
    (new GenerateNovelOutlineJob(990_001, 3))->handle($pipeline);
    (new GenerateNovelFoundationJob(990_002))->handle($pipeline);
    (new GenerateNovelOutlineSkeletonJob(990_002))->handle($pipeline);
    (new GenerateNovelBeatDetailJob(990_002, 'missing-beat'))->handle($pipeline);
    (new FinalizeNovelOutlineJob(990_002))->handle($pipeline);

    $ending = Mockery::mock(EndingAuditService::class);
    $ending->shouldNotReceive('audit');
    (new EndingAuditJob(990_001))->handle($ending);

    $projection = Mockery::mock(ProjectionRebuilder::class);
    $projection->shouldNotReceive('rebuild');
    (new RefreshNovelProjectionJob(990_001, 990_003))->handle($projection);

    expect(true)->toBeTrue();
});

/** @return array<string, mixed> */
function fullNovelDeletionFixture(string $title = '待删除长篇'): array
{
    $novel = Novel::factory()->create(['title' => $title, 'status' => NovelStatus::Paused]);
    NovelBible::factory()->for($novel)->create();
    $character = Character::factory()->for($novel)->create();
    $world = WorldEntity::factory()->for($novel)->create();
    $foreshadowing = Foreshadowing::factory()->for($novel)->create(['status' => ForeshadowingStatus::Idea]);
    $baseline = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
    ]);
    $plan = ChapterPlan::factory()->for($chapter)->create();
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 1, 'pov_character_id' => $character->getKey()]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::OutlineBlueprint]);
    DB::table('novel_outlines')->where('novel_id', $novel->getKey())->limit(1)->update([
        'source_artifact_id' => $artifact->getKey(),
    ]);
    $review = Review::factory()->for($run)->create(['artifact_id' => $artifact->getKey()]);
    $usage = UsageRecord::factory()->create([
        'generation_run_id' => $run->getKey(),
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
    ]);
    $state = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
        'version' => 1,
        'state' => $baseline->state,
        'checksum' => $baseline->checksum,
    ]);
    $event = StoryEvent::factory()->for($novel)->for($chapter)->for($scene)->create([
        'event_type' => EventType::CharacterMoved,
        'subject_type' => 'character',
        'subject_id' => (string) $character->getKey(),
        'state_version' => 1,
    ]);
    Fact::factory()->for($novel)->create([
        'subject_type' => 'character',
        'subject_id' => $character->getKey(),
        'source_type' => FactSourceType::StoryEvent,
        'source_event_id' => $event->getKey(),
    ]);
    $memory = Memory::factory()->for($novel)->create([
        'source_type' => 'story_event',
        'source_id' => $event->getKey(),
        'valid_from_chapter' => 1,
    ]);
    $arc = $novel->storyArcs()->firstOrFail();
    $foreshadowing->update(['owner_arc_id' => $arc->getKey(), 'setup_chapter_id' => $chapter->getKey()]);
    $character->update(['source_chapter_id' => $chapter->getKey()]);
    $world->update(['source_chapter_id' => $chapter->getKey()]);
    $chapter->update(['canonical_artifact_id' => $artifact->getKey()]);
    $novel->update([
        'status' => NovelStatus::Paused,
        'canonical_state_version_id' => $state->getKey(),
        'current_chapter_sequence' => 1,
    ]);

    return [
        'novel' => $novel->fresh(),
        'chapter' => $chapter,
        'plan' => $plan,
        'scene' => $scene,
        'run' => $run,
        'artifact' => $artifact,
        'review' => $review,
        'usage' => $usage,
        'state' => $state,
        'memory' => $memory,
        'outline_ids' => $novel->outlines()->pluck('id'),
    ];
}

/** @return array<int, string> */
function novelOwnedTables(): array
{
    return [
        'novel_bibles',
        'novel_outlines',
        'volumes',
        'story_arcs',
        'chapters',
        'characters',
        'world_entities',
        'foreshadowings',
        'story_state_versions',
        'facts',
        'story_events',
        'memories',
        'generation_runs',
    ];
}
