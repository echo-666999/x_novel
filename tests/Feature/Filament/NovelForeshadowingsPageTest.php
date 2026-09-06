<?php

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelForeshadowings;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the foreshadowing workspace makes critical due and overdue items visible', function () {
    $novel = Novel::factory()->create([
        'title' => '雾海长明',
        'current_chapter_sequence' => 15,
    ]);
    $arc = StoryArc::factory()->for($novel)->create(['title' => '追查雾潮']);
    $overdue = Foreshadowing::factory()->for($novel)->create([
        'title' => '破损罗盘',
        'promised_payoff' => '揭示旧航路入口。',
        'due_from_chapter' => 5,
        'due_to_chapter' => 10,
        'status' => ForeshadowingStatus::Reinforced,
        'owner_arc_id' => $arc->getKey(),
    ]);
    $criticalDue = Foreshadowing::factory()->for($novel)->create([
        'title' => '灯塔暗号',
        'setup_chapter_id' => 3,
        'due_from_chapter' => 12,
        'due_to_chapter' => 18,
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Due,
        'owner_arc_id' => $arc->getKey(),
    ]);
    $otherForeshadowing = Foreshadowing::factory()->create(['title' => '不应出现']);

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSeeTextInOrder([
            '伏笔管理',
            '雾海长明',
            '伏笔',
            '需处理',
            '状态',
            '重要度',
            '铺设章节',
            '到期窗口',
            '所属故事线',
            '兑现章节',
            '破损罗盘',
            'Overdue',
            '灯塔暗号',
            'Critical',
            'Due',
        ])
        ->assertCanSeeTableRecords([$overdue, $criticalDue])
        ->assertCanNotSeeTableRecords([$otherForeshadowing])
        ->assertActionExists('create');
});

test('the owner can create and edit a foreshadowing', function () {
    $novel = Novel::factory()->create();
    $arc = StoryArc::factory()->for($novel)->create();

    $component = Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'title' => '破损罗盘',
            'owner_arc_id' => $arc->getKey(),
            'description' => '主角在旧船中发现无法指北的罗盘。',
            'promised_payoff' => '罗盘最终指向隐藏航路。',
            'setup_chapter_id' => 3,
            'due_from_chapter' => 20,
            'due_to_chapter' => 30,
            'payoff_chapter_id' => null,
            'importance' => ForeshadowingImportance::High->value,
            'status' => ForeshadowingStatus::Planted->value,
            'reinforce_count' => 1,
            'notes' => '第十五章需要再次出现。',
        ])
        ->assertHasNoActionErrors();

    $foreshadowing = $novel->foreshadowings()->sole();

    expect($foreshadowing->ownerArc->is($arc))->toBeTrue()
        ->and($foreshadowing->due_from_chapter)->toBe(20)
        ->and($foreshadowing->importance)->toBe(ForeshadowingImportance::High);

    $component
        ->callTableAction('edit', $foreshadowing, data: [
            'title' => '潮汐罗盘',
            'owner_arc_id' => $arc->getKey(),
            'description' => '罗盘会随雾潮改变方向。',
            'promised_payoff' => '罗盘最终指向隐藏航路。',
            'setup_chapter_id' => 3,
            'due_from_chapter' => 20,
            'due_to_chapter' => 30,
            'payoff_chapter_id' => 28,
            'importance' => ForeshadowingImportance::Critical->value,
            'status' => ForeshadowingStatus::PaidOff->value,
            'reinforce_count' => 2,
            'notes' => '已在隐藏航路兑现。',
        ])
        ->assertHasNoTableActionErrors();

    expect($foreshadowing->refresh()->title)->toBe('潮汐罗盘')
        ->and($foreshadowing->status)->toBe(ForeshadowingStatus::PaidOff)
        ->and($foreshadowing->payoff_chapter_id)->toBe(28);
});

test('the form validates required fields and due window order', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'title' => '',
            'description' => '',
            'promised_payoff' => '',
            'due_from_chapter' => 20,
            'due_to_chapter' => 19,
            'importance' => null,
            'status' => null,
            'reinforce_count' => -1,
        ])
        ->assertHasActionErrors([
            'title' => 'required',
            'description' => 'required',
            'promised_payoff' => 'required',
            'due_to_chapter' => 'gte',
            'importance' => 'required',
            'status' => 'required',
            'reinforce_count' => 'min',
        ]);
});

test('the attention filter isolates due overdue and critical foreshadowings', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => 15]);
    $due = Foreshadowing::factory()->for($novel)->create([
        'due_from_chapter' => 10,
        'due_to_chapter' => 20,
        'status' => ForeshadowingStatus::Planted,
    ]);
    $overdue = Foreshadowing::factory()->for($novel)->create([
        'due_from_chapter' => 1,
        'due_to_chapter' => 9,
        'status' => ForeshadowingStatus::Reinforced,
    ]);
    $critical = Foreshadowing::factory()->for($novel)->create([
        'due_from_chapter' => 30,
        'due_to_chapter' => 40,
        'importance' => ForeshadowingImportance::Critical,
    ]);

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->filterTable('attention', 'due')
        ->assertCanSeeTableRecords([$due])
        ->assertCanNotSeeTableRecords([$overdue, $critical]);

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->filterTable('attention', 'overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$due, $critical]);

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->filterTable('attention', 'critical')
        ->assertCanSeeTableRecords([$critical])
        ->assertCanNotSeeTableRecords([$due, $overdue]);
});

test('the owner arc picker is scoped to the current novel', function () {
    $novel = Novel::factory()->create();
    $arc = StoryArc::factory()->for($novel)->create();
    $otherArc = StoryArc::factory()->create();

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->mountAction('create')
        ->assertFormFieldExists('owner_arc_id', function ($field) use ($arc, $otherArc): bool {
            $options = $field->getOptions();

            return isset($options[$arc->getKey()]) && ! isset($options[$otherArc->getKey()]);
        });
});

test('the foreshadowing detail uses a slideover with complete planning data', function () {
    $novel = Novel::factory()->create();
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'description' => '旧钟每逢雾潮便逆行。',
        'promised_payoff' => '旧钟揭示城市时间循环。',
        'notes' => '强化时保持钟声意象。',
    ]);

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionExists('view', fn ($action): bool => $action->isModalSlideOver())
        ->mountTableAction('view', $foreshadowing)
        ->assertTableActionDataSet(function (array $data): bool {
            return $data['description'] === '旧钟每逢雾潮便逆行。'
                && $data['promised_payoff'] === '旧钟揭示城市时间循环。'
                && $data['notes'] === '强化时保持钟声意象。';
        });
});

test('foreshadowing management remains inside the novel workspace', function () {
    $novel = Novel::factory()->create();

    $this->get(NovelResource::getUrl('foreshadowings', ['record' => $novel]))
        ->assertOk()
        ->assertSee('伏笔管理')
        ->assertSee('小说')
        ->assertSee('概览')
        ->assertSee('伏笔');
});
