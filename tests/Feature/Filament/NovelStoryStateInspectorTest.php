<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewNovelStoryState;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Services\StoryStateRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the inspector defaults to the novels current state version', function () {
    $novel = Novel::factory()->create();
    $versionZero = app(InitializeNovelStateAction::class)->handle($novel);
    $versionOne = StoryStateVersion::factory()->for($novel)->create([
        'version' => 1,
        'chapter_id' => 12,
        'state' => [
            ...$versionZero->state,
            'characters' => ['hero' => ['name' => '沈澜', 'location' => '灯塔']],
        ],
        'checksum' => str_repeat('b', 64),
    ]);
    $novel->update(['canonical_state_version_id' => $versionOne->getKey()]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSet('selectedVersion', 1)
        ->assertSeeTextInOrder([
            'State Version 1',
            'Canonical Story State 只读快照',
            'Current',
            '查看版本',
            'v1 · Current',
            'v0',
            '来源章节',
            '#12',
            'Checksum',
        ])
        ->assertSee('沈澜')
        ->assertSee('灯塔');
});

test('the inspector switches between state version history', function () {
    $novel = Novel::factory()->create();
    $versionZero = app(InitializeNovelStateAction::class)->handle($novel);
    StoryStateVersion::factory()->for($novel)->create([
        'version' => 1,
        'state' => [...$versionZero->state, 'timeline' => ['current_day_index' => 2]],
        'checksum' => str_repeat('c', 64),
    ]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectVersion', 1)
        ->assertSet('selectedVersion', 1)
        ->call('selectDomain', 'timeline')
        ->assertSee('current_day_index')
        ->assertSee('2')
        ->call('selectVersion', 0)
        ->assertSet('selectedVersion', 0)
        ->assertSee('State Version 0')
        ->assertSee('初始化状态')
        ->assertDontSee('current_day_index');
});

test('the inspector never loads a state version from another novel', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $otherVersion = StoryStateVersion::factory()->create([
        'version' => 99,
        'state' => ['schema_version' => 1, 'characters' => ['secret' => '不应出现']],
    ]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectVersion', $otherVersion->version)
        ->assertSet('selectedVersion', 0)
        ->assertDontSee('不应出现');
});

test('the inspector shows every fixed story state domain and remains read only', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->assertSeeTextInOrder([
            '人物',
            '关系',
            '地点',
            '物品',
            '世界',
            '时间线',
            '开放线索',
            '伏笔',
            '读者承诺',
            '事实',
            '故事事件',
            '版本差异',
        ])
        ->assertDontSee('保存')
        ->assertDontSee('编辑')
        ->assertDontSee('删除');
});

test('an uninitialized novel has an explicit inspector empty state', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->assertSet('selectedVersion', null)
        ->assertSee('故事状态尚未初始化')
        ->assertSee('返回小说概览');
});

test('the story state inspector stays inside the novel workspace', function () {
    $novel = Novel::factory()->create();

    $this->get(NovelResource::getUrl('story-state', ['record' => $novel]))
        ->assertOk()
        ->assertSee('故事状态检查器')
        ->assertSee('故事状态');
});

test('the inspector exposes a dry run story state rebuild report', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->assertActionExists('verifyRebuild');

    $this->view('filament.resources.novels.pages.story-state-rebuild', [
        'result' => app(StoryStateRebuilder::class)->rebuild($novel->fresh()),
    ])
        ->assertSee('故事状态校验通过')
        ->assertSee('当前 checksum')
        ->assertSee('重建 checksum')
        ->assertSee('本次仅校验，不写入正式状态');

    expect($novel->storyStateVersions()->count())->toBe(1);
});
