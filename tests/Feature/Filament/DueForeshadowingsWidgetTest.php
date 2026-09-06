<?php

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Widgets\DueForeshadowingsWidget;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the dashboard widget shows due soon critical due and overdue foreshadowings across novels', function () {
    $firstNovel = Novel::factory()->create([
        'title' => '雾海长明',
        'current_chapter_sequence' => 15,
    ]);
    $secondNovel = Novel::factory()->create([
        'title' => '星河彼岸',
        'current_chapter_sequence' => 40,
    ]);
    $dueSoon = Foreshadowing::factory()->for($firstNovel)->create([
        'title' => '潮汐钟声',
        'due_from_chapter' => 12,
        'due_to_chapter' => 18,
        'status' => ForeshadowingStatus::Planted,
    ]);
    $criticalDue = Foreshadowing::factory()->for($firstNovel)->create([
        'title' => '王室血契',
        'due_from_chapter' => 14,
        'due_to_chapter' => 20,
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Reinforced,
    ]);
    $overdue = Foreshadowing::factory()->for($secondNovel)->create([
        'title' => '失落坐标',
        'due_from_chapter' => 20,
        'due_to_chapter' => 30,
        'status' => ForeshadowingStatus::Due,
    ]);

    Livewire::test(DueForeshadowingsWidget::class)
        ->assertOk()
        ->assertSeeTextInOrder([
            '待处理伏笔',
            'Due Soon、Critical Due 与 Overdue',
            '星河彼岸',
            '失落坐标',
            'Overdue',
            '雾海长明',
            '王室血契',
            'Critical Due',
            '潮汐钟声',
            'Due Soon',
        ])
        ->assertCanSeeTableRecords([$overdue, $criticalDue, $dueSoon], inOrder: true);
});

test('the widget excludes future and terminal foreshadowings', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => 10]);
    $future = Foreshadowing::factory()->for($novel)->create([
        'due_from_chapter' => 20,
        'due_to_chapter' => 30,
        'status' => ForeshadowingStatus::Planted,
    ]);
    $paidOff = Foreshadowing::factory()->for($novel)->create([
        'due_from_chapter' => 1,
        'due_to_chapter' => 5,
        'status' => ForeshadowingStatus::PaidOff,
    ]);
    $abandoned = Foreshadowing::factory()->for($novel)->create([
        'due_from_chapter' => 1,
        'due_to_chapter' => 5,
        'status' => ForeshadowingStatus::Abandoned,
    ]);

    Livewire::test(DueForeshadowingsWidget::class)
        ->assertCanNotSeeTableRecords([$future, $paidOff, $abandoned])
        ->assertSee('暂无待处理伏笔');
});

test('status due remains visible before a novel has a current chapter', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => null]);
    $due = Foreshadowing::factory()->for($novel)->create([
        'title' => '开篇承诺',
        'status' => ForeshadowingStatus::Due,
    ]);

    Livewire::test(DueForeshadowingsWidget::class)
        ->assertCanSeeTableRecords([$due])
        ->assertSee('Due Soon')
        ->assertSee('尚未开始');
});

test('each widget row links to the matching novel and foreshadowing filter', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => 15]);
    $overdue = Foreshadowing::factory()->for($novel)->create([
        'title' => '破损罗盘',
        'due_from_chapter' => 1,
        'due_to_chapter' => 10,
        'status' => ForeshadowingStatus::Reinforced,
    ]);

    $component = Livewire::test(DueForeshadowingsWidget::class);
    $url = $component->instance()->getTable()->getRecordUrl($overdue->load('novel'));

    expect($url)
        ->toStartWith(NovelResource::getUrl('foreshadowings', ['record' => $novel]))
        ->toContain('tableSearch=')
        ->toContain('tableFilters%5Battention%5D%5Bvalue%5D=overdue');
});

test('the dashboard renders the due foreshadowing widget in its existing slot', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => 15]);
    Foreshadowing::factory()->for($novel)->create([
        'title' => '灯塔暗号',
        'due_from_chapter' => 12,
        'due_to_chapter' => 18,
        'importance' => ForeshadowingImportance::Critical,
    ]);

    $this->get('/x')
        ->assertOk()
        ->assertSee('待处理伏笔')
        ->assertSee('灯塔暗号')
        ->assertSee('Critical Due');
});
