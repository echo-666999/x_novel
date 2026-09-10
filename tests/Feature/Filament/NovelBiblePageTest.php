<?php

use App\Actions\Novels\CreateBibleVersionAction;
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

function biblePageStyleProfile(array $overrides = []): array
{
    return array_replace_recursive([
        'subgenre' => '东方玄幻',
        'target_platform' => 'qidian',
        'primary_style' => 'passionate',
        'secondary_styles' => ['accessible_brisk'],
        'language_era' => 'modern_spoken',
        'pacing' => 'fast',
        'parameters' => [
            'ornateness' => 2,
            'dialogue_ratio' => 4,
            'description_density' => 3,
            'psychology_density' => 2,
            'humor_level' => 1,
            'literary_level' => 2,
        ],
    ], $overrides);
}

test('the bible workspace shows current content and version history', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    NovelBible::factory()->for($novel)->create([
        'version' => 1,
        'logline' => '旧版故事梗概',
        'status' => BibleStatus::Superseded,
        'style_profile' => biblePageStyleProfile([
            'primary_style' => 'austere',
            'secondary_styles' => [],
            'pacing' => 'balanced',
        ]),
    ]);
    NovelBible::factory()->for($novel)->create([
        'version' => 2,
        'logline' => '当前故事梗概',
        'status' => BibleStatus::Current,
        'hard_constraints' => ['主角不能复活'],
        'style_profile' => biblePageStyleProfile(),
    ]);

    Livewire::test(ManageNovelBible::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSeeTextInOrder([
            '小说圣经',
            '当前版本',
            'v2',
            '当前故事梗概',
            '叙事与文风基线',
            '热血激昂',
            '通俗爽快',
            '现代口语',
            '快节奏',
            '东方玄幻',
            '起点中文网',
            '文风高级设置',
            '4 / 5',
            '硬约束',
            '主角不能复活',
            '结局契约',
            '版本历史',
        ])
        ->assertSee('旧版故事梗概')
        ->assertSee('冷峻克制')
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
            'style_profile' => biblePageStyleProfile(),
            'ending_contract' => [
                'final_protagonist_state' => '接受真相',
                'main_conflict_resolution' => '揭开禁区来源',
                'theme_payoff' => '亲情并不等于盲从',
                'allowed_open_endings' => ['保留王都未来走向'],
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
        ->and($bible->style_profile)->toEqual(biblePageStyleProfile())
        ->and($bible->ending_contract['theme_payoff'])->toBe('亲情并不等于盲从')
        ->and($bible->ending_contract['allowed_open_endings'])->toBe(['保留王都未来走向']);
});

test('ending contract is displayed as six structured requirements', function () {
    $novel = Novel::factory()->create();
    NovelBible::factory()->for($novel)->create([
        'ending_contract' => [
            'final_protagonist_state' => '主角成为守门人',
            'main_conflict_resolution' => '关闭雾潮源头',
            'theme_payoff' => '责任来自主动选择',
            'required_foreshadowing_payoff' => ['断剑来历'],
            'character_arc_requirements' => ['主角停止逃避责任'],
            'allowed_open_endings' => ['远海文明的真相'],
        ],
    ]);

    Livewire::test(ManageNovelBible::class, ['record' => $novel->getRouteKey()])
        ->assertSee('主角最终状态')
        ->assertSee('主角成为守门人')
        ->assertSee('主冲突解决方式')
        ->assertSee('关闭雾潮源头')
        ->assertSee('主题兑现')
        ->assertSee('责任来自主动选择')
        ->assertSee('必须回收的伏笔')
        ->assertSee('断剑来历')
        ->assertSee('人物弧要求')
        ->assertSee('主角停止逃避责任')
        ->assertSee('允许保留的开放结局')
        ->assertSee('远海文明的真相');
});

test('legacy open ending text is normalized when creating the next bible version', function () {
    $novel = Novel::factory()->create();
    $first = NovelBible::factory()->for($novel)->create([
        'ending_contract' => [
            'final_protagonist_state' => '承担责任',
            'main_conflict_resolution' => '终结战争',
            'theme_payoff' => '选择定义身份',
            'required_foreshadowing_payoff' => ['旧信件'],
            'character_arc_requirements' => ['停止逃避'],
            'allowed_open_endings' => '远海文明',
        ],
    ]);

    $data = $first->only(['logline', 'themes', 'tone', 'pov', 'tense', 'taboos', 'hard_constraints', 'ending_contract', 'style_profile']);
    $next = app(CreateBibleVersionAction::class)->execute($novel, $data);

    expect($next->ending_contract['allowed_open_endings'])->toBe(['远海文明']);
});

test('the new bible version form prefills the current style profile', function () {
    $novel = Novel::factory()->create();
    NovelBible::factory()->for($novel)->create([
        'style_profile' => biblePageStyleProfile([
            'primary_style' => 'delicate_emotional',
            'secondary_styles' => ['warm_healing'],
            'parameters' => ['psychology_density' => 5],
        ]),
    ]);

    Livewire::test(ManageNovelBible::class, ['record' => $novel->getRouteKey()])
        ->mountAction('createBibleVersion')
        ->assertActionDataSet([
            'style_profile' => biblePageStyleProfile([
                'primary_style' => 'delicate_emotional',
                'secondary_styles' => ['warm_healing'],
                'parameters' => ['psychology_density' => 5],
            ]),
        ]);
});

test('the bible form rejects conflicting styles and incomplete parameters with chinese errors', function () {
    $novel = Novel::factory()->create();
    $data = [
        'logline' => '少女穿过禁区寻找失踪的兄长。',
        'themes' => ['亲情'],
        'tone' => '热血',
        'pov' => '第一人称',
        'tense' => '过去时',
        'taboos' => [],
        'hard_constraints' => [],
        'style_profile' => biblePageStyleProfile([
            'secondary_styles' => ['passionate'],
        ]),
        'ending_contract' => [
            'final_protagonist_state' => '接受真相',
            'main_conflict_resolution' => '揭开禁区来源',
            'theme_payoff' => '亲情并不等于盲从',
            'allowed_open_endings' => ['王都未来'],
            'required_foreshadowing_payoff' => ['染血地图'],
            'character_arc_requirements' => ['从依赖走向独立'],
        ],
    ];
    $data['style_profile']['parameters']['literary_level'] = null;

    $component = Livewire::test(ManageNovelBible::class, ['record' => $novel->getRouteKey()])
        ->callAction('createBibleVersion', data: $data)
        ->assertHasActionErrors([
            'style_profile.parameters.literary_level',
        ]);

    expect(collect($component->errors())->flatten()->implode(' '))->toContain('请选择文学性');

    $data['style_profile']['parameters']['literary_level'] = 2;

    $component = Livewire::test(ManageNovelBible::class, ['record' => $novel->getRouteKey()])
        ->callAction('createBibleVersion', data: $data);

    expect($component->errors()->toArray())
        ->toHaveKey('style_profile.secondary_styles')
        ->and(collect($component->errors())->flatten()->implode(' '))->toContain('辅助文风不能与主文风重复');
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
