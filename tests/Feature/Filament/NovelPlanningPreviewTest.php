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
use App\Models\User;
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
        'status' => ForeshadowingStatus::Due,
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
        'due_foreshadowings' => [$foreshadowing->getKey()],
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
        ->assertSeeTextInOrder([
            'Planning Preview',
            '雾海长明 · 第 12 章 · 旧港夜航',
            '本章为什么存在',
            'Chapter Function',
            '迫使主角离开安全区',
            'Arc Contribution',
            '推进失踪船队主线',
            'Reader Promise',
            '确认钟声来自沉没灯塔',
            'Scene 流程',
            'Scene 1',
            '取得出港许可',
            'Scene 2',
            '穿过封锁线',
            '约束与伏笔',
            '潮汐钟声',
            'Required Facts',
            'can_swim',
            'Forbidden Conflicts',
            '林舟不得突然学会游泳',
            'Plan Findings',
        ])
        ->assertSee('Valid');
});

test('a chapter with no plan shows the preview empty state', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();

    Livewire::test(ViewNovelPlanningPreview::class, [
        'record' => $novel->getRouteKey(),
        'chapter' => $chapter->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee('尚未建立 Chapter Plan')
        ->assertSee('返回章节列表建立完整 Plan 后');
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
