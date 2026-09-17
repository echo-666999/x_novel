<?php

use App\Enums\CharacterStatus;
use App\Enums\FactHardness;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\NovelStatus;
use App\Enums\PlanFindingSeverity;
use App\Enums\StoryArcStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Models\StoryArc;
use App\Models\StoryStateVersion;
use App\Models\Volume;
use App\Models\WorldEntity;
use App\Services\PlanValidator;
use App\Services\StoryArcBeatContract;
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

test('structured arc beats and world candidates are scoped to the current novel and volume', function () {
    $novel = Novel::factory()->create();
    $volume = Volume::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->for($volume)->create(['sequence' => 10]);
    $arc = StoryArc::factory()->for($novel)->forVolume($volume)->create([
        'status' => StoryArcStatus::Active,
        'beats' => ['取得通行证'],
    ]);
    $beatKey = app(StoryArcBeatContract::class)->key('取得通行证');
    $plan = validPlan([
        'chapter' => $chapter,
        'arc_contributions' => [[
            'arc_id' => $arc->getKey(), 'beat_key' => $beatKey, 'beat_index' => 1,
            'target_scene_sequence' => 1, 'acceptance_criteria' => '正文明确取得通行证。',
        ]],
        'world_entity_candidates' => [[
            'candidate_key' => 'wec-north-pass', 'type' => 'item', 'name' => '北门通行证',
            'description' => '进入北门的凭证。', 'deduplication_basis' => '现有实体没有同名凭证。',
            'possible_duplicate_entity_ids' => [], 'introduction_reason' => '进入下一地点。', 'target_scene_sequence' => 1,
        ]],
    ]);

    expect(app(PlanValidator::class)->validate($plan)->canGenerate())->toBeTrue();

    $foreignArc = StoryArc::factory()->create(['status' => StoryArcStatus::Active, 'beats' => ['错误 Beat']]);
    $existing = WorldEntity::factory()->for($novel)->create(['name' => '北门通行证']);
    $plan->update([
        'arc_contributions' => [[
            'arc_id' => $foreignArc->getKey(), 'beat_key' => 'beat-invalid', 'beat_index' => 1,
            'target_scene_sequence' => 1, 'acceptance_criteria' => '错误引用。',
        ]],
        'world_entity_candidates' => [[
            'candidate_key' => 'wec-north-pass', 'type' => 'rule', 'name' => $existing->name,
            'description' => '冲突候选。', 'deduplication_basis' => '错误判断。',
            'possible_duplicate_entity_ids' => [$existing->getKey()], 'introduction_reason' => '测试。', 'target_scene_sequence' => 1,
        ]],
    ]);
    $codes = collect(app(PlanValidator::class)->validate($plan->fresh())->findings)->pluck('code');

    expect($codes)->toContain('INVALID_ARC_REFERENCE', 'WORLD_ENTITY_TYPE_CONFLICT');
});

test('duplicate requirements across scenes are rejected before writing', function () {
    $plan = validPlan(['scene_plans' => [
        [
            'goal' => '等待审核结果',
            'conflict' => '监管人员拒绝放行',
            'turn' => '收到补充材料要求',
            'outcome' => '继续等待审核',
            'outcome_allowed' => [],
            'outcome_forbidden' => [],
            'continuity_requirements' => [],
            'transition_from_previous' => null,
        ],
        [
            'goal' => '等待审核结果',
            'conflict' => '伤势限制行动',
            'turn' => '主角改为提交书面说明',
            'outcome' => '审核进入复核阶段',
            'outcome_allowed' => [],
            'outcome_forbidden' => [],
            'continuity_requirements' => [],
            'transition_from_previous' => '承接上一场的补充材料要求。',
        ],
    ]]);

    $result = app(PlanValidator::class)->validate($plan);

    expect(collect($result->findings)->pluck('code'))->toContain('DUPLICATE_CROSS_SCENE_REQUIREMENT')
        ->and($result->canGenerate())->toBeFalse();
});

test('structured continuity requirements preserve establishment persistence change and callback order', function () {
    $plan = validPlan(['scene_plans' => [
        [
            'goal' => '提交报告', 'conflict' => '材料不全', 'turn' => '找到编号', 'outcome' => '报告受理',
            'outcome_allowed' => [], 'outcome_forbidden' => [], 'transition_from_previous' => null,
            'continuity_requirements' => [['key' => 'injury', 'mode' => 'establish', 'description' => '首次交代左肩受伤。']],
        ],
        [
            'goal' => '接受询问', 'conflict' => '无法久坐', 'turn' => '改为站立陈述', 'outcome' => '询问完成',
            'outcome_allowed' => [], 'outcome_forbidden' => [], 'transition_from_previous' => '承接报告受理。',
            'continuity_requirements' => [['key' => 'injury', 'mode' => 'persist', 'description' => '只写伤势对本场动作的新影响。']],
        ],
        [
            'goal' => '离开大厅', 'conflict' => '伤口裂开', 'turn' => '同伴包扎', 'outcome' => '伤势稳定',
            'outcome_allowed' => [], 'outcome_forbidden' => [], 'transition_from_previous' => '承接询问结束。',
            'continuity_requirements' => [
                ['key' => 'injury', 'mode' => 'change', 'description' => '伤口裂开后完成包扎。'],
                ['key' => 'injury', 'mode' => 'callback', 'description' => '章末只回扣包扎后的稳定状态。'],
            ],
        ],
    ]]);

    $result = app(PlanValidator::class)->validate($plan);

    expect(collect($result->findings)->pluck('code'))
        ->not->toContain('DUPLICATE_CROSS_SCENE_REQUIREMENT', 'INVALID_CONTINUITY_REQUIREMENT', 'CONTINUITY_REQUIREMENT_NOT_ESTABLISHED', 'CONTINUITY_CALLBACK_NOT_AT_CHAPTER_END');
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
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 8,
        'due_to_chapter' => 12,
    ]);
    Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'title' => '旧日钟声',
        'importance' => ForeshadowingImportance::Medium,
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 9,
        'due_to_chapter' => 11,
    ]);

    $result = app(PlanValidator::class)->validate($plan);
    $codes = collect($result->findings)->pluck('code');

    expect($result->status())->toBe(PlanFindingSeverity::Blocked)
        ->and($codes)->toContain('CRITICAL_DUE_FORESHADOWING_MISSING', 'DUE_FORESHADOWING_MISSING');
});

test('foreshadowing actions reject foreign and terminal references', function () {
    $plan = validPlan();
    $foreign = Foreshadowing::factory()->create(['status' => ForeshadowingStatus::Planted]);
    $terminal = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'status' => ForeshadowingStatus::PaidOff,
    ]);
    $plan->update(['foreshadowing_actions' => collect([$foreign, $terminal])
        ->map(fn (Foreshadowing $foreshadowing): array => [
            'foreshadowing_id' => $foreshadowing->getKey(),
            'action' => 'reinforce',
            'target_scene_sequence' => 1,
            'acceptance_criteria' => '不应接受此引用。',
            'reason' => null,
        ])
        ->all()]);

    $codes = collect(app(PlanValidator::class)->validate($plan->fresh())->findings)->pluck('code');

    expect($codes->filter(fn (string $code): bool => $code === 'INVALID_FORESHADOWING_REFERENCE'))->toHaveCount(2);
});

test('legacy foreshadowing ids remain readable but do not satisfy critical action coverage', function () {
    $plan = validPlan();
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 8,
        'due_to_chapter' => 12,
    ]);
    $plan->update(['due_foreshadowings' => [$foreshadowing->getKey()]]);

    $codes = collect(app(PlanValidator::class)->validate($plan->fresh())->findings)->pluck('code');

    expect($plan->fresh()->legacyForeshadowingIds())->toBe([$foreshadowing->getKey()])
        ->and($codes)->toContain('LEGACY_FORESHADOWING_REFERENCES', 'CRITICAL_DUE_FORESHADOWING_MISSING');
});

test('including a valid due foreshadowing action clears its findings', function () {
    $plan = validPlan();
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 8,
        'due_to_chapter' => 12,
    ]);
    $plan->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'reinforce',
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文再次呈现该线索，并改变角色本章判断。',
        'reason' => null,
    ]]]);

    $result = app(PlanValidator::class)->validate($plan->fresh());

    expect($result->status())->toBe(PlanFindingSeverity::Valid);
});

test('an idea foreshadowing cannot skip directly to reinforce', function () {
    $plan = validPlan();
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'status' => ForeshadowingStatus::Idea,
        'due_from_chapter' => 8,
        'due_to_chapter' => 12,
    ]);
    $plan->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'reinforce',
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文强化该线索。',
        'reason' => null,
    ]]]);

    $codes = collect(app(PlanValidator::class)->validate($plan->fresh())->findings)->pluck('code');

    expect($codes)->toContain('INVALID_FORESHADOWING_LIFECYCLE');
});

test('foreshadowing lifecycle validation prefers canonical state over a stale projection', function () {
    $plan = validPlan();
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'status' => ForeshadowingStatus::Idea,
        'due_from_chapter' => 8,
        'due_to_chapter' => 12,
    ]);
    $state = StoryStateVersion::factory()->for($plan->chapter->novel)->create([
        'version' => 1,
        'state' => [
            'foreshadowings' => [
                (string) $foreshadowing->getKey() => ['status' => ForeshadowingStatus::Planted->value],
            ],
        ],
    ]);
    $plan->chapter->novel->update(['canonical_state_version_id' => $state->getKey()]);
    $plan->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'reinforce',
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文进一步强化已经正式铺设的线索。',
        'reason' => null,
    ]]]);

    $result = app(PlanValidator::class)->validate($plan->fresh());

    expect($result->canGenerate())->toBeTrue()
        ->and(collect($result->findings)->pluck('code'))->not->toContain('INVALID_FORESHADOWING_LIFECYCLE');
});

test('an idea may be planted and paid off in order within the same chapter', function () {
    $plan = validPlan();
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Idea,
        'due_from_chapter' => 10,
        'due_to_chapter' => 10,
    ]);
    $plan->update(['foreshadowing_actions' => [
        [
            'foreshadowing_id' => $foreshadowing->getKey(),
            'action' => 'plant',
            'target_scene_sequence' => 1,
            'acceptance_criteria' => '正文先建立可识别的线索。',
            'reason' => null,
        ],
        [
            'foreshadowing_id' => $foreshadowing->getKey(),
            'action' => 'pay_off',
            'target_scene_sequence' => 1,
            'acceptance_criteria' => '正文随后揭示线索答案及其后果。',
            'reason' => null,
        ],
    ]]);

    expect(app(PlanValidator::class)->validate($plan->fresh())->canGenerate())->toBeTrue();
});

test('a critical foreshadowing cannot use reinforce at its payoff deadline', function () {
    $plan = validPlan();
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 8,
        'due_to_chapter' => 10,
    ]);
    $plan->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'reinforce',
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文再次出现线索。',
        'reason' => null,
    ]]]);

    $codes = collect(app(PlanValidator::class)->validate($plan->fresh())->findings)->pluck('code');

    expect($codes)->toContain('CRITICAL_FORESHADOWING_DEADLINE_REQUIRES_RESOLUTION');
});

test('defer and abandon require current manual authorization metadata', function (string $action) {
    $plan = validPlan();
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 8,
        'due_to_chapter' => 12,
    ]);
    $plan->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => $action,
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文遵守人工处置结果。',
        'reason' => '用户决定调整该伏笔。',
        'new_due_from_chapter' => 13,
        'new_due_to_chapter' => 16,
    ]]]);

    $codes = collect(app(PlanValidator::class)->validate($plan->fresh())->findings)->pluck('code');

    expect($codes)->toContain('FORESHADOWING_ACTION_REQUIRES_USER_AUTHORIZATION');
})->with(['defer', 'abandon']);

test('an overdue critical foreshadowing accepts an explicit payoff repair contract', function () {
    $plan = validPlan();
    $foreshadowing = Foreshadowing::factory()->for($plan->chapter->novel)->create([
        'importance' => ForeshadowingImportance::Critical,
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 5,
        'due_to_chapter' => 9,
    ]);
    $plan->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'pay_off',
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文明确揭示承诺答案并让角色据此采取行动。',
        'reason' => null,
    ]]]);

    $result = app(PlanValidator::class)->validate($plan->fresh());

    expect($result->canGenerate())->toBeTrue()
        ->and(collect($result->findings)->pluck('code'))->not->toContain('CRITICAL_OVERDUE_REPAIR_REQUIRED');
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
    $plan->update(['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => 'plant',
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文首次明确建立该伏笔。',
        'reason' => null,
    ]]]);

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
