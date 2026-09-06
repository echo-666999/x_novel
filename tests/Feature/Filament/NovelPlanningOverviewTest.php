<?php

use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewNovelPlanning;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the planning overview shows active arcs beneath their volumes', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $volume = Volume::factory()->for($novel)->create([
        'sequence' => 1,
        'title' => '孤城卷',
        'goal' => '守住被雾潮包围的孤城。',
        'status' => VolumeStatus::Active,
    ]);
    StoryArc::factory()->forVolume($volume)->create([
        'type' => StoryArcType::Main,
        'title' => '追查雾潮源头',
        'goal' => '找出雾潮反常的原因。',
        'stakes' => '孤城会在下一次潮汐中覆灭。',
        'beats' => ['发现旧港航海日志', '进入沉没灯塔'],
        'progress' => 0.4,
        'status' => StoryArcStatus::Active,
    ]);
    StoryArc::factory()->forVolume($volume)->create([
        'title' => '已经结束的支线',
        'status' => StoryArcStatus::Completed,
    ]);
    StoryArc::factory()->create([
        'title' => '其他小说的主线',
        'status' => StoryArcStatus::Active,
    ]);

    Livewire::test(ViewNovelPlanning::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSet('scope', 'active')
        ->assertSeeTextInOrder([
            '规划总览',
            '雾海长明',
            '当前推进',
            '第 1 卷 · 孤城卷',
            '追查雾潮源头',
            '40%',
            '关键节拍',
            '发现旧港航海日志',
            '进入沉没灯塔',
        ])
        ->assertDontSee('已经结束的支线')
        ->assertDontSee('其他小说的主线');
});

test('the planning overview switches between active completed and all scopes', function () {
    $novel = Novel::factory()->create();
    $activeVolume = Volume::factory()->for($novel)->create([
        'sequence' => 1,
        'title' => '当前卷',
        'status' => VolumeStatus::Active,
    ]);
    $completedVolume = Volume::factory()->for($novel)->create([
        'sequence' => 2,
        'title' => '已完成卷',
        'status' => VolumeStatus::Completed,
    ]);
    StoryArc::factory()->forVolume($activeVolume)->create([
        'title' => '当前主线',
        'status' => StoryArcStatus::Active,
    ]);
    StoryArc::factory()->forVolume($completedVolume)->create([
        'title' => '完成主线',
        'status' => StoryArcStatus::Completed,
    ]);

    Livewire::test(ViewNovelPlanning::class, ['record' => $novel->getRouteKey()])
        ->assertSee('当前主线')
        ->assertDontSee('完成主线')
        ->call('setScope', 'completed')
        ->assertSet('scope', 'completed')
        ->assertSee('完成主线')
        ->assertDontSee('当前主线')
        ->call('setScope', 'all')
        ->assertSet('scope', 'all')
        ->assertSee('当前主线')
        ->assertSee('完成主线');
});

test('the planning overview gives cross volume arcs their own group', function () {
    $novel = Novel::factory()->create();
    StoryArc::factory()->for($novel)->create([
        'volume_id' => null,
        'title' => '贯穿全书的归乡线',
        'status' => StoryArcStatus::Active,
    ]);

    Livewire::test(ViewNovelPlanning::class, ['record' => $novel->getRouteKey()])
        ->assertSeeTextInOrder([
            '跨卷 / 全书故事线',
            '贯穿全书的归乡线',
        ]);
});

test('the planning overview has an actionable empty state', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ViewNovelPlanning::class, ['record' => $novel->getRouteKey()])
        ->assertSee('当前范围没有规划内容')
        ->assertSee('可在“分卷”和“故事线”中维护小说的规划结构。');
});

test('the planning route is the workspace summary and volume management remains available', function () {
    $novel = Novel::factory()->create();

    $this->get(NovelResource::getUrl('planning', ['record' => $novel]))
        ->assertOk()
        ->assertSee('规划总览');

    $this->get(NovelResource::getUrl('volumes', ['record' => $novel]))
        ->assertOk()
        ->assertSee('分卷规划');
});
