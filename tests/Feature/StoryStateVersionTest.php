<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\CharacterStatus;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\WorldEntityStatus;
use App\Enums\WorldEntityType;
use App\Models\Character;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryStateVersion;
use App\Models\WorldEntity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('first initialization creates state version zero and updates the novel pointer', function () {
    $novel = Novel::factory()->create();

    $stateVersion = app(InitializeNovelStateAction::class)->handle($novel);

    expect($stateVersion->version)->toBe(0)
        ->and($stateVersion->chapter_id)->toBeNull()
        ->and($stateVersion->state['schema_version'])->toBe(1)
        ->and($stateVersion->checksum)->toHaveLength(64)
        ->and($novel->refresh()->canonicalStateVersion->is($stateVersion))->toBeTrue()
        ->and($novel->storyStateVersions()->count())->toBe(1);
});

test('duplicate initialization returns the existing state zero', function () {
    $novel = Novel::factory()->create();
    $action = app(InitializeNovelStateAction::class);

    $first = $action->handle($novel);
    $second = $action->handle($novel->fresh());

    expect($second->is($first))->toBeTrue()
        ->and($second->checksum)->toBe($first->checksum)
        ->and($novel->storyStateVersions()->count())->toBe(1);
});

test('state zero projects the current bible characters world and foreshadowings', function () {
    $novel = Novel::factory()->create();
    NovelBible::factory()->for($novel)->create([
        'hard_constraints' => ['魔法不能复活死者'],
        'ending_contract' => ['main_conflict_resolution' => '雾潮源头被关闭'],
    ]);
    $character = Character::factory()->for($novel)->create([
        'name' => '沈澜',
        'status' => CharacterStatus::Active,
        'current_state' => [
            'location' => '旧港',
            'relationships' => ['沈澜:顾舟' => ['type' => 'ally']],
        ],
    ]);
    $location = WorldEntity::factory()->for($novel)->create([
        'name' => '旧港',
        'type' => WorldEntityType::Location,
        'status' => WorldEntityStatus::Active,
        'current_state' => ['weather' => '浓雾'],
    ]);
    $item = WorldEntity::factory()->for($novel)->create([
        'name' => '潮汐罗盘',
        'type' => WorldEntityType::Item,
    ]);
    $rule = WorldEntity::factory()->for($novel)->create([
        'name' => '雾潮法则',
        'type' => WorldEntityType::Rule,
        'rules' => ['entry' => '钟响后不可入海'],
    ]);
    $foreshadowing = Foreshadowing::factory()->for($novel)->create([
        'title' => '破损罗盘',
        'status' => ForeshadowingStatus::Reinforced,
        'importance' => ForeshadowingImportance::Critical,
        'reinforce_count' => 2,
        'due_from_chapter' => 10,
        'due_to_chapter' => 20,
    ]);

    $state = app(InitializeNovelStateAction::class)->handle($novel)->state;

    expect($state)->toHaveKeys([
        'schema_version',
        'characters',
        'relationships',
        'locations',
        'items',
        'world',
        'timeline',
        'open_threads',
        'foreshadowings',
        'reader_promises',
    ])
        ->and($state['characters'][(string) $character->getKey()])->toMatchArray([
            'name' => '沈澜',
            'status' => 'active',
            'location' => '旧港',
        ])
        ->and($state['relationships']['沈澜:顾舟']['type'])->toBe('ally')
        ->and($state['locations'][(string) $location->getKey()]['current_state'])->toBe(['weather' => '浓雾'])
        ->and($state['items'])->toHaveKey((string) $item->getKey())
        ->and($state['world']['entities'])->toHaveKey((string) $rule->getKey())
        ->and($state['world']['hard_constraints'])->toBe(['魔法不能复活死者'])
        ->and($state['foreshadowings'][(string) $foreshadowing->getKey()])->toMatchArray([
            'status' => 'reinforced',
            'importance' => 'critical',
            'reinforce_count' => 2,
            'due_from' => 10,
            'due_to' => 20,
        ])
        ->and($state['reader_promises'])->toBe(['main_conflict_resolution' => '雾潮源头被关闭']);
});

test('state zero checksum matches its recursively canonicalized state', function () {
    $novel = Novel::factory()->create();
    Character::factory()->for($novel)->create([
        'current_state' => ['zeta' => 1, 'alpha' => ['last' => true, 'first' => true]],
    ]);
    $stateVersion = app(InitializeNovelStateAction::class)->handle($novel);

    $sortKeys = function (array $value) use (&$sortKeys): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $sortKeys($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    };
    $expected = hash('sha256', json_encode(
        $sortKeys($stateVersion->state),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ));

    expect($stateVersion->checksum)->toBe($expected);
});

test('story state versions are immutable', function () {
    $stateVersion = StoryStateVersion::factory()->create();

    expect(fn () => $stateVersion->update(['checksum' => str_repeat('a', 64)]))
        ->toThrow(LogicException::class, '不可原地修改')
        ->and(fn () => $stateVersion->delete())
        ->toThrow(LogicException::class, '不可删除');
});

test('the database rejects duplicate novel versions', function () {
    $stateVersion = StoryStateVersion::factory()->create();

    expect(fn () => StoryStateVersion::factory()->for($stateVersion->novel)->create(['version' => 0]))
        ->toThrow(QueryException::class);
});

test('the story state migration can be rolled back', function () {
    expect(Schema::hasTable('story_state_versions'))->toBeTrue()
        ->and(Schema::hasColumn('novels', 'canonical_state_version_id'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_160000_create_story_state_versions_table.php');
    $migration->down();

    expect(Schema::hasTable('story_state_versions'))->toBeFalse()
        ->and(Schema::hasColumn('novels', 'canonical_state_version_id'))->toBeFalse();
});
