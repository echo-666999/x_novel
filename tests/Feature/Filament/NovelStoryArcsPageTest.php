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

test('the story arc workspace shows arcs grouped under their volumes', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $volume = Volume::factory()->for($novel)->create(['title' => '孤城卷']);
    $mainArc = StoryArc::factory()->forVolume($volume)->create([
        'type' => StoryArcType::Main,
        'title' => '守住孤城',
        'goal' => '查明风暴源头并守住孤城。',
        'stakes' => '失败会让整座城沉入雾海。',
        'beats' => ['发现潮汐异常', '确认城防内鬼'],
        'completion_conditions' => ['风暴源头被关闭'],
        'progress' => 0.4,
        'status' => StoryArcStatus::Active,
    ]);
    $otherNovelArc = StoryArc::factory()->create(['title' => '不应出现']);

    Livewire::test(ManageNovelStoryArcs::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSeeTextInOrder([
            '故事线规划',
            '雾海长明',
            '孤城卷',
            '主线',
            '守住孤城',
            '推进中',
        ])
        ->assertCanSeeTableRecords([$mainArc])
        ->assertCanNotSeeTableRecords([$otherNovelArc])
        ->assertSee('40%')
        ->assertActionExists('create');
});

test('the owner can create and edit a story arc', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();

    $component = Livewire::test(ManageNovelStoryArcs::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'volume_id' => $volume->getKey(),
            'type' => StoryArcType::Main->value,
            'title' => '王都阴谋',
            'goal' => '揭开摄政王的计划。',
            'stakes' => '王国将陷入内战。',
            'beats' => [
                ['beat' => '发现伪造诏书'],
                ['beat' => '找到目击者'],
            ],
            'completion_conditions' => [
                ['condition' => '阴谋公开且主谋伏法'],
            ],
            'progress' => 0.2,
            'status' => StoryArcStatus::Active->value,
        ])
        ->assertHasNoActionErrors();

    $arc = $novel->storyArcs()->sole();

    expect($arc->volume->is($volume))->toBeTrue()
        ->and($arc->beats)->toBe(['发现伪造诏书', '找到目击者'])
        ->and($arc->progress)->toBe(0.2);

    $component
        ->callTableAction('edit', $arc, data: [
            'volume_id' => $volume->getKey(),
            'type' => StoryArcType::Main->value,
            'title' => '王都政变',
            'goal' => '阻止摄政王发动政变。',
            'stakes' => '王国将陷入内战。',
            'beats' => [
                ['beat' => '取得密令'],
            ],
            'completion_conditions' => [
                ['condition' => '政变被阻止'],
            ],
            'progress' => 0.75,
            'status' => StoryArcStatus::Active->value,
        ])
        ->assertHasNoTableActionErrors();

    expect($arc->refresh()->title)->toBe('王都政变')
        ->and($arc->beats)->toBe(['取得密令'])
        ->and($arc->progress)->toBe(0.75);
});

test('the story arc workspace validates required fields and progress range', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ManageNovelStoryArcs::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'type' => null,
            'title' => '',
            'goal' => '',
            'stakes' => '',
            'beats' => [],
            'completion_conditions' => [],
            'progress' => 1.1,
            'status' => null,
        ])
        ->assertHasActionErrors([
            'type' => 'required',
            'title' => 'required',
            'goal' => 'required',
            'stakes' => 'required',
            'progress' => 'max',
            'status' => 'required',
        ]);
});

test('the story arc form only offers volumes from the current novel', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create(['title' => '当前小说分卷']);
    $otherVolume = Volume::factory()->create(['title' => '其他小说分卷']);

    Livewire::test(ManageNovelStoryArcs::class, ['record' => $novel->getRouteKey()])
        ->mountAction('create')
        ->assertFormFieldExists('volume_id', function ($field) use ($volume, $otherVolume): bool {
            $options = $field->getOptions();

            return isset($options[$volume->getKey()]) && ! isset($options[$otherVolume->getKey()]);
        });
});
