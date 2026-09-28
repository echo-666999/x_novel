<?php

use App\Enums\FactHardness;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelChapters;
use App\Filament\Resources\Novels\Pages\ViewNovelPlanningPreview;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\User;
use App\Models\Volume;
use App\Services\OutlineProgressResolver;
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
    $plan = ChapterPlan::factory()->for($chapter)->create([
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
    $target = app(OutlineProgressResolver::class)->resolve($novel->fresh());
    $plan->update(['arc_contributions' => [[
        'role' => 'primary', 'arc_id' => $target->arcId, 'beat_key' => $target->beat['key'],
        'beat_index' => $target->beat['sequence'], 'target_scene_sequence' => 1,
        'acceptance_criteria' => $target->milestone['acceptance_criteria'][0],
    ]]]);

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
    $volume = Volume::factory()->for($novel)->create(['status' => 'active']);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create();
    StoryArc::factory()->for($novel)->forVolume($volume)->create(['status' => 'active']);
    $pov = Character::factory()->for($novel)->create();
    $plan = ChapterPlan::factory()->for($chapter)->create(['pov_character_id' => $pov->getKey()]);
    $target = app(OutlineProgressResolver::class)->resolve($novel->fresh());
    $plan->update(['arc_contributions' => [[
        'role' => 'primary', 'arc_id' => $target->arcId, 'beat_key' => $target->beat['key'],
        'beat_index' => $target->beat['sequence'], 'target_scene_sequence' => 1,
        'acceptance_criteria' => $target->milestone['acceptance_criteria'][0],
    ]]]);

    Livewire::test(ViewNovelPlanningPreview::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertSeeTextInOrder([
            'Current Outline Target', 'Outline Version', 'v1', 'Primary Beat', 'Factory Beat',
            'Beat Key', 'factory-beat', '章节预算', '1–2 章', '验收条件', '完整父链存在。',
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
