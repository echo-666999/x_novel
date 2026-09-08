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

test('the planning workspace lists a novels volumes and progress', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $first = Volume::factory()->for($novel)->create([
        'sequence' => 1,
        'title' => '孤城卷',
        'goal' => '让主角发现孤城真正的危机。',
        'climax' => '城门在风暴中失守。',
        'status' => VolumeStatus::Active,
    ]);
    $second = Volume::factory()->for($novel)->create([
        'sequence' => 2,
        'title' => '远航卷',
        'status' => VolumeStatus::Planned,
    ]);

    Livewire::test(ManageNovelVolumes::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSeeTextInOrder([
            '分卷规划',
            '雾海长明',
            '卷序',
            '卷名',
            '本卷目标',
            '本卷高潮',
            '目标字数',
            '状态',
            '进度',
        ])
        ->assertCanSeeTableRecords([$first, $second])
        ->assertSee('孤城卷')
        ->assertSee('进行中')
        ->assertSee('0%')
        ->assertActionExists('create');
});

test('the owner can create and edit a volume', function () {
    $novel = Novel::factory()->create();

    $component = Livewire::test(ManageNovelVolumes::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'sequence' => 1,
            'title' => '启程卷',
            'goal' => '建立核心人物关系。',
            'climax' => '主角被迫离开故乡。',
            'target_words' => 180_000,
            'status' => VolumeStatus::Planned->value,
        ])
        ->assertHasNoActionErrors();

    $volume = $novel->volumes()->sole();

    expect($volume->title)->toBe('启程卷')
        ->and($volume->status)->toBe(VolumeStatus::Planned);

    $component
        ->callTableAction('edit', $volume, data: [
            'sequence' => 1,
            'title' => '离乡卷',
            'goal' => '建立核心人物关系并完成离乡。',
            'climax' => '主角主动踏出故乡。',
            'target_words' => 200_000,
            'status' => VolumeStatus::Active->value,
        ])
        ->assertHasNoTableActionErrors();

    expect($volume->refresh()->title)->toBe('离乡卷')
        ->and($volume->target_words)->toBe(200_000)
        ->and($volume->status)->toBe(VolumeStatus::Active);
});

test('the planning workspace validates required fields and scoped sequence uniqueness', function () {
    $novel = Novel::factory()->create();
    Volume::factory()->for($novel)->create(['sequence' => 1]);

    Livewire::test(ManageNovelVolumes::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'sequence' => 1,
            'title' => '',
            'goal' => '',
            'climax' => '',
            'target_words' => 0,
            'status' => null,
        ])
        ->assertHasActionErrors([
            'sequence' => 'unique',
            'title' => 'required',
            'goal' => 'required',
            'climax' => 'required',
            'target_words' => 'min',
            'status' => 'required',
        ]);
});

test('volume detail shows completion checklist and completes an unblocked active volume', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(ManageNovelVolumes::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionExists('completionChecklist', fn ($action): bool => $action->isModalSlideOver())
        ->mountTableAction('completionChecklist', $volume)
        ->assertSchemaComponentExists('completion_checks', null, fn ($component): bool => collect($component->getState())
            ->pluck('label')
            ->all() === ['Volume Goal', 'Climax', 'Required Arcs', 'Character Stage Changes', 'Due Foreshadowings', 'Blocking Findings'])
        ->unmountAction()
        ->assertTableActionEnabled('completeVolume', $volume)
        ->callTableAction('completeVolume', $volume)
        ->assertNotified('分卷已完成');

    expect($volume->fresh()->status)->toBe(VolumeStatus::Completed);
});

test('volume completion action is disabled while a required arc remains open', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    StoryArc::factory()->forVolume($volume)->create(['status' => StoryArcStatus::Active]);

    Livewire::test(ManageNovelVolumes::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionDisabled('completeVolume', $volume);
});

test('ordinary volume editing cannot bypass the completion gate', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(ManageNovelVolumes::class, ['record' => $novel->getRouteKey()])
        ->callTableAction('edit', $volume, data: [
            'sequence' => $volume->sequence,
            'title' => $volume->title,
            'goal' => $volume->goal,
            'climax' => $volume->climax,
            'target_words' => $volume->target_words,
            'status' => VolumeStatus::Completed->value,
        ])
        ->assertHasTableActionErrors(['status']);

    expect($volume->fresh()->status)->toBe(VolumeStatus::Active);
});
