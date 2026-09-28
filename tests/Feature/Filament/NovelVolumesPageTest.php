<?php

use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\Pages\ManageNovelVolumes;
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

test('the planning workspace lists normalized runtime volumes as read only definitions', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $volume = Volume::factory()->for($novel)->create([
        'title' => '孤城卷',
        'status' => VolumeStatus::Active,
    ]);

    Livewire::test(ManageNovelVolumes::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertCanSeeTableRecords([$volume])
        ->assertSee('孤城卷')
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit');
});

test('volume detail completes an unblocked active volume', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(ManageNovelVolumes::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionExists('completionChecklist', fn ($action): bool => $action->isModalSlideOver())
        ->assertTableActionEnabled('completeVolume', $volume)
        ->callTableAction('completeVolume', $volume)
        ->assertNotified('分卷已完成');

    expect($volume->fresh()->status)->toBe(VolumeStatus::Completed);
});

test('volume completion remains disabled while a required arc is open', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    StoryArc::factory()->forVolume($volume)->create(['status' => StoryArcStatus::Active]);

    Livewire::test(ManageNovelVolumes::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionDisabled('completeVolume', $volume);
});
