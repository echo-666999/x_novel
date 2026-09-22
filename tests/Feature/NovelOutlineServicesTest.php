<?php

use App\Actions\Novels\CreateNovelOutlineVersionAction;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Enums\VolumeStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\Volume;
use App\Services\NovelOutlineChecksum;
use App\Services\NovelOutlineValidator;
use App\Services\OutlineContextBuilder;
use App\Services\OutlineDiffService;
use App\Services\OutlineProgressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function validOutlineContent(): array
{
    return [
        'title' => '学院成长大纲',
        'summary' => '主角完成学院阶段成长。',
        'must_include' => ['建立导师关系'],
        'must_not_include' => ['提前揭示终局'],
        'baseline_completions' => [],
        'volumes' => [[
            'key' => 'volume-academy',
            'sequence' => 1,
            'title' => '学院篇',
            'goal' => '完成基础成长',
            'climax' => '通过最终试炼',
            'target_words' => 100000,
            'arcs' => [[
                'key' => 'arc-growth',
                'sequence' => 1,
                'type' => 'main',
                'title' => '成长主线',
                'goal' => '掌握核心能力',
                'stakes' => '失败将被逐出学院',
                'completion_conditions' => ['主角通过最终试炼'],
                'beats' => [
                    [
                        'key' => 'beat-entry',
                        'sequence' => 1,
                        'title' => '进入学院',
                        'summary' => '主角进入学院并确认目标。',
                        'chapter_budget' => ['min' => 1, 'max' => 2],
                        'acceptance_criteria' => ['主角正式进入学院'],
                        'must_include' => ['学院入口'],
                        'must_not_include' => ['最终试炼结果'],
                        'character_candidates' => [],
                        'world_entity_candidates' => [],
                    ],
                    [
                        'key' => 'beat-training',
                        'sequence' => 2,
                        'title' => '基础训练',
                        'summary' => '主角开始基础训练。',
                        'chapter_budget' => ['min' => 1, 'max' => null],
                        'acceptance_criteria' => ['主角完成第一次训练'],
                        'must_include' => ['训练'],
                        'must_not_include' => ['毕业'],
                        'character_candidates' => [[
                            'candidate_key' => 'character-mentor',
                            'name' => '林教授',
                            'role' => '导师',
                            'motivation' => '培养继承人',
                            'profile' => [],
                            'personality' => [],
                            'abilities' => [],
                            'knowledge' => [],
                            'deduplication_basis' => '学院导师候选',
                            'possible_duplicate_character_ids' => [],
                            'introduction_reason' => '负责训练主角',
                            'target_scene_sequence' => 1,
                        ]],
                        'world_entity_candidates' => [[
                            'candidate_key' => 'wec-training-hall',
                            'type' => 'location',
                            'name' => '训练馆',
                            'description' => '学院基础训练场地',
                            'deduplication_basis' => '学院唯一基础训练馆',
                            'possible_duplicate_entity_ids' => [],
                            'introduction_reason' => '承载训练节点',
                            'target_scene_sequence' => 1,
                        ]],
                    ],
                ],
            ]],
        ]],
    ];
}

test('outline validator rejects duplicate keys sequence gaps reversed budgets empty criteria and candidate keys', function (Closure $mutate, string $message) {
    $content = validOutlineContent();
    $mutate($content);

    $result = app(NovelOutlineValidator::class)->validate($content);

    expect($result->isValid())->toBeFalse()
        ->and(implode(' ', $result->errors))->toContain($message);
})->with([
    'duplicate node key' => [function (array &$content): void {
        $content['volumes'][0]['arcs'][0]['beats'][1]['key'] = 'beat-entry';
    }, '重复'],
    'sequence gap' => [function (array &$content): void {
        $content['volumes'][0]['arcs'][0]['beats'][1]['sequence'] = 3;
    }, 'sequence'],
    'reversed budget' => [function (array &$content): void {
        $content['volumes'][0]['arcs'][0]['beats'][0]['chapter_budget'] = ['min' => 3, 'max' => 2];
    }, 'chapter_budget'],
    'empty acceptance criterion' => [function (array &$content): void {
        $content['volumes'][0]['arcs'][0]['beats'][0]['acceptance_criteria'] = [''];
    }, '空项'],
    'duplicate candidate key' => [function (array &$content): void {
        $content['volumes'][0]['arcs'][0]['beats'][1]['world_entity_candidates'][0]['candidate_key'] = 'character-mentor';
    }, 'Candidate key'],
]);

test('outline validator accepts a complete contract and permits an empty subplot beat list', function () {
    $content = validOutlineContent();
    $content['volumes'][0]['arcs'][] = [
        'key' => 'arc-friendship',
        'sequence' => 2,
        'type' => 'subplot',
        'title' => '友情支线',
        'goal' => '建立合作关系',
        'stakes' => '合作可能破裂',
        'completion_conditions' => [],
        'beats' => [],
    ];

    expect(app(NovelOutlineValidator::class)->validate($content)->isValid())->toBeTrue();
});

test('draft outline action creates immutable version with deterministic checksum without changing canonical state', function () {
    $novel = Novel::factory()->create();
    $content = validOutlineContent();
    $reordered = ['summary' => $content['summary'], 'title' => $content['title'], ...collect($content)->except(['summary', 'title'])->all()];

    $outline = app(CreateNovelOutlineVersionAction::class)->handle($novel, $content, NovelOutlineSource::Manual);

    expect($outline->version)->toBe(1)
        ->and($outline->status)->toBe(NovelOutlineStatus::Draft)
        ->and($outline->checksum)->toBe(app(NovelOutlineChecksum::class)->for($reordered))
        ->and($novel->fresh()->current_outline_id)->toBeNull()
        ->and($novel->storyEvents()->count())->toBe(0)
        ->and($novel->canonical_state_version_id)->toBeNull();

    expect(fn () => $outline->update(['content' => ['changed' => true]]))->toThrow(LogicException::class);
    $outline->refresh();
    $outline->update(['status' => NovelOutlineStatus::Superseded]);
    expect($outline->fresh()->status)->toBe(NovelOutlineStatus::Superseded);
});

test('outline progress selects earliest unfinished main beat and never returns a completed beat', function () {
    [$novel, $outline, $volume, $arc] = outlineProgressFixture();
    $initial = app(OutlineProgressResolver::class)->resolve($novel);
    expect($initial?->beat['key'])->toBe('beat-entry');

    StoryEvent::factory()->create([
        'novel_id' => $novel->getKey(),
        'event_type' => EventType::StoryArcBeatCompleted,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $arc->getKey(),
        'payload' => ['beat_key' => 'beat-entry'],
    ]);

    $next = app(OutlineProgressResolver::class)->resolve($novel->fresh());
    expect($next?->beat['key'])->toBe('beat-training')
        ->and($next?->canonicalCompletedBeatKeys)->toBe(['beat-entry']);
});

test('baseline completion changes only the old novel starting target and creates no event', function () {
    $content = validOutlineContent();
    $content['baseline_completions'] = [[
        'beat_key' => 'beat-entry',
        'chapter_ids' => [1],
        'evidence' => '他跨过学院正门。',
        'reason' => '迁移已有正式章节',
        'confirmed_by' => 'admin',
        'confirmed_at' => '2026-09-22T10:00:00+08:00',
    ]];
    [$novel] = outlineProgressFixture($content);
    $before = StoryEvent::query()->count();

    $target = app(OutlineProgressResolver::class)->resolve($novel);

    expect($target?->beat['key'])->toBe('beat-training')
        ->and($target?->baselineCompletedBeatKeys)->toBe(['beat-entry'])
        ->and(StoryEvent::query()->count())->toBe($before);
});

test('outline context freezes target contract and counts only canonical chapters using that beat', function () {
    [$novel, $outline, $volume, $arc] = outlineProgressFixture();
    $chapter = Chapter::factory()->for($novel)->for($volume)->create(['status' => ChapterStatus::Canonical]);
    ChapterPlan::factory()->for($chapter)->create([
        'novel_outline_id' => $outline->getKey(),
        'arc_contributions' => [[
            'role' => 'primary',
            'arc_id' => $arc->getKey(),
            'beat_key' => 'beat-entry',
            'beat_index' => 0,
        ]],
    ]);

    $context = app(OutlineContextBuilder::class)->build($novel->fresh());

    expect($context['novel_outline_id'])->toBe($outline->getKey())
        ->and($context['outline_checksum'])->toBe($outline->checksum)
        ->and($context['primary_arc_id'])->toBe($arc->getKey())
        ->and($context['primary_beat_key'])->toBe('beat-entry')
        ->and($context['chapters_used_for_current_beat'])->toBe(1)
        ->and($context['acceptance_criteria'])->toBe(['主角正式进入学院']);
});

test('outline diff reports additions deletions reordering and semantic modifications', function () {
    $before = validOutlineContent();
    $after = $before;
    $after['volumes'][0]['arcs'][0]['beats'][0]['sequence'] = 2;
    $after['volumes'][0]['arcs'][0]['beats'][1]['sequence'] = 1;
    $after['volumes'][0]['arcs'][0]['beats'][1]['summary'] = '修改后的训练说明。';
    array_shift($after['volumes'][0]['arcs'][0]['beats']);
    $after['volumes'][0]['arcs'][0]['beats'][] = [
        ...$before['volumes'][0]['arcs'][0]['beats'][0],
        'key' => 'beat-trial',
        'sequence' => 2,
    ];

    $diff = app(OutlineDiffService::class)->compare($before, $after);

    expect(collect($diff['added'])->pluck('key')->all())->toBe(['beat-trial'])
        ->and(collect($diff['removed'])->pluck('key')->all())->toBe(['beat-entry'])
        ->and(collect($diff['reordered'])->pluck('key')->all())->toContain('beat-training')
        ->and(collect($diff['modified'])->pluck('key')->all())->toContain('beat-training');
});

test('outline versions referenced by chapter plans cannot be deleted', function () {
    [$novel, $outline, $volume] = outlineProgressFixture();
    $chapter = Chapter::factory()->for($novel)->for($volume)->create();
    ChapterPlan::factory()->for($chapter)->create(['novel_outline_id' => $outline->getKey()]);

    expect(fn () => $outline->delete())->toThrow(LogicException::class);
});

/** @return array{Novel, NovelOutline, Volume, StoryArc} */
function outlineProgressFixture(?array $content = null): array
{
    $content ??= validOutlineContent();
    $novel = Novel::factory()->create();
    $outline = NovelOutline::factory()->for($novel)->create([
        'status' => NovelOutlineStatus::Current,
        'content' => $content,
        'checksum' => app(NovelOutlineChecksum::class)->for($content),
    ]);
    $novel->update(['current_outline_id' => $outline->getKey()]);
    $volume = Volume::factory()->for($novel)->create([
        'outline_key' => 'volume-academy',
        'sequence' => 1,
        'status' => VolumeStatus::Active,
    ]);
    $arc = StoryArc::factory()->forVolume($volume)->create([
        'outline_key' => 'arc-growth',
        'sequence' => 1,
        'type' => StoryArcType::Main,
        'status' => StoryArcStatus::Active,
        'beats' => $content['volumes'][0]['arcs'][0]['beats'],
    ]);

    return [$novel->fresh(), $outline, $volume, $arc];
}
