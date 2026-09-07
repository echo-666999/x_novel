<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Data\ContextRequest;
use App\Enums\FactStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryStateVersion;
use App\Models\WorldEntity;
use App\Services\ContextBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function contextFixture(): array
{
    $novel = Novel::factory()->create();
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    $bible = NovelBible::factory()->for($novel)->create([
        'version' => 3,
        'hard_constraints' => ['魔法不能复活死者'],
        'ending_contract' => ['主角必须回到故乡'],
    ]);
    $chapter = Chapter::factory()->for($novel)->create();
    $character = Character::factory()->for($novel)->create();
    $lockedFact = Fact::factory()->for($novel)->create([
        'subject_type' => 'character',
        'subject_id' => $character->getKey(),
        'predicate' => 'can_swim',
        'value' => ['value' => false],
        'locked' => true,
        'status' => FactStatus::Active,
    ]);
    $world = WorldEntity::factory()->for($novel)->create([
        'name' => '潮汐门',
        'rules' => ['只能在月落时开启'],
        'locked_fields' => ['rules'],
    ]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'pov_character_id' => $character->getKey(),
        'must_reveal' => ['潮汐门仍然存在'],
        'must_not_reveal' => ['守门人的真名'],
        'required_facts' => [$lockedFact->getKey()],
        'forbidden_conflicts' => ['角色不得突然会游泳'],
    ]);

    return compact('novel', 'state', 'bible', 'chapter', 'character', 'lockedFact', 'world', 'plan');
}

test('context builder freezes l0 and the requested canonical l1 with trace metadata', function () {
    $fixture = contextFixture();
    $snapshot = app(ContextBuilder::class)->build(new ContextRequest(
        novelId: $fixture['novel']->getKey(),
        chapterId: $fixture['chapter']->getKey(),
        sceneId: null,
        taskType: 'scene_generation',
        stateVersion: 0,
        chapterPlanId: $fixture['plan']->getKey(),
        tokenBudget: 10_000,
        promptVersion: 'scene-writer-v1',
        model: 'writer-test',
    ));
    $data = $snapshot->toArray();

    expect($data['schema_version'])->toBe(1)
        ->and($data['bible_version'])->toBe(3)
        ->and($data['state_version'])->toBe(0)
        ->and($data['chapter_plan_id'])->toBe($fixture['plan']->getKey())
        ->and($data['prompt_version'])->toBe('scene-writer-v1')
        ->and($data['model'])->toBe('writer-test')
        ->and($data['l0']['bible_hard_constraints'])->toBe(['魔法不能复活死者'])
        ->and($data['l0']['ending_contract'])->toBe(['主角必须回到故乡'])
        ->and($data['l0']['locked_and_required_facts'][0]['id'])->toBe($fixture['lockedFact']->getKey())
        ->and($data['l0']['world_rules'][0]['id'])->toBe($fixture['world']->getKey())
        ->and($data['l1']['canonical_story_state'])->toBe($fixture['state']->state)
        ->and($data['fact_ids'])->toBe([$fixture['lockedFact']->getKey()])
        ->and($data['character_ids'])->toContain($fixture['character']->getKey())
        ->and($data['world_entity_ids'])->toBe([$fixture['world']->getKey()])
        ->and($data['memory_ids'])->toBe([])
        ->and($data['recent_chapter_ids'])->toBe([])
        ->and($data['token_allocation']['sections'])->toHaveKeys(['l0', 'l1'])
        ->and($data['truncated_sections'])->toBe([]);
});

test('l1 comes from the requested immutable state version instead of projections', function () {
    $fixture = contextFixture();
    StoryStateVersion::factory()->for($fixture['novel'])->create([
        'version' => 1,
        'state' => ['timeline' => ['later']],
    ]);
    $fixture['character']->update(['current_state' => ['location' => '错误投影']]);

    $snapshot = app(ContextBuilder::class)->build(new ContextRequest(
        novelId: $fixture['novel']->getKey(), chapterId: $fixture['chapter']->getKey(), sceneId: null,
        taskType: 'scene_generation', stateVersion: 0, chapterPlanId: $fixture['plan']->getKey(),
        tokenBudget: 10_000, promptVersion: 'scene-writer-v1', model: 'writer-test',
    ));

    expect($snapshot->l1['canonical_story_state'])->toBe($fixture['state']->state)
        ->not->toHaveKey('characters.0.current_state');
});

test('context builder rejects chapter plans from another novel', function () {
    $fixture = contextFixture();
    $foreignPlan = ChapterPlan::factory()->create();

    app(ContextBuilder::class)->build(new ContextRequest(
        novelId: $fixture['novel']->getKey(), chapterId: $fixture['chapter']->getKey(), sceneId: null,
        taskType: 'scene_generation', stateVersion: 0, chapterPlanId: $foreignPlan->getKey(),
        tokenBudget: 10_000, promptVersion: 'scene-writer-v1', model: 'writer-test',
    ));
})->throws(ModelNotFoundException::class);
