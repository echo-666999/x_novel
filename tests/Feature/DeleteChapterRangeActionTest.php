<?php

use App\Actions\Chapters\DeleteChapterRangeAction;
use App\Actions\Generation\CheckNextAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\FactSourceType;
use App\Enums\ForeshadowingStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Jobs\ContinueAutoGenerationJob;
use App\Jobs\GenerateCanonicalChapterSummaryJob;
use App\Jobs\GenerateEmbeddingJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\Review;
use App\Models\Scene;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\UsageRecord;
use App\Models\WorldEntity;
use App\Services\CanonicalChapterSummaryService;
use App\Services\MemoryEmbedder;
use App\Services\MemoryUpdater;
use App\Services\OutlineProgressResolver;
use App\Services\StoryArcProgressProjector;
use App\Services\StoryStateRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('it deletes the last draft chapter and all dependent runtime records without deleting outline definitions', function () {
    $novel = pausedNovel();
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1]);
    $plan = ChapterPlan::factory()->for($chapter)->create();
    $outlineId = $plan->novel_outline_id;
    $scene = Scene::factory()->for($chapter)->create(['sequence' => 1]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::ReviewResult]);
    Review::factory()->for($run)->create(['artifact_id' => $artifact->getKey()]);
    $usage = UsageRecord::factory()->create([
        'generation_run_id' => $run->getKey(),
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
    ]);

    $impact = app(DeleteChapterRangeAction::class)->impact($chapter);
    $result = app(DeleteChapterRangeAction::class)->execute($chapter, '删除错误建立的草稿章', 7);

    expect($impact)->toMatchArray([
        'range' => '第 1–1 章（含全部后续章节）',
        'chapters' => 1,
        'scenes' => 1,
        'plans' => 1,
        'runs' => 1,
        'artifacts' => 1,
        'reviews' => 1,
        'usage' => 1,
    ])->and($result['status'])->toBe('deleted')
        ->and(Chapter::query()->find($chapter->getKey()))->toBeNull()
        ->and(UsageRecord::query()->find($usage->getKey()))->toBeNull()
        ->and(DB::table('novel_outlines')->where('id', $outlineId)->exists())->toBeTrue()
        ->and(data_get($novel->fresh()->settings, 'chapter_range_deletions.0.reason'))->toBe('删除错误建立的草稿章');
});

test('it deletes the latest canonical chapter and restores the previous canonical pointer', function () {
    $novel = pausedNovel();
    $first = canonicalChapter($novel, 1);
    $last = canonicalChapter($novel, 2);

    $result = app(DeleteChapterRangeAction::class)->execute($last['chapter'], '撤销最新正式章');
    $novel->refresh();

    expect($result)->toMatchArray(['chapters' => 1, 'canonical_chapters' => 1, 'state' => 'v2 → v1'])
        ->and($novel->canonical_state_version_id)->toBe($first['state']->getKey())
        ->and($novel->current_chapter_sequence)->toBe(1)
        ->and(StoryStateVersion::query()->find($last['state']->getKey()))->toBeNull()
        ->and(app(StoryStateRebuilder::class)->rebuild($novel)->matches())->toBeTrue();

    $this->artisan('story:rebuild-state', ['novel' => $novel->getKey(), '--dry-run' => true])
        ->expectsOutputToContain('校验通过')
        ->assertSuccessful();
});

test('it deletes a middle chapter and every later chapter', function () {
    $novel = pausedNovel();
    $first = canonicalChapter($novel, 1);
    $second = canonicalChapter($novel, 2);
    $third = canonicalChapter($novel, 3);

    $result = app(DeleteChapterRangeAction::class)->execute($second['chapter'], '从剧情分叉点重写');

    expect($result['chapters'])->toBe(2)
        ->and(Chapter::query()->pluck('id')->all())->toBe([$first['chapter']->getKey()])
        ->and(StoryStateVersion::query()->find($second['state']->getKey()))->toBeNull()
        ->and(StoryStateVersion::query()->find($third['state']->getKey()))->toBeNull()
        ->and($novel->fresh()->canonical_state_version_id)->toBe($first['state']->getKey())
        ->and($novel->fresh()->current_chapter_sequence)->toBe(1);
});

test('it rejects deletion while any novel run is queued or running', function (RunStatus $status) {
    $novel = pausedNovel();
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1]);
    GenerationRun::factory()->for($novel)->for($chapter)->create(['status' => $status]);

    expect(fn () => app(DeleteChapterRangeAction::class)->execute($chapter, '存在运行任务'))
        ->toThrow(ValidationException::class, '排队中或运行中')
        ->and($chapter->fresh())->not->toBeNull();
})->with([RunStatus::Queued, RunStatus::Running]);

test('it removes tail introduced entities facts events memories and usage while retaining foreshadowing definitions', function () {
    $foreshadowing = Foreshadowing::factory()->create(['status' => ForeshadowingStatus::Idea]);
    $novel = $foreshadowing->novel;
    $novel->update(['status' => NovelStatus::Paused]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $canonical = canonicalChapter($novel, 1);
    $chapter = $canonical['chapter'];
    $later = canonicalChapter($novel, 2)['chapter'];
    $character = Character::factory()->for($novel)->create(['source_chapter_id' => $chapter->getKey()]);
    $world = WorldEntity::factory()->for($novel)->create(['source_chapter_id' => $chapter->getKey()]);
    $event = StoryEvent::factory()->for($novel)->for($chapter)->create([
        'event_type' => EventType::CharacterMoved,
        'subject_type' => 'character',
        'subject_id' => (string) $character->getKey(),
        'state_version' => 1,
    ]);
    $laterEvent = StoryEvent::factory()->for($novel)->for($later)->create([
        'event_type' => EventType::CharacterLearned,
        'subject_type' => 'character',
        'subject_id' => (string) $character->getKey(),
        'payload' => ['knowledge' => '尾部秘密'],
        'state_version' => 2,
    ]);
    $fact = Fact::factory()->for($novel)->create([
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
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'status' => RunStatus::Succeeded,
    ]);
    $usage = UsageRecord::factory()->create([
        'generation_run_id' => $run->getKey(),
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
    ]);
    DB::table('foreshadowings')->where('id', $foreshadowing->getKey())->update([
        'status' => ForeshadowingStatus::Planted->value,
        'setup_chapter_id' => $chapter->getKey(),
        'reinforce_count' => 1,
    ]);

    app(DeleteChapterRangeAction::class)->execute($chapter, '删除包含错误设定的尾部');

    expect($character->fresh())->toBeNull()
        ->and($world->fresh())->toBeNull()
        ->and($event->fresh())->toBeNull()
        ->and($laterEvent->fresh())->toBeNull()
        ->and($fact->fresh())->toBeNull()
        ->and($memory->fresh())->toBeNull()
        ->and($usage->fresh())->toBeNull()
        ->and($foreshadowing->fresh())->not->toBeNull()
        ->and($foreshadowing->fresh()->status)->toBe(ForeshadowingStatus::Idea)
        ->and($foreshadowing->fresh()->setup_chapter_id)->toBeNull()
        ->and($foreshadowing->fresh()->reinforce_count)->toBe(0);
});

test('it rebuilds arc and milestone progress from the remaining active events', function () {
    $novel = pausedNovel();
    $canonical = canonicalChapter($novel, 1);
    $chapter = $canonical['chapter'];
    $plan = ChapterPlan::factory()->for($chapter)->create();
    $outlineArc = $plan->primaryOutlineArc;
    $beat = $plan->primaryOutlineBeat;
    $arc = $novel->storyArcs()->where('source_outline_arc_id', $outlineArc->getKey())->firstOrFail();
    StoryEvent::factory()->for($novel)->for($chapter)->create([
        'event_type' => EventType::StoryArcBeatCompleted,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $arc->getKey(),
        'payload' => ['beat_key' => $beat->beat_key],
        'state_version' => 1,
        'novel_outline_id' => $plan->novel_outline_id,
        'novel_outline_arc_id' => $outlineArc->getKey(),
        'novel_outline_beat_id' => $beat->getKey(),
        'novel_outline_milestone_id' => null,
    ]);
    app(StoryArcProgressProjector::class)->refreshNovel($novel);
    expect((float) $arc->fresh()->progress)->toBeGreaterThan(0.0);

    app(DeleteChapterRangeAction::class)->execute($chapter, '重置正式大纲进度');
    $target = app(OutlineProgressResolver::class)->resolve($novel->fresh());

    expect((float) $arc->fresh()->progress)->toBe(0.0)
        ->and($target)->not->toBeNull()
        ->and($target->outlineBeatId)->toBe($beat->getKey())
        ->and($target->outlineMilestoneId)->toBe($beat->milestones()->orderBy('sequence')->firstOrFail()->getKey());
});

test('it rolls back every deletion when post deletion validation fails', function () {
    $novel = pausedNovel();
    $canonical = canonicalChapter($novel, 1);
    $rebuilder = Mockery::mock(StoryStateRebuilder::class);
    $rebuilder->shouldReceive('rebuild')->once()->andThrow(new RuntimeException('forced rebuild failure'));
    app()->instance(StoryStateRebuilder::class, $rebuilder);

    expect(fn () => app(DeleteChapterRangeAction::class)->execute($canonical['chapter'], '验证事务回滚'))
        ->toThrow(RuntimeException::class, 'forced rebuild failure')
        ->and($canonical['chapter']->fresh())->not->toBeNull()
        ->and($canonical['state']->fresh())->not->toBeNull()
        ->and($novel->fresh()->canonical_state_version_id)->toBe($canonical['state']->getKey());
});

test('repeating deletion with the same stale chapter is idempotent', function () {
    $novel = pausedNovel();
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1]);
    $action = app(DeleteChapterRangeAction::class);

    expect($action->execute($chapter, '第一次删除')['status'])->toBe('deleted')
        ->and($action->execute($chapter, '重复投递')['status'])->toBe('already_deleted');
});

test('queued post commit jobs safely stop after their chapter or memory target was deleted', function () {
    $summaries = Mockery::mock(CanonicalChapterSummaryService::class);
    $summaries->shouldNotReceive('generate');
    (new GenerateCanonicalChapterSummaryJob(999_001, 999_002))->handle($summaries);

    $memories = Mockery::mock(MemoryUpdater::class);
    $memories->shouldNotReceive('update');
    (new UpdateMemoryJob(999_001))->handle($memories);

    $next = Mockery::mock(CheckNextAction::class);
    $next->shouldNotReceive('handle');
    (new ContinueAutoGenerationJob(999_001, 999_002, 999_003))->handle($next);

    $embedder = Mockery::mock(MemoryEmbedder::class);
    $embedder->shouldNotReceive('embed');
    (new GenerateEmbeddingJob(999_004))->handle($embedder);

    expect(true)->toBeTrue();
});

function pausedNovel(): Novel
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Paused]);
    app(InitializeNovelStateAction::class)->handle($novel);

    return $novel->fresh();
}

/** @return array{chapter: Chapter, state: StoryStateVersion} */
function canonicalChapter(Novel $novel, int $sequence): array
{
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => $sequence,
        'status' => ChapterStatus::Canonical,
    ]);
    $previous = $novel->fresh()->canonicalStateVersion;
    $version = (int) $novel->storyStateVersions()->max('version') + 1;
    $state = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
        'version' => $version,
        'state' => $previous->state,
        'checksum' => $previous->checksum,
    ]);
    $novel->update([
        'canonical_state_version_id' => $state->getKey(),
        'current_chapter_sequence' => $sequence,
    ]);

    return ['chapter' => $chapter, 'state' => $state];
}
