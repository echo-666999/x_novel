<?php

use App\Enums\FactHardness;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\NovelOutlineStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelChapters;
use App\Filament\Resources\Novels\Pages\ViewNovelPlanningPreview;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\StoryArc;
use App\Models\User;
use App\Models\Volume;
use App\Services\NovelOutlineChecksum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('planning preview shows the complete chapter plan in reading order', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 12,
        'title' => '旧港夜航',
    ]);
    $pov = Character::factory()->for($novel)->create(['name' => '林舟']);
    $fact = Fact::factory()->for($novel)->create([
        'subject_id' => $pov->getKey(),
        'predicate' => 'can_swim',
        'value' => ['value' => false],
        'hardness' => FactHardness::Hard,
        'locked' => true,
    ]);
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'title' => '潮汐钟声',
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 10,
        'due_to_chapter' => 14,
    ]);
    ChapterPlan::factory()->for($chapter)->create([
        'chapter_function' => '迫使主角离开安全区',
        'arc_contribution' => '推进失踪船队主线',
        'reader_promise' => '确认钟声来自沉没灯塔',
        'pov_character_id' => $pov->getKey(),
        'time_anchor' => '午夜',
        'required_facts' => [$fact->getKey()],
        'forbidden_conflicts' => ['林舟不得突然学会游泳'],
        'must_not_reveal' => ['幕后主使身份'],
        'foreshadowing_actions' => [[
            'foreshadowing_id' => $foreshadowing->getKey(),
            'action' => 'reinforce',
            'target_scene_sequence' => 1,
            'acceptance_criteria' => '钟声再次出现并直接迫使主角改变出港方式。',
            'reason' => null,
        ]],
        'scene_plans' => [
            [
                'goal' => '取得出港许可',
                'conflict' => '港务官拒绝放行',
                'turn' => '潮汐钟提前响起',
                'outcome' => '林舟被迫偷船',
                'location' => '旧港',
            ],
            [
                'goal' => '穿过封锁线',
                'conflict' => '巡逻船追击',
                'turn' => '雾中出现灯塔轮廓',
                'outcome' => '主角进入禁航区',
            ],
        ],
    ]);

    Livewire::test(ViewNovelPlanningPreview::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertActionVisible('backToChapter')
        ->assertActionHasUrl('backToChapter', NovelResource::getUrl('chapters', [
            'record' => $novel,
        ]))
        ->assertSeeTextInOrder([
            '规划预览',
            '雾海长明 · 第 12 章 · 旧港夜航',
            '本章为什么存在',
            '章节功能',
            '迫使主角离开安全区',
            '故事线贡献',
            '推进失踪船队主线',
            '读者承诺',
            '确认钟声来自沉没灯塔',
            '场景流程',
            '场景 1',
            '取得出港许可',
            '场景 2',
            '穿过封锁线',
            '约束与伏笔',
            '潮汐钟声',
            '必需事实',
            'can_swim',
            '禁止冲突',
            '林舟不得突然学会游泳',
            '计划检查结果',
        ])
        ->assertSee('有效');
});

test('a chapter with no plan shows the preview empty state', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();

    Livewire::test(ViewNovelPlanningPreview::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee('尚未建立章节计划')
        ->assertSee('返回章节列表建立完整计划后');
});

test('planning preview shows the frozen current outline target and constraints', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['outline_key' => 'volume-one', 'status' => 'active']);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create();
    $arc = StoryArc::factory()->for($novel)->forVolume($volume)->create([
        'outline_key' => 'main-arc',
        'status' => 'active',
    ]);
    $content = [
        'title' => '预览大纲', 'summary' => '预览当前节点。', 'must_include' => [], 'must_not_include' => [],
        'baseline_completions' => [],
        'volumes' => [[
            'key' => 'volume-one', 'sequence' => 1, 'title' => '第一卷', 'goal' => '启程', 'climax' => '离城', 'target_words' => 100000,
            'arcs' => [[
                'key' => 'main-arc', 'sequence' => 1, 'type' => 'main', 'title' => '启程主线', 'goal' => '离开旧城',
                'stakes' => '被困旧城', 'completion_conditions' => ['离开旧城'],
                'beats' => [[
                    'key' => 'beat-map', 'sequence' => 1, 'title' => '取得地图', 'summary' => '取得可靠地图。',
                    'chapter_budget' => ['min' => 1, 'max' => 2], 'acceptance_criteria' => ['主角取得真实地图'],
                    'must_include' => ['地图来源可验证'], 'must_not_include' => ['直接穿过城门'],
                    'character_candidates' => [], 'world_entity_candidates' => [],
                ]],
            ]],
        ]],
    ];
    $outline = NovelOutline::factory()->for($novel)->create([
        'status' => NovelOutlineStatus::Current,
        'content' => $content,
        'checksum' => app(NovelOutlineChecksum::class)->for($content),
        'applied_at' => now(),
    ]);
    $novel->update(['current_outline_id' => $outline->getKey()]);
    $pov = Character::factory()->for($novel)->create();
    ChapterPlan::factory()->for($chapter)->create([
        'novel_outline_id' => $outline->getKey(),
        'pov_character_id' => $pov->getKey(),
        'arc_contributions' => [[
            'role' => 'primary', 'arc_id' => $arc->getKey(), 'beat_key' => 'beat-map', 'beat_index' => 1,
            'target_scene_sequence' => 1, 'acceptance_criteria' => '主角取得真实地图',
        ]],
        'must_reveal' => ['地图来源可验证'],
        'must_not_reveal' => ['直接穿过城门'],
    ]);

    Livewire::test(ViewNovelPlanningPreview::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertSeeTextInOrder([
            'Current Outline Target', 'Outline Version', 'v1', 'Primary Beat', '取得地图',
            'Beat Key', 'beat-map', '章节预算', '1–2 章', '验收条件', '主角取得真实地图',
            '必须包含', '地图来源可验证', '禁止包含', '直接穿过城门',
        ]);
});

test('planning preview rejects a chapter from another novel', function () {
    $novel = Novel::factory()->create();
    $foreignChapter = Chapter::factory()->create();

    $this->get(NovelResource::getUrl('planning-preview', [
        'record' => $novel,
        'chapter' => $foreignChapter,
    ]))->assertNotFound();
});

test('the chapter list links plans to their planning preview', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $pov = Character::factory()->for($novel)->create();
    ChapterPlan::factory()->for($chapter)->create(['pov_character_id' => $pov->getKey()]);

    Livewire::test(ManageNovelChapters::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionVisible('previewPlan', $chapter)
        ->assertTableActionHasUrl(
            'previewPlan',
            NovelResource::getUrl('planning-preview', [
                'record' => $novel,
                'chapter' => $chapter,
            ]),
            $chapter,
        );
});
