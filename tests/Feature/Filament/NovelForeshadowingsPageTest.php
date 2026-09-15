<?php

use App\Actions\Foreshadowings\AbandonForeshadowingAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingPlanAction;
use App\Enums\ForeshadowingStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelForeshadowings;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Services\StoryStateService;
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
        'status' => ForeshadowingStatus::Reinforced,
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
            '内容状态',
            '时限状态',
            '重要度',
            '铺设章节',
            '兑现窗口',
            '所属故事线',
            '兑现章节',
            '破损罗盘',
            '已逾期',
            '灯塔暗号',
            '关键',
            '兑现窗口',
        ])
        ->assertCanSeeTableRecords([$overdue, $criticalDue])
        ->assertCanNotSeeTableRecords([$otherForeshadowing])
        ->assertActionExists('create');
});

test('the owner can create and edit a foreshadowing', function () {
    $novel = Novel::factory()->create();
    $arc = StoryArc::factory()->for($novel)->create();
    $setupChapter = Chapter::factory()->for($novel)->create(['sequence' => 3]);
    $payoffChapter = Chapter::factory()->for($novel)->create(['sequence' => 28]);

    $component = Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'title' => '破损罗盘',
            'owner_arc_id' => $arc->getKey(),
            'description' => '主角在旧船中发现无法指北的罗盘。',
            'promised_payoff' => '罗盘最终指向隐藏航路。',
            'setup_chapter_id' => $setupChapter->getKey(),
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
            'setup_chapter_id' => $setupChapter->getKey(),
            'due_from_chapter' => 20,
            'due_to_chapter' => 30,
            'payoff_chapter_id' => $payoffChapter->getKey(),
            'importance' => ForeshadowingImportance::Critical->value,
            'status' => ForeshadowingStatus::PaidOff->value,
            'reinforce_count' => 2,
            'notes' => '已在隐藏航路兑现。',
        ])
        ->assertHasNoTableActionErrors();

    expect($foreshadowing->refresh()->title)->toBe('潮汐罗盘')
        ->and($foreshadowing->importance)->toBe(ForeshadowingImportance::Critical)
        ->and($foreshadowing->status)->toBe(ForeshadowingStatus::Planted)
        ->and($foreshadowing->reinforce_count)->toBe(1)
        ->and($foreshadowing->payoff_chapter_id)->toBeNull();
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

test('the setup and payoff chapter pickers are scoped to the current novel', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 3, 'title' => '雾港']);
    $otherChapter = Chapter::factory()->create(['sequence' => 4, 'title' => '不应出现']);

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->mountAction('create')
        ->assertFormFieldExists('setup_chapter_id', function ($field) use ($chapter, $otherChapter): bool {
            $options = $field->getOptions();

            return isset($options[$chapter->getKey()]) && ! isset($options[$otherChapter->getKey()]);
        })
        ->assertFormFieldExists('payoff_chapter_id', function ($field) use ($chapter, $otherChapter): bool {
            $options = $field->getOptions();

            return isset($options[$chapter->getKey()]) && ! isset($options[$otherChapter->getKey()]);
        });
});

test('the form cannot create the legacy due content status', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->mountAction('create')
        ->assertFormFieldExists('status', function ($field): bool {
            return ! array_key_exists(ForeshadowingStatus::Due->value, $field->getOptions());
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

test('the workspace distinguishes canonical progress draft progress events plans and projection drift', function () {
    $novel = Novel::factory()->create([
        'title' => '雾海长明',
        'current_chapter_sequence' => 10,
    ]);
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'title' => '潮汐门密钥',
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Idea,
        'reinforce_count' => 0,
        'due_from_chapter' => 1,
        'due_to_chapter' => 10,
    ]);
    $initial = app(InitializeNovelStateAction::class)->handle($novel);
    $canonicalChapter = Chapter::factory()->for($novel)->create([
        'sequence' => 10,
        'title' => '旧港回声',
        'status' => ChapterStatus::Canonical,
    ]);
    foreach ([EventType::ForeshadowingPlanted, ...array_fill(0, 11, EventType::ForeshadowingReinforced)] as $index => $eventType) {
        StoryEvent::factory()->for($novel)->for($canonicalChapter)->create([
            'event_type' => $eventType,
            'subject_type' => 'foreshadowing',
            'subject_id' => (string) $foreshadowing->getKey(),
            'state_version' => $index + 1,
            'evidence' => [['quote' => "第 {$index} 次有效证据"]],
        ]);
    }
    $state = $initial->state;
    data_set($state, "foreshadowings.{$foreshadowing->getKey()}.status", ForeshadowingStatus::Reinforced->value);
    data_set($state, "foreshadowings.{$foreshadowing->getKey()}.reinforce_count", 11);
    $current = StoryStateVersion::factory()->for($novel)->for($canonicalChapter)->create([
        'version' => 12,
        'state' => $state,
        'checksum' => app(StoryStateService::class)->checksum($state),
    ]);
    $novel->update(['canonical_state_version_id' => $current->getKey()]);
    $activeChapter = Chapter::factory()->for($novel)->create([
        'sequence' => 11,
        'title' => '修复潮汐门',
        'status' => ChapterStatus::Generating,
    ]);
    ChapterPlan::factory()->for($activeChapter)->create([
        'foreshadowing_actions' => [[
            'foreshadowing_id' => $foreshadowing->getKey(),
            'action' => ForeshadowingPlanAction::PayOff->value,
            'target_scene_sequence' => 1,
            'acceptance_criteria' => '明确打开潮汐门。',
            'reason' => null,
        ]],
    ]);

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->assertSeeText('Canonical 进度：第 10 章')
        ->assertSeeText('活跃章节：第 11 章（生成中）')
        ->assertSeeText('已强化')
        ->assertSeeText('11 次')
        ->assertSeeText('已逾期')
        ->assertSeeText('已漂移')
        ->assertSeeText('第 10 章 · Foreshadowing Reinforced')
        ->assertSeeText('第 11 章 · 兑现')
        ->assertTableActionVisible('arrangeRepair', $foreshadowing)
        ->assertTableActionVisible('defer', $foreshadowing)
        ->assertTableActionVisible('abandon', $foreshadowing)
        ->assertTableActionVisible('inspectProjection', $foreshadowing)
        ->assertActionExists('rebuildProjection');
});

test('critical overdue defer action requires a reason and persists the audit', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => 10]);
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 1,
        'due_to_chapter' => 10,
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->callTableAction('defer', $foreshadowing, data: [
            'new_due_from_chapter' => 15,
            'new_due_to_chapter' => 20,
            'reason' => '',
        ])
        ->assertHasTableActionErrors(['reason' => 'required']);

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->callTableAction('defer', $foreshadowing, data: [
            'new_due_from_chapter' => 15,
            'new_due_to_chapter' => 20,
            'reason' => '主线冲突尚未结束，延期处理。',
        ])->assertHasNoTableActionErrors();

    expect($foreshadowing->fresh()->due_from_chapter)->toBe(15)
        ->and(data_get($foreshadowing->fresh()->management_history, '0.actor_id'))->toBe(auth()->id());
});

test('the workspace shows an abandonment correction as the latest effective event', function () {
    $novel = Novel::factory()->create(['current_chapter_sequence' => 8]);
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'status' => ForeshadowingStatus::Reinforced,
    ]);
    $initial = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 8,
        'status' => ChapterStatus::Canonical,
    ]);
    $current = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
        'version' => 1,
        'state' => $initial->state,
        'checksum' => app(StoryStateService::class)->checksum($initial->state),
    ]);
    $novel->update(['canonical_state_version_id' => $current->getKey()]);

    app(AbandonForeshadowingAction::class)->execute(
        $foreshadowing,
        '该承诺与已确定结局冲突，明确放弃。',
        auth()->id(),
    );

    Livewire::test(ManageNovelForeshadowings::class, ['record' => $novel->getRouteKey()])
        ->assertSeeText('第 8 章 · Manual Correction')
        ->assertSeeText('该承诺与已确定结局冲突，明确放弃。');
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
