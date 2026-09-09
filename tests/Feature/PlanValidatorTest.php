<?php

use App\Enums\CharacterStatus;
use App\Enums\FactHardness;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\NovelStatus;
use App\Enums\PlanFindingSeverity;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Services\PlanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function validPlan(array $attributes = []): ChapterPlan
{
    $chapter = $attributes['chapter'] ?? Chapter::factory()->create(['sequence' => 10]);
    unset($attributes['chapter']);
    $pov = Character::factory()->for($chapter->novel)->create();

    return ChapterPlan::factory()->for($chapter)->create([
        'pov_character_id' => $pov->getKey(),
        ...$attributes,
    ]);
}

test('a structurally valid plan returns valid and can enter generation', function () {
    $result = app(PlanValidator::class)->validate(validPlan());

    expect($result->status())->toBe(PlanFindingSeverity::Valid)
        ->and($result->findings)->toBe([])
        ->and($result->canGenerate())->toBeTrue();

    $result->assertCanGenerate();
});

test('invalid entity references and a deceased pov block a plan', function () {
    $plan = validPlan();
    $deceased = Character::factory()->for($plan->chapter->novel)->create([
        'status' => CharacterStatus::Deceased,
    ]);
    $foreignCharacter = Character::factory()->create();
    $foreignFact = Fact::factory()->create();
    $plan->update([
        'pov_character_id' => $deceased->getKey(),
        'required_facts' => [$foreignFact->getKey()],
        'scene_plans' => [[
            'goal' => '目标',
            'conflict' => '冲突',
            'turn' => '转折',
            'outcome' => '结果',
            'pov_character_id' => $foreignCharacter->getKey(),
        ]],
    ]);

    $result = app(PlanValidator::class)->validate($plan->fresh());
    $codes = collect($result->findings)->pluck('code');

    expect($result->status())->toBe(PlanFindingSeverity::Blocked)
        ->and($result->canGenerate())->toBeFalse()
        ->and($codes)->toContain('DECEASED_POV', 'INVALID_POV_REFERENCE', 'INVALID_FACT_REFERENCE');
});

test('an inactive pov produces a warning without blocking generation', function () {
    $plan = validPlan();
    $inactive = Character::factory()->for($plan->chapter->novel)->create([
        'status' => CharacterStatus::Inactive,
    ]);
    $plan->update(['pov_character_id' => $inactive->getKey()]);

    $result = app(PlanValidator::class)->validate($plan->fresh());

    expect($result->status())->toBe(PlanFindingSeverity::Warning)
        ->and($result->canGenerate())->toBeTrue()
        ->and(collect($result->findings)->pluck('code'))->toContain('INACTIVE_POV');
});

test('a chapter after a canonical chapter requires an explicit opening transition', function () {
    $novel = Novel::factory()->create();
    Chapter::factory()->for($novel)->create(['sequence' => 1, 'status' => 'canonical']);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 2]);
    $plan = validPlan([
        'chapter' => $chapter,
        'scene_plans' => [[
            'goal' => '进入学院',
            'conflict' => '守门人盘问',
            'turn' => '导师出面',
            'outcome' => '获得临时宿舍',
            'transition_from_previous' => null,
        ]],
    ]);

    $result = app(PlanValidator::class)->validate($plan);

    expect(collect($result->findings)->pluck('code'))->toContain('MISSING_CHAPTER_TRANSITION')
        ->and($result->canGenerate())->toBeFalse();

    $plan->update(['scene_plans' => [[
        'goal' => '进入学院',
        'conflict' => '守门人盘问',
        'turn' => '导师出面',
        'outcome' => '获得临时宿舍',
        'transition_from_previous' => '承接走向学院的结尾，写出入院登记和入住，再过渡到次日醒来。',
    ]]]);

    expect(collect(app(PlanValidator::class)->validate($plan->fresh())->findings)->pluck('code'))
        ->not->toContain('MISSING_CHAPTER_TRANSITION');
});

test('a required fact conflicting with a locked fact blocks a plan', function () {
    $plan = validPlan();
    $character = $plan->povCharacter;
    $locked = Fact::factory()->for($plan->chapter->novel)->create([
        'subject_id' => $character->getKey(),
        'predicate' => 'can_swim',
        'value' => ['value' => false],
        'hardness' => FactHardness::Hard,
        'locked' => true,
    ]);
    $required = Fact::factory()->for($plan->chapter->novel)->create([
        'subject_id' => $character->getKey(),
        'predicate' => 'can_swim',
        'value' => ['value' => true],
        'locked' => false,
    ]);
    $plan->update(['required_facts' => [$required->getKey()]]);

    $result = app(PlanValidator::class)->validate($plan->fresh());

    expect($locked->locked)->toBeTrue()
        ->and(collect($result->findings)->pluck('code'))->toContain('LOCKED_FACT_CONFLICT')
        ->and($result->canGenerate())->toBeFalse();
});

test('knowledge conflicts use the dedicated blocking finding', function () {
    $plan = validPlan();
    $character = $plan->povCharacter;
    $character->update(['knowledge' => ['knows_secret' => false]]);
    $required = Fact::factory()->for($plan->chapter->novel)->create([
        'subject_id' => $character->getKey(),
        'predicate' => 'knows_secret',
        'value' => ['value' => true],
    ]);
    $plan->update(['required_facts' => [$required->getKey()]]);

    $result = app(PlanValidator::class)->validate($plan->fresh());

    expect(collect($result->findings)->pluck('code'))->toContain('KNOWLEDGE_CONFLICT')
        ->and($result->canGenerate())->toBeFalse();
});

test('a missing critical due foreshadowing blocks while a normal due item warns', function () {
    $plan = validPlan();
    Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'title' => '王冠裂痕',
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Due,
        'due_from_chapter' => 8,
        'due_to_chapter' => 12,
    ]);
    Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'title' => '旧日钟声',
        'importance' => ForeshadowingImportance::Medium,
        'status' => ForeshadowingStatus::Due,
        'due_from_chapter' => 9,
        'due_to_chapter' => 11,
    ]);

    $result = app(PlanValidator::class)->validate($plan);
    $codes = collect($result->findings)->pluck('code');

    expect($result->status())->toBe(PlanFindingSeverity::Blocked)
        ->and($codes)->toContain('CRITICAL_DUE_FORESHADOWING_MISSING', 'DUE_FORESHADOWING_MISSING');
});

test('including due foreshadowings clears their findings', function () {
    $plan = validPlan();
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Due,
        'due_from_chapter' => 8,
        'due_to_chapter' => 12,
    ]);
    $plan->update(['due_foreshadowings' => [$foreshadowing->getKey()]]);

    $result = app(PlanValidator::class)->validate($plan->fresh());

    expect($result->status())->toBe(PlanFindingSeverity::Valid);
});

test('completing novels cannot introduce a high importance idea foreshadowing', function () {
    $plan = validPlan();
    $plan->chapter->novel->update(['status' => NovelStatus::Completing]);
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'importance' => ForeshadowingImportance::High,
        'status' => ForeshadowingStatus::Idea,
        'due_from_chapter' => 20,
        'due_to_chapter' => 25,
    ]);
    $plan->update(['due_foreshadowings' => [$foreshadowing->getKey()]]);

    $result = app(PlanValidator::class)->validate($plan->fresh());

    expect(collect($result->findings)->pluck('code'))
        ->toContain('COMPLETING_RESTRICTIONS_ACTIVE', 'COMPLETING_NEW_FORESHADOWING')
        ->and($result->canGenerate())->toBeFalse();
});

test('the generation assertion reports every blocking finding', function () {
    $plan = validPlan(['pov_character_id' => null, 'scene_plans' => []]);
    $result = app(PlanValidator::class)->validate($plan);

    expect(fn () => $result->assertCanGenerate())
        ->toThrow(ValidationException::class);
});
