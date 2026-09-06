<?php

use App\Enums\WorldEntityStatus;
use App\Enums\WorldEntityType;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ManageNovelWorld;
use App\Models\Novel;
use App\Models\User;
use App\Models\WorldEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the world workspace lists useful entity state for the current novel', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $location = WorldEntity::factory()->for($novel)->create([
        'type' => WorldEntityType::Location,
        'name' => '旧港',
        'description' => '被雾潮侵蚀的古老港口。',
        'current_state' => ['access' => '封锁', 'weather' => '暴雨'],
        'locked_fields' => ['rules.access'],
        'status' => WorldEntityStatus::Active,
    ]);
    $otherEntity = WorldEntity::factory()->create(['name' => '不应出现']);

    Livewire::test(ManageNovelWorld::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSet('activeTab', 'locations')
        ->assertSeeTextInOrder([
            '世界实体',
            '雾海长明',
            '地点',
            '物品',
            '阵营与组织',
            '规则',
            '其他',
            '名称',
            '类型',
            '状态',
            '当前状态',
            '旧港',
            '被雾潮侵蚀的古老港口。',
            '有效',
            'access: 封锁',
            '锁定 1 项',
        ])
        ->assertCanSeeTableRecords([$location])
        ->assertCanNotSeeTableRecords([$otherEntity])
        ->assertActionExists('create');
});

test('world workspace tabs map all entity types into five groups', function () {
    $novel = Novel::factory()->create();
    $location = WorldEntity::factory()->for($novel)->create(['type' => WorldEntityType::Location]);
    $item = WorldEntity::factory()->for($novel)->create(['type' => WorldEntityType::Item]);
    $faction = WorldEntity::factory()->for($novel)->create(['type' => WorldEntityType::Faction]);
    $organization = WorldEntity::factory()->for($novel)->create(['type' => WorldEntityType::Organization]);
    $rule = WorldEntity::factory()->for($novel)->create(['type' => WorldEntityType::Rule]);
    $concept = WorldEntity::factory()->for($novel)->create(['type' => WorldEntityType::Concept]);

    Livewire::test(ManageNovelWorld::class, ['record' => $novel->getRouteKey()])
        ->assertCanSeeTableRecords([$location])
        ->assertCanNotSeeTableRecords([$item, $faction, $organization, $rule, $concept])
        ->set('activeTab', 'items')
        ->assertCanSeeTableRecords([$item])
        ->set('activeTab', 'factions')
        ->assertCanSeeTableRecords([$faction, $organization])
        ->set('activeTab', 'rules')
        ->assertCanSeeTableRecords([$rule])
        ->set('activeTab', 'other')
        ->assertCanSeeTableRecords([$concept]);
});

test('the owner can create and edit a world entity', function () {
    $novel = Novel::factory()->create();

    $component = Livewire::test(ManageNovelWorld::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'type' => WorldEntityType::Location->value,
            'name' => '旧港',
            'description' => '被雾潮侵蚀的古老港口。',
            'status' => WorldEntityStatus::Active->value,
            'attributes' => ['region' => '北境'],
            'rules' => ['access' => '仅退潮时开放'],
            'current_state' => ['condition' => '受损'],
            'locked_fields' => ['rules.access'],
        ])
        ->assertHasNoActionErrors();

    $entity = $novel->worldEntities()->sole();

    expect($entity->attributes)->toBe(['region' => '北境'])
        ->and($entity->rules)->toBe(['access' => '仅退潮时开放'])
        ->and($entity->locked_fields)->toBe(['rules.access']);

    $component
        ->callTableAction('edit', $entity, data: [
            'type' => WorldEntityType::Location->value,
            'name' => '新港',
            'description' => '重建后的北境港口。',
            'status' => WorldEntityStatus::Inactive->value,
            'attributes' => ['region' => '北境'],
            'rules' => ['access' => '禁止进入'],
            'current_state' => ['condition' => '重建中'],
            'locked_fields' => ['rules.access'],
        ])
        ->assertHasNoTableActionErrors();

    expect($entity->refresh()->name)->toBe('新港')
        ->and($entity->status)->toBe(WorldEntityStatus::Inactive)
        ->and($entity->current_state)->toBe(['condition' => '重建中']);
});

test('the world entity form validates required fields', function () {
    $novel = Novel::factory()->create();

    Livewire::test(ManageNovelWorld::class, ['record' => $novel->getRouteKey()])
        ->callAction('create', data: [
            'type' => null,
            'name' => '',
            'description' => '',
            'status' => null,
        ])
        ->assertHasActionErrors([
            'type' => 'required',
            'name' => 'required',
            'description' => 'required',
            'status' => 'required',
        ]);
});

test('the world entity detail uses a slideover with complete structured data', function () {
    $novel = Novel::factory()->create();
    $entity = WorldEntity::factory()->for($novel)->create([
        'description' => '只在午夜出现的潮汐门。',
        'attributes' => ['material' => '黑石'],
        'rules' => ['trigger' => '午夜潮汐'],
        'current_state' => ['open' => '否'],
        'locked_fields' => ['rules.trigger'],
    ]);

    Livewire::test(ManageNovelWorld::class, ['record' => $novel->getRouteKey()])
        ->assertTableActionExists('view', fn ($action): bool => $action->isModalSlideOver())
        ->mountTableAction('view', $entity)
        ->assertTableActionDataSet(function (array $data): bool {
            return $data['description'] === '只在午夜出现的潮汐门。'
                && $data['attributes'] === ['material' => '黑石']
                && $data['rules'] === ['trigger' => '午夜潮汐']
                && $data['current_state'] === ['open' => '否']
                && $data['locked_fields'] === ['rules.trigger'];
        });
});

test('the world workspace filters by type and status', function () {
    $novel = Novel::factory()->create();
    $activeLocation = WorldEntity::factory()->for($novel)->create([
        'type' => WorldEntityType::Location,
        'status' => WorldEntityStatus::Active,
    ]);
    $inactiveLocation = WorldEntity::factory()->for($novel)->create([
        'type' => WorldEntityType::Location,
        'status' => WorldEntityStatus::Inactive,
    ]);

    Livewire::test(ManageNovelWorld::class, ['record' => $novel->getRouteKey()])
        ->filterTable('status', WorldEntityStatus::Active->value)
        ->assertCanSeeTableRecords([$activeLocation])
        ->assertCanNotSeeTableRecords([$inactiveLocation]);
});

test('world entity management remains inside the novel workspace', function () {
    $novel = Novel::factory()->create();

    $this->get(NovelResource::getUrl('world', ['record' => $novel]))
        ->assertOk()
        ->assertSee('世界实体')
        ->assertSee('小说')
        ->assertSee('概览')
        ->assertSee('世界');
});
