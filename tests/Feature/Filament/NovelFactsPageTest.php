<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelStoryState;
use App\Models\Fact;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('facts are listed read only inside the story state inspector', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $fact = Fact::factory()->for($novel)->create([
        'subject_type' => 'character',
        'subject_id' => 7,
        'predicate' => 'can_swim',
        'value' => ['value' => false],
        'hardness' => FactHardness::Hard,
        'locked' => true,
    ]);
    $otherFact = Fact::factory()->create(['predicate' => 'must_not_appear']);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectDomain', 'facts')
        ->assertSet('activeDomain', 'facts')
        ->assertSee('当前规范事实 · 只读')
        ->assertCanSeeTableRecords([$fact])
        ->assertCanNotSeeTableRecords([$otherFact])
        ->assertSee('can_swim')
        ->assertSee('false')
        ->assertSee('硬事实')
        ->assertSee('手工')
        ->assertSee('有效')
        ->assertDontSee('新增事实')
        ->assertDontSee('编辑')
        ->assertDontSee('删除');
});

test('facts can be searched by subject and predicate', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $matching = Fact::factory()->for($novel)->create([
        'subject_type' => 'world_entity',
        'subject_id' => 314,
        'predicate' => 'belongs_to_keeper',
    ]);
    $other = Fact::factory()->for($novel)->create([
        'subject_type' => 'character',
        'subject_id' => 271,
        'predicate' => 'can_swim',
    ]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectDomain', 'facts')
        ->searchTable('belongs_to_keeper')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('314')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

test('facts can be filtered by locked active and source', function () {
    $novel = Novel::factory()->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $activeManualLocked = Fact::factory()->for($novel)->create([
        'locked' => true,
        'status' => FactStatus::Active,
        'source_type' => FactSourceType::Manual,
    ]);
    $activeBibleUnlocked = Fact::factory()->for($novel)->create([
        'locked' => false,
        'status' => FactStatus::Active,
        'source_type' => FactSourceType::Bible,
    ]);
    $supersededManualLocked = Fact::factory()->for($novel)->create([
        'locked' => true,
        'status' => FactStatus::Superseded,
        'source_type' => FactSourceType::Manual,
    ]);

    Livewire::test(ViewNovelStoryState::class, ['record' => $novel->getRouteKey()])
        ->call('selectDomain', 'facts')
        ->filterTable('locked', true)
        ->assertCanSeeTableRecords([$activeManualLocked, $supersededManualLocked])
        ->assertCanNotSeeTableRecords([$activeBibleUnlocked])
        ->resetTableFilters()
        ->filterTable('status', FactStatus::Active->value)
        ->assertCanSeeTableRecords([$activeManualLocked, $activeBibleUnlocked])
        ->assertCanNotSeeTableRecords([$supersededManualLocked])
        ->resetTableFilters()
        ->filterTable('source_type', FactSourceType::Bible->value)
        ->assertCanSeeTableRecords([$activeBibleUnlocked])
        ->assertCanNotSeeTableRecords([$activeManualLocked, $supersededManualLocked]);
});
