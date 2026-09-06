<?php

use App\Enums\BibleStatus;
use App\Filament\Resources\Novels\Pages\ManageNovelBible;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the bible workspace shows current content and version history', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    NovelBible::factory()->for($novel)->create([
        'version' => 1,
        'logline' => '旧版故事梗概',
        'status' => BibleStatus::Superseded,
    ]);
    NovelBible::factory()->for($novel)->create([
        'version' => 2,
        'logline' => '当前故事梗概',
        'status' => BibleStatus::Current,
        'hard_constraints' => ['主角不能复活'],
    ]);

    Livewire::test(ManageNovelBible::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSeeTextInOrder([
            '小说圣经',
            '当前版本',
            'v2',
            '当前故事梗概',
            '叙事基线',
            '硬约束',
            '主角不能复活',
            '结局契约',
            '版本历史',
        ])
        ->assertSee('旧版故事梗概')
        ->assertActionExists('createBibleVersion');
});

test('the owner can create a bible version from its workspace', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ManageNovelBible::class, ['record' => $novel->getRouteKey()])
        ->callAction('createBibleVersion', data: [
            'logline' => '少女穿过禁区寻找失踪的兄长。',
            'themes' => ['亲情', '真相'],
            'tone' => '冷峻',
            'pov' => '第三人称限知',
            'tense' => '过去时',
            'taboos' => ['无代价复活'],
            'hard_constraints' => ['兄长失踪前未进入王都'],
            'ending_contract' => [
                'final_protagonist_state' => '接受真相',
                'main_conflict_resolution' => '揭开禁区来源',
                'theme_payoff' => '亲情并不等于盲从',
                'allowed_open_endings' => '保留王都未来走向',
                'required_foreshadowing_payoff' => ['染血地图'],
                'character_arc_requirements' => ['从依赖走向独立'],
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('小说圣经新版本已创建');

    $bible = $novel->fresh()->currentBible;

    expect($bible)->not->toBeNull()
        ->and($bible->version)->toBe(1)
        ->and($bible->status)->toBe(BibleStatus::Current)
        ->and($bible->themes)->toBe(['亲情', '真相'])
        ->and($bible->ending_contract['theme_payoff'])->toBe('亲情并不等于盲从');
});

test('the bible workspace validates required narrative fields', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ManageNovelBible::class, ['record' => $novel->getRouteKey()])
        ->callAction('createBibleVersion', data: [
            'logline' => '',
            'themes' => [],
            'tone' => '',
            'pov' => '',
            'tense' => '',
        ])
        ->assertHasActionErrors([
            'logline' => 'required',
            'themes' => 'required',
            'tone' => 'required',
            'pov' => 'required',
            'tense' => 'required',
        ]);
});
