<?php

use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Filament\Resources\Novels\Pages\ManageNovelStoryArcs;
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

test('the story arc workspace lists normalized definitions without direct edit actions', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $volume = Volume::factory()->for($novel)->create(['title' => '孤城卷']);
    $arc = StoryArc::factory()->forVolume($volume)->create([
        'type' => StoryArcType::Main,
        'title' => '守住孤城',
        'status' => StoryArcStatus::Active,
    ]);

    Livewire::test(ManageNovelStoryArcs::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertCanSeeTableRecords([$arc])
        ->assertSee('守住孤城')
        ->assertSee('1 项')
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit');
});

test('the story arc workspace excludes another novels arcs', function () {
    $novel = Novel::factory()->create();
    $own = StoryArc::factory()->for($novel)->create(['title' => '本书故事线']);
    $foreign = StoryArc::factory()->create(['title' => '其他小说故事线']);

    Livewire::test(ManageNovelStoryArcs::class, ['record' => $novel->getRouteKey()])
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});
