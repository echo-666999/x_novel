<?php

use App\Actions\Chapters\RestartChapterFromOutlineAction;
use App\Actions\Novels\ApplyNovelOutlineRevisionAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Enums\StoryArcStatus;
use App\Enums\StoryEventStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\Pages\ManageNovelOutline;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\Review;
use App\Models\Scene;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\Volume;
use App\Services\NovelOutlineChecksum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function revisionOutlineContent(): array
{
    return [
        'title' => '学院成长大纲',
        'summary' => '主角完成学院阶段成长。',
        'must_include' => ['完成训练'],
        'must_not_include' => ['提前毕业'],
        'baseline_completions' => [],
        'volumes' => [[
            'key' => 'volume-academy', 'sequence' => 1, 'title' => '学院篇',
            'goal' => '完成基础成长', 'climax' => '通过最终试炼', 'target_words' => 100000,
            'arcs' => [[
                'key' => 'arc-growth', 'sequence' => 1, 'type' => 'main', 'title' => '成长主线',
                'goal' => '掌握核心能力', 'stakes' => '失败将被逐出学院',
                'completion_conditions' => ['主角通过最终试炼'],
                'beats' => [[
                    'key' => 'beat-entry', 'sequence' => 1, 'title' => '进入学院',
                    'summary' => '主角进入学院并确认目标。',
                    'chapter_budget' => ['min' => 1, 'max' => 2],
                    'acceptance_criteria' => ['主角正式进入学院'],
                    'must_include' => ['学院入口'], 'must_not_include' => ['最终试炼结果'],
                    'character_candidates' => [], 'world_entity_candidates' => [],
                ], [
                    'key' => 'beat-training', 'sequence' => 2, 'title' => '基础训练',
                    'summary' => '主角开始基础训练。',
                    'chapter_budget' => ['min' => 1, 'max' => 3],
                    'acceptance_criteria' => ['主角完成第一次训练'],
                    'must_include' => ['训练'], 'must_not_include' => ['毕业'],
                    'character_candidates' => [], 'world_entity_candidates' => [],
                ]],
            ]],
        ]],
    ];
}

/** @return array{novel: Novel, outline: NovelOutline, volume: Volume, arc: StoryArc} */
function revisionOutlineFixture(): array
{
    $content = revisionOutlineContent();
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => 0,
    ]);
    $outline = NovelOutline::factory()->for($novel)->create([
        'version' => 1,
        'status' => NovelOutlineStatus::Current,
        'content' => $content,
        'checksum' => app(NovelOutlineChecksum::class)->for($content),
        'applied_at' => now(),
    ]);
    $novel->update(['current_outline_id' => $outline->getKey()]);
    $volume = Volume::factory()->for($novel)->create([
        'outline_key' => 'volume-academy',
        'sequence' => 1,
        'title' => '学院篇',
        'goal' => '完成基础成长',
        'climax' => '通过最终试炼',
        'target_words' => 100000,
        'status' => VolumeStatus::Active,
    ]);
    $arc = StoryArc::factory()->forVolume($volume)->create([
        'outline_key' => 'arc-growth',
        'sequence' => 1,
        'title' => '成长主线',
        'goal' => '掌握核心能力',
        'stakes' => '失败将被逐出学院',
        'beats' => $content['volumes'][0]['arcs'][0]['beats'],
        'completion_conditions' => ['主角通过最终试炼'],
        'status' => StoryArcStatus::Active,
    ]);

    return compact('novel', 'outline', 'volume', 'arc');
}

function protectCompletedEntryBeat(array $fixture): Chapter
{
    $chapter = Chapter::factory()->for($fixture['novel'])->for($fixture['volume'])->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
    ]);
    $fixture['novel']->update(['current_chapter_sequence' => 1]);
    StoryEvent::factory()->create([
        'novel_id' => $fixture['novel']->getKey(),
        'chapter_id' => $chapter->getKey(),
        'event_type' => EventType::StoryArcBeatCompleted,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $fixture['arc']->getKey(),
        'payload' => ['beat_key' => 'beat-entry'],
        'status' => StoryEventStatus::Active,
    ]);

    return $chapter;
}

test('a revision changes a future beat and atomically adopts one new current outline', function () {
    $fixture = revisionOutlineFixture();
    protectCompletedEntryBeat($fixture);
    $content = revisionOutlineContent();
    $content['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '主角在失败和复盘后完成第一次训练。';

    $revision = app(ApplyNovelOutlineRevisionAction::class)->handle(
        $fixture['novel'],
        $content,
        $fixture['outline']->getKey(),
        $fixture['outline']->checksum,
    );

    expect($revision->version)->toBe(2)
        ->and($revision->status)->toBe(NovelOutlineStatus::Current)
        ->and($revision->based_on_outline_id)->toBe($fixture['outline']->getKey())
        ->and($fixture['outline']->fresh()->status)->toBe(NovelOutlineStatus::Superseded)
        ->and($fixture['novel']->fresh()->current_outline_id)->toBe($revision->getKey())
        ->and($fixture['arc']->fresh()->beats[1]['summary'])->toBe('主角在失败和复盘后完成第一次训练。')
        ->and($fixture['novel']->storyEvents()->count())->toBe(1);
});

test('a revision updates unreferenced projections and creates future volume and arc projections', function () {
    $fixture = revisionOutlineFixture();
    $content = revisionOutlineContent();
    $content['volumes'][0]['title'] = '学院修订篇';
    $content['volumes'][0]['arcs'][0]['goal'] = '掌握修订后的核心能力';
    $content['volumes'][] = [
        'key' => 'volume-frontier', 'sequence' => 2, 'title' => '边境篇',
        'goal' => '前往边境', 'climax' => '守住边城', 'target_words' => 120000,
        'arcs' => [[
            'key' => 'arc-frontier', 'sequence' => 1, 'type' => 'main', 'title' => '边境主线',
            'goal' => '查清边境异变', 'stakes' => '边城陷落', 'completion_conditions' => ['守住边城'],
            'beats' => [[
                'key' => 'beat-arrival', 'sequence' => 1, 'title' => '抵达边境', 'summary' => '主角抵达边城。',
                'chapter_budget' => ['min' => 1, 'max' => 2], 'acceptance_criteria' => ['主角进入边城'],
                'must_include' => ['边城现状'], 'must_not_include' => ['立即解决异变'],
                'character_candidates' => [], 'world_entity_candidates' => [],
            ]],
        ]],
    ];

    app(ApplyNovelOutlineRevisionAction::class)->handle(
        $fixture['novel'], $content, $fixture['outline']->getKey(), $fixture['outline']->checksum,
    );

    $futureVolume = $fixture['novel']->volumes()->where('outline_key', 'volume-frontier')->sole();
    $futureArc = $fixture['novel']->storyArcs()->where('outline_key', 'arc-frontier')->sole();
    expect($fixture['volume']->fresh()->title)->toBe('学院修订篇')
        ->and($fixture['arc']->fresh()->goal)->toBe('掌握修订后的核心能力')
        ->and($futureVolume->sequence)->toBe(2)
        ->and($futureVolume->status)->toBe(VolumeStatus::Planned)
        ->and($futureArc->volume_id)->toBe($futureVolume->getKey())
        ->and($futureArc->status)->toBe(StoryArcStatus::Planned);
});

test('a revision rejects changing the key or meaning of a completed beat', function (string $change) {
    $fixture = revisionOutlineFixture();
    protectCompletedEntryBeat($fixture);
    $content = revisionOutlineContent();
    if ($change === 'key') {
        $content['volumes'][0]['arcs'][0]['beats'][0]['key'] = 'beat-entry-renamed';
    } else {
        $content['volumes'][0]['arcs'][0]['beats'][0]['summary'] = '改写已经正式发生的节点。';
    }

    expect(fn () => app(ApplyNovelOutlineRevisionAction::class)->handle(
        $fixture['novel'], $content, $fixture['outline']->getKey(), $fixture['outline']->checksum,
    ))->toThrow(ValidationException::class, '不能修改');

    expect($fixture['novel']->outlines()->count())->toBe(1)
        ->and($fixture['novel']->fresh()->current_outline_id)->toBe($fixture['outline']->getKey());
})->with(['key', 'semantic']);

test('a revision rejects changing a beat referenced by a canonical chapter plan without a completion event', function () {
    $fixture = revisionOutlineFixture();
    $chapter = Chapter::factory()->for($fixture['novel'])->for($fixture['volume'])->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
    ]);
    ChapterPlan::factory()->for($chapter)->create([
        'novel_outline_id' => $fixture['outline']->getKey(),
        'status' => PlanStatus::Ready,
        'arc_contributions' => [[
            'role' => 'primary', 'arc_id' => $fixture['arc']->getKey(), 'beat_key' => 'beat-training',
            'beat_index' => 2, 'target_scene_sequence' => 1, 'acceptance_criteria' => '主角完成第一次训练',
        ]],
    ]);
    $content = revisionOutlineContent();
    $content['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '试图改写正式章节引用的节点。';

    expect(fn () => app(ApplyNovelOutlineRevisionAction::class)->handle(
        $fixture['novel'], $content, $fixture['outline']->getKey(), $fixture['outline']->checksum,
    ))->toThrow(ValidationException::class, '不能修改');
});

test('a revision rejects a stale expected outline checksum', function () {
    $fixture = revisionOutlineFixture();
    $content = revisionOutlineContent();
    $content['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '修订后的未来节点。';

    expect(fn () => app(ApplyNovelOutlineRevisionAction::class)->handle(
        $fixture['novel'], $content, $fixture['outline']->getKey(), hash('sha256', 'stale'),
    ))->toThrow(ValidationException::class, 'Current Outline 已变化');
});

test('a revision rejects any queued or running generation run', function (RunStatus $status) {
    $fixture = revisionOutlineFixture();
    GenerationRun::factory()->for($fixture['novel'])->create(['status' => $status]);
    $content = revisionOutlineContent();
    $content['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '修订后的未来节点。';

    expect(fn () => app(ApplyNovelOutlineRevisionAction::class)->handle(
        $fixture['novel'], $content, $fixture['outline']->getKey(), $fixture['outline']->checksum,
    ))->toThrow(ValidationException::class, 'Generation Run');
})->with([RunStatus::Queued, RunStatus::Running]);

test('applying a revision for the next chapter leaves the current chapter plan frozen', function () {
    $fixture = revisionOutlineFixture();
    $chapter = Chapter::factory()->for($fixture['novel'])->for($fixture['volume'])->create([
        'sequence' => 1,
        'status' => ChapterStatus::Generating,
    ]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'novel_outline_id' => $fixture['outline']->getKey(),
        'status' => PlanStatus::Ready,
        'arc_contributions' => [[
            'role' => 'primary', 'arc_id' => $fixture['arc']->getKey(), 'beat_key' => 'beat-entry',
            'beat_index' => 1, 'target_scene_sequence' => 1, 'acceptance_criteria' => '主角正式进入学院',
        ]],
    ]);
    $before = $plan->getRawOriginal();
    $content = revisionOutlineContent();
    $content['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '只影响下一章之后的未来节点。';

    app(ApplyNovelOutlineRevisionAction::class)->handle(
        $fixture['novel'], $content, $fixture['outline']->getKey(), $fixture['outline']->checksum,
    );

    expect($plan->fresh()->novel_outline_id)->toBe($fixture['outline']->getKey())
        ->and($plan->fresh()->getRawOriginal())->toMatchArray($before);
});

test('restarting the current non canonical chapter preserves its historical source chain', function () {
    Queue::fake();
    Cache::flush();
    $fixture = revisionOutlineFixture();
    $chapter = Chapter::factory()->for($fixture['novel'])->for($fixture['volume'])->create([
        'sequence' => 1,
        'status' => ChapterStatus::Review,
    ]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'novel_outline_id' => $fixture['outline']->getKey(),
        'status' => PlanStatus::Ready,
    ]);
    $run = GenerationRun::factory()->for($fixture['novel'])->for($chapter)->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run, 'generationRun')->create([
        'type' => ArtifactType::ChapterDraft,
    ]);
    Review::factory()->for($run, 'generationRun')->create();
    UsageRecord::factory()->for($run, 'generationRun')->create([
        'novel_id' => $fixture['novel']->getKey(),
        'chapter_id' => $chapter->getKey(),
    ]);
    $scene = Scene::factory()->for($chapter)->create([
        'sequence' => 1,
        'status' => SceneStatus::Accepted,
        'current_artifact_id' => $artifact->getKey(),
    ]);
    $content = revisionOutlineContent();
    $content['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '当前修订版本的训练节点。';
    $revision = app(ApplyNovelOutlineRevisionAction::class)->handle(
        $fixture['novel'], $content, $fixture['outline']->getKey(), $fixture['outline']->checksum,
    );
    $counts = [
        'runs' => $chapter->generationRuns()->count(),
        'artifacts' => GenerationArtifact::query()->count(),
        'reviews' => Review::query()->count(),
        'usage' => UsageRecord::query()->count(),
    ];

    $result = app(RestartChapterFromOutlineAction::class)->handle(
        $fixture['novel'], $revision->getKey(), $revision->checksum,
    );
    $replayed = app(RestartChapterFromOutlineAction::class)->handle(
        $fixture['novel']->fresh(), $revision->getKey(), $revision->checksum,
    );

    expect($result['status'])->toBe('applied')
        ->and($result['dispatched'])->toBeTrue()
        ->and($replayed['status'])->toBe('already_applied')
        ->and($replayed['dispatched'])->toBeFalse()
        ->and($plan->fresh()->status)->toBe(PlanStatus::Superseded)
        ->and($chapter->fresh()->status)->toBe(ChapterStatus::Void)
        ->and($scene->fresh()->status)->toBe(SceneStatus::Planned)
        ->and($scene->fresh()->current_artifact_id)->toBeNull()
        ->and($chapter->generationRuns()->count())->toBe($counts['runs'])
        ->and(GenerationArtifact::query()->count())->toBe($counts['artifacts'])
        ->and(Review::query()->count())->toBe($counts['reviews'])
        ->and(UsageRecord::query()->count())->toBe($counts['usage']);
    Queue::assertPushed(PlanChapterJob::class, 1);
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $chapter->getKey() && $job->regenerate);
});

test('replaying the same revision does not create another current outline', function () {
    $fixture = revisionOutlineFixture();
    $content = revisionOutlineContent();
    $content['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '幂等修订内容。';
    $action = app(ApplyNovelOutlineRevisionAction::class);

    $first = $action->handle($fixture['novel'], $content, $fixture['outline']->getKey(), $fixture['outline']->checksum);
    $second = $action->handle($fixture['novel']->fresh(), $content, $fixture['outline']->getKey(), $fixture['outline']->checksum);

    expect($second->is($first))->toBeTrue()
        ->and($fixture['novel']->outlines()->count())->toBe(2)
        ->and($fixture['novel']->outlines()->where('status', NovelOutlineStatus::Current)->count())->toBe(1);
});

test('a previous outline can only be restored by creating another revision version', function () {
    $fixture = revisionOutlineFixture();
    $changed = revisionOutlineContent();
    $changed['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '临时采用的未来训练安排。';
    $action = app(ApplyNovelOutlineRevisionAction::class);
    $second = $action->handle(
        $fixture['novel'], $changed, $fixture['outline']->getKey(), $fixture['outline']->checksum,
    );

    $restored = $action->handle(
        $fixture['novel']->fresh(), revisionOutlineContent(), $second->getKey(), $second->checksum,
    );

    expect($restored->version)->toBe(3)
        ->and($restored->based_on_outline_id)->toBe($second->getKey())
        ->and($restored->checksum)->toBe($fixture['outline']->checksum)
        ->and($restored->getKey())->not->toBe($fixture['outline']->getKey())
        ->and($fixture['outline']->fresh()->status)->toBe(NovelOutlineStatus::Superseded)
        ->and($second->fresh()->status)->toBe(NovelOutlineStatus::Superseded);
});

test('a revision cannot rewrite confirmed baseline completion records', function () {
    $content = revisionOutlineContent();
    $content['baseline_completions'] = [[
        'beat_key' => 'beat-entry',
        'chapter_ids' => [1],
        'evidence' => '主角正式进入学院。',
        'reason' => '迁移正式历史',
        'confirmed_by' => 'admin',
        'confirmed_at' => '2026-09-22T10:00:00+08:00',
    ]];
    $fixture = revisionOutlineFixture();
    DB::table('novel_outlines')->where('id', $fixture['outline']->getKey())->update([
        'content' => json_encode($content),
        'checksum' => app(NovelOutlineChecksum::class)->for($content),
    ]);
    $fixture['outline']->refresh();
    $changed = $content;
    $changed['baseline_completions'] = [];

    expect(fn () => app(ApplyNovelOutlineRevisionAction::class)->handle(
        $fixture['novel'], $changed, $fixture['outline']->getKey(), $fixture['outline']->checksum,
    ))->toThrow(ValidationException::class, 'Baseline Completion');
});

test('outline workspace exposes explicit next chapter and current chapter revision flows', function () {
    Queue::fake();
    Cache::flush();
    $this->actingAs(User::factory()->create());
    $fixture = revisionOutlineFixture();
    $chapter = Chapter::factory()->for($fixture['novel'])->for($fixture['volume'])->create([
        'sequence' => 1,
        'status' => ChapterStatus::Generating,
    ]);
    ChapterPlan::factory()->for($chapter)->create([
        'novel_outline_id' => $fixture['outline']->getKey(),
        'status' => PlanStatus::Ready,
    ]);
    $content = revisionOutlineContent();
    $content['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '由后台明确应用的未来节点修订。';

    $component = Livewire::test(ManageNovelOutline::class, ['record' => $fixture['novel']->getRouteKey()])
        ->assertActionVisible('applyOutlineRevision')
        ->assertActionHidden('restartChapterFromOutline')
        ->callAction('applyOutlineRevision', [
            ...$content,
            'expected_current_outline_id' => $fixture['outline']->getKey(),
            'expected_current_outline_checksum' => $fixture['outline']->checksum,
        ])
        ->assertNotified('新 Outline Version 已从下一章生效')
        ->assertActionVisible('restartChapterFromOutline')
        ->callAction('restartChapterFromOutline')
        ->assertNotified('当前章已进入重新规划');

    $component->assertHasNoActionErrors();
    expect($chapter->fresh()->status)->toBe(ChapterStatus::Void)
        ->and($chapter->latestPlan->status)->toBe(PlanStatus::Superseded);
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $chapter->getKey() && $job->regenerate);
});
