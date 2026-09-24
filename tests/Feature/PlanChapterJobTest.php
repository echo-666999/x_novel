<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\ArtifactType;
use App\Enums\BibleStatus;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelOutlineStatus;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Exceptions\GenerationPreflightException;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\NovelOutline;
use App\Models\StoryArc;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\Volume;
use App\Services\ChapterPlanner;
use App\Services\ChapterPlanPayload;
use App\Services\NovelOutlineChecksum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function plannerChapter(): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create(['version' => 1]);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create(['sequence' => 1]);
    $character = Character::factory()->for($novel)->create();

    return [$chapter, $character];
}

function plannerPayload(int $characterId, array $overrides = []): array
{
    return [
        'novel_outline_id' => null,
        'chapter_function' => '迫使主角离开安全区',
        'arc_contribution' => '推进失踪船队主线',
        'arc_contributions' => [],
        'character_candidates' => [],
        'reader_promise' => '揭示灯塔的第一层秘密',
        'target_words' => 3000,
        'pov_character_id' => $characterId,
        'tone' => '紧张',
        'time_anchor' => '当日傍晚',
        'hook_type' => '悬念',
        'must_reveal' => ['灯塔仍在运转'],
        'may_hint' => [],
        'must_not_reveal' => ['幕后主使身份'],
        'required_facts' => [],
        'forbidden_conflicts' => [],
        'foreshadowing_actions' => [],
        'world_entity_candidates' => [],
        'scene_plans' => [[
            'goal' => '取得出港许可',
            'conflict' => '港务官拒绝放行',
            'turn' => '潮汐钟提前响起',
            'outcome' => '主角决定偷船',
            'outcome_allowed' => ['寻找无人看守的小船'],
            'outcome_forbidden' => ['取得港务官正式许可'],
            'continuity_requirements' => [],
            'pov_character_id' => $characterId,
            'location' => '旧港',
            'time_anchor' => '黄昏',
            'transition_from_previous' => null,
        ]],
        ...$overrides,
    ];
}

function plannerResponse(array $payload): AiResponse
{
    return new AiResponse(
        content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        structuredData: $payload,
        inputTokens: 100,
        outputTokens: 200,
        cachedTokens: 0,
        latencyMs: 50,
        providerRequestId: fake()->uuid(),
        model: 'planner-test',
    );
}

function plannerOutlineContent(int $maximum = 2): array
{
    return [
        'title' => '章节规划测试大纲',
        'summary' => '按顺序取得地图并穿过城门。',
        'must_include' => [],
        'must_not_include' => [],
        'baseline_completions' => [],
        'volumes' => [[
            'key' => 'volume-one', 'sequence' => 1, 'title' => '启程篇', 'goal' => '离开旧城', 'climax' => '穿过城门', 'target_words' => 100000,
            'arcs' => [[
                'key' => 'arc-departure', 'sequence' => 1, 'type' => 'main', 'title' => '启程主线', 'goal' => '主角离开旧城', 'stakes' => '被困在旧城',
                'completion_conditions' => ['主角穿过城门'],
                'beats' => [[
                    'key' => 'beat-map', 'sequence' => 1, 'title' => '取得地图', 'summary' => '主角取得可靠地图。',
                    'chapter_budget' => ['min' => 1, 'max' => $maximum], 'acceptance_criteria' => ['主角取得真实地图'],
                    'must_include' => ['地图来源可验证'], 'must_not_include' => ['直接穿过城门'],
                    'character_candidates' => [], 'world_entity_candidates' => [],
                ], [
                    'key' => 'beat-gate', 'sequence' => 2, 'title' => '穿过城门', 'summary' => '主角付出代价穿过城门。',
                    'chapter_budget' => ['min' => 1, 'max' => 2], 'acceptance_criteria' => ['主角穿过城门'],
                    'must_include' => ['通行代价'], 'must_not_include' => ['无代价通行'],
                    'character_candidates' => [], 'world_entity_candidates' => [],
                ]],
            ]],
        ]],
    ];
}

function outlinePlannerChapter(int $maximum = 2): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    NovelBible::factory()->for($novel)->create(['version' => 1]);
    $content = plannerOutlineContent($maximum);
    $outline = NovelOutline::factory()->for($novel)->create([
        'status' => NovelOutlineStatus::Current,
        'content' => $content,
        'checksum' => app(NovelOutlineChecksum::class)->for($content),
        'applied_at' => now(),
    ]);
    $novel->update(['current_outline_id' => $outline->getKey()]);
    $volume = Volume::factory()->for($novel)->create([
        'outline_key' => 'volume-one',
        'status' => VolumeStatus::Active,
    ]);
    $arc = StoryArc::factory()->forVolume($volume)->create([
        'outline_key' => 'arc-departure',
        'status' => 'active',
        'beats' => data_get($content, 'volumes.0.arcs.0.beats'),
    ]);
    $character = Character::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create(['sequence' => 1]);

    return [$chapter, $character, $outline, $volume, $arc];
}

function outlinePlannerPayload(Character $character, NovelOutline $outline, StoryArc $arc, string $beatKey = 'beat-map', int $beatSequence = 1): array
{
    $required = $beatKey === 'beat-map' ? '地图来源可验证' : '通行代价';
    $forbidden = $beatKey === 'beat-map' ? '直接穿过城门' : '无代价通行';
    $criteria = $beatKey === 'beat-map' ? '主角取得真实地图' : '主角穿过城门';

    return plannerPayload($character->getKey(), [
        'novel_outline_id' => $outline->getKey(),
        'arc_contributions' => [[
            'role' => 'primary',
            'arc_id' => $arc->getKey(),
            'beat_key' => $beatKey,
            'beat_index' => $beatSequence,
            'target_scene_sequence' => 1,
            'acceptance_criteria' => $criteria,
        ]],
        'must_reveal' => [$required],
        'must_not_reveal' => [$forbidden],
    ]);
}

test('the chapter plan response schema requires every declared scene field', function () {
    $schema = ChapterPlanPayload::schema();
    $sceneSchema = ChapterPlanPayload::schema()['properties']['scene_plans']['items'];
    $foreshadowingSchema = ChapterPlanPayload::schema()['properties']['foreshadowing_actions']['items'];
    $arcSchema = ChapterPlanPayload::schema()['properties']['arc_contributions']['items'];

    expect($sceneSchema['required'])
        ->toEqualCanonicalizing(array_keys($sceneSchema['properties']))
        ->and($sceneSchema['properties']['pov_character_id']['type'])->toContain('null')
        ->and($sceneSchema['properties']['location']['type'])->toContain('null')
        ->and($sceneSchema['properties']['time_anchor']['type'])->toContain('null')
        ->and($foreshadowingSchema['required'])->toEqualCanonicalizing(array_keys($foreshadowingSchema['properties']))
        ->and($arcSchema['required'])->toEqualCanonicalizing(array_keys($arcSchema['properties']))
        ->and($arcSchema['properties']['role']['enum'])->toBe(['primary', 'secondary'])
        ->and($foreshadowingSchema['properties']['action']['enum'])->toBe(['plant', 'reinforce', 'pay_off'])
        ->and(data_get($schema, 'properties.character_candidates.items.properties.profile.type'))->toBe('array')
        ->and(data_get($schema, 'properties.character_candidates.items.properties.personality.type'))->toBe('array')
        ->and(data_get($schema, 'properties.character_candidates.items.properties.abilities.type'))->toBe('array')
        ->and(data_get($schema, 'properties.character_candidates.items.properties.knowledge.type'))->toBe('array');
});

test('the planner freezes the earliest unfinished outline beat and source version', function () {
    [$chapter, $character, $outline, , $arc] = outlinePlannerChapter();
    $payload = outlinePlannerPayload($character, $outline, $arc);
    $fake = (new FakeAiProvider)->enqueue(plannerResponse($payload));
    app()->instance(AiProvider::class, $fake);

    $plan = app(ChapterPlanner::class)->generate($chapter->getKey());
    $snapshot = $chapter->generationRuns()->sole()->context_snapshot;

    expect($plan?->novel_outline_id)->toBe($outline->getKey())
        ->and(data_get($plan?->arc_contributions, '0.role'))->toBe('primary')
        ->and(data_get($snapshot, 'novel_outline_id'))->toBe($outline->getKey())
        ->and(data_get($snapshot, 'outline_version'))->toBe(1)
        ->and(data_get($snapshot, 'outline_checksum'))->toBe($outline->checksum)
        ->and(data_get($snapshot, 'primary_arc_id'))->toBe($arc->getKey())
        ->and(data_get($snapshot, 'primary_beat_key'))->toBe('beat-map')
        ->and(data_get($snapshot, 'chapter_budget'))->toBe(['min' => 1, 'max' => 2])
        ->and(data_get($snapshot, 'current_outline_target.beat.key'))->toBe('beat-map')
        ->and($arc->fresh()->progress)->toBe(0.0)
        ->and($chapter->novel->storyEvents()->count())->toBe(0)
        ->and($chapter->novel->canonicalStateVersion()->value('version'))->toBe(0)
        ->and($chapter->novel->fresh()->current_outline_id)->toBe($outline->getKey())
        ->and($fake->requests())->toHaveCount(1);
});

test('the planner restores authoritative outline constraints before validating the model plan', function () {
    [$chapter, $character, $outline, , $arc] = outlinePlannerChapter();
    $payload = outlinePlannerPayload($character, $outline, $arc);
    $payload['novel_outline_id'] = $outline->getKey() + 100;
    $payload['arc_contributions'][0]['arc_id'] = $arc->getKey() + 100;
    $payload['arc_contributions'][0]['beat_key'] = 'model-rewritten-beat';
    $payload['arc_contributions'][0]['beat_index'] = 99;
    $payload['must_reveal'] = [
        '地图来源可验证：需要在正文中交代证据链。',
        '模型补充的本章揭示项',
    ];
    $payload['must_not_reveal'] = [
        '直接穿过城门。',
        '模型补充的禁止项',
    ];
    $fake = (new FakeAiProvider)->enqueue(plannerResponse($payload));
    app()->instance(AiProvider::class, $fake);

    $plan = app(ChapterPlanner::class)->generate($chapter->getKey());

    expect($plan->novel_outline_id)->toBe($outline->getKey())
        ->and(data_get($plan->arc_contributions, '0.arc_id'))->toBe($arc->getKey())
        ->and(data_get($plan->arc_contributions, '0.beat_key'))->toBe('beat-map')
        ->and(data_get($plan->arc_contributions, '0.beat_index'))->toBe(1)
        ->and($plan->must_reveal)->toBe(['地图来源可验证', '模型补充的本章揭示项'])
        ->and($plan->must_not_reveal)->toBe(['直接穿过城门', '模型补充的禁止项']);
});

test('the planner advances to the next beat only after an active completion event', function () {
    [$chapter, $character, $outline, , $arc] = outlinePlannerChapter();
    StoryEvent::factory()->create([
        'novel_id' => $chapter->novel_id,
        'chapter_id' => $chapter->getKey(),
        'event_type' => EventType::StoryArcBeatCompleted,
        'subject_type' => 'story_arc',
        'subject_id' => (string) $arc->getKey(),
        'payload' => ['beat_key' => 'beat-map'],
    ]);
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(outlinePlannerPayload($character, $outline, $arc, 'beat-gate', 2)));
    app()->instance(AiProvider::class, $fake);

    $plan = app(ChapterPlanner::class)->generate($chapter->getKey());

    expect(data_get($plan?->arc_contributions, '0.beat_key'))->toBe('beat-gate')
        ->and(data_get($chapter->generationRuns()->sole()->context_snapshot, 'primary_beat_key'))->toBe('beat-gate')
        ->and(data_get($chapter->generationRuns()->sole()->context_snapshot, 'canonical_completed_beat_keys'))->toBe(['beat-map']);
});

test('an exhausted outline beat budget stops planning before a run or provider call', function () {
    [$chapter, $character, $outline, $volume, $arc] = outlinePlannerChapter(1);
    $canonical = Chapter::factory()->for($chapter->novel)->for($volume)->create([
        'sequence' => 0,
        'status' => ChapterStatus::Canonical,
    ]);
    ChapterPlan::factory()->for($canonical)->create([
        'novel_outline_id' => $outline->getKey(),
        'arc_contributions' => [[
            'role' => 'primary', 'arc_id' => $arc->getKey(), 'beat_key' => 'beat-map', 'beat_index' => 1,
            'target_scene_sequence' => 1, 'acceptance_criteria' => '主角取得真实地图',
        ]],
    ]);
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(outlinePlannerPayload($character, $outline, $arc)));
    app()->instance(AiProvider::class, $fake);

    try {
        app(ChapterPlanner::class)->generate($chapter->getKey());
        test()->fail('Expected outline budget gate to stop planning.');
    } catch (GenerationPreflightException $exception) {
        expect($exception->reason)->toBe('outline_beat_budget_exhausted')
            ->and($fake->requests())->toHaveCount(0)
            ->and($chapter->generationRuns()->count())->toBe(0)
            ->and($chapter->plans()->count())->toBe(0);
    }
});

test('outline retries and duplicate delivery keep the same frozen outline context', function () {
    config()->set('generation.planner_max_output_tokens', 12_000);
    config()->set('generation.planner_retry_max_output_tokens', 16_000);
    [$chapter, $character, $outline, , $arc] = outlinePlannerChapter();
    $payload = outlinePlannerPayload($character, $outline, $arc);
    $fake = (new FakeAiProvider)
        ->enqueue(new AiProviderException('provider_timeout', 'timeout', true))
        ->enqueue(plannerResponse($payload));
    app()->instance(AiProvider::class, $fake);
    $planner = app(ChapterPlanner::class);

    expect(fn () => $planner->generate($chapter->getKey()))->toThrow(AiProviderException::class, 'timeout');
    $plan = $planner->generate($chapter->getKey());
    $duplicate = $planner->generate($chapter->getKey());
    $runs = $chapter->generationRuns()->oldest('id')->get();

    expect($duplicate?->is($plan))->toBeTrue()
        ->and($fake->requests())->toHaveCount(2)
        ->and($fake->requests()[0]->maxTokens)->toBe(12_000)
        ->and($fake->requests()[1]->maxTokens)->toBe(16_000)
        ->and($runs)->toHaveCount(2)
        ->and($runs->pluck('context_snapshot')->pluck('outline_checksum')->unique()->all())->toBe([$outline->checksum])
        ->and(data_get($runs[0]->context_snapshot, 'generation_preferences.max_completion_tokens'))->toBe(12_000)
        ->and(data_get($runs[1]->context_snapshot, 'generation_preferences.max_completion_tokens'))->toBe(16_000)
        ->and($chapter->plans()->count())->toBe(1);
});

test('the model payload cannot authorize defer or abandon actions', function (string $action) {
    [$chapter, $character] = plannerChapter();
    $foreshadowing = Foreshadowing::factory()->for($chapter->novel)->create([
        'due_from_chapter' => 1,
        'due_to_chapter' => 2,
        'status' => ForeshadowingStatus::Planted,
    ]);
    $payload = plannerPayload($character->getKey(), ['foreshadowing_actions' => [[
        'foreshadowing_id' => $foreshadowing->getKey(),
        'action' => $action,
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '模型不得授权该动作。',
        'reason' => '模型生成的原因不能作为人工授权。',
    ]]]);

    expect(fn () => ChapterPlanPayload::validate($payload))->toThrow(ValidationException::class);
})->with(['defer', 'abandon']);

test('the planner creates a validated plan artifact and succeeds its run', function () {
    [$chapter, $character] = plannerChapter();
    $chapter->novel->update(['settings' => [
        'generation' => ['chapter_target_words' => 4_200],
        'editorial' => ['primary_style' => 'austere', 'secondary_styles' => [], 'style_parameters' => []],
    ]]);
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(plannerPayload($character->getKey())));
    app()->instance(AiProvider::class, $fake);

    app(ChapterPlanner::class)->generate($chapter->getKey());

    $plan = $chapter->plans()->sole();
    $run = $chapter->generationRuns()->sole();

    expect($plan->status)->toBe(PlanStatus::Ready)
        ->and($plan->novel_outline_id)->toBeNull()
        ->and($plan->scene_plans)->toHaveCount(1)
        ->and(data_get($plan->scene_plans, '0.outcome_allowed'))->toBe(['寻找无人看守的小船'])
        ->and(data_get($plan->scene_plans, '0.outcome_forbidden'))->toBe(['取得港务官正式许可'])
        ->and($plan->target_words)->toBe(4_200)
        ->and($chapter->scenes()->count())->toBe(1)
        ->and($chapter->scenes()->sole()->goal)->toBe('取得出港许可')
        ->and($chapter->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->prompt_version)->toBe('chapter-planner-v9')
        ->and($run->bible_version)->toBe(1)
        ->and(data_get($run->context_snapshot, 'style_contract_checksum'))->toBe(data_get($run->context_snapshot, 'l4.checksum'))
        ->and(data_get($run->context_snapshot, 'l4.primary_style.name'))->toBe('通俗爽快')
        ->and(data_get($run->context_snapshot, 'generation_preferences'))->not->toHaveKey('style_profile')
        ->and($run->artifacts()->sole()->type)->toBe(ArtifactType::ChapterPlan)
        ->and($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->prompt)->toContain('通俗爽快')
        ->and($fake->requests()[0]->prompt)->not->toContain('冷峻克制')
        ->and($fake->requests()[0]->prompt)->toContain('active_facts 为空时必须返回 []')
        ->and($fake->requests()[0]->prompt)->toContain('outcome_allowed')
        ->and($fake->requests()[0]->prompt)->toContain('outcome_forbidden')
        ->and($fake->requests()[0]->prompt)->toContain('每个 key 按本章 Scene 顺序首次出现时必须使用 establish')
        ->and($fake->requests()[0]->prompt)->toContain('即使该状态继承自 previous_chapter_ending 或 Canonical Story State');
});

test('the planner selects foreshadowings by the shared target chapter timing rule', function () {
    [$chapter, $character] = plannerChapter();
    $due = Foreshadowing::factory()->for($chapter->novel)->create([
        'title' => '开篇暗号',
        'status' => ForeshadowingStatus::Planted,
        'due_from_chapter' => 1,
        'due_to_chapter' => 2,
    ]);
    Foreshadowing::factory()->for($chapter->novel)->create([
        'title' => '后期暗号',
        'status' => ForeshadowingStatus::Planted,
        'due_from_chapter' => 2,
        'due_to_chapter' => 3,
    ]);
    $payload = plannerPayload($character->getKey(), ['foreshadowing_actions' => [[
        'foreshadowing_id' => $due->getKey(),
        'action' => 'reinforce',
        'target_scene_sequence' => 1,
        'acceptance_criteria' => '正文明确再次出现开篇暗号并推动当前冲突。',
        'reason' => null,
    ]]]);
    $fake = (new FakeAiProvider)->enqueue(plannerResponse($payload));
    app()->instance(AiProvider::class, $fake);

    app(ChapterPlanner::class)->generate($chapter->getKey());

    $context = $chapter->generationRuns()->sole()->context_snapshot;

    expect(data_get($context, 'foreshadowings_requiring_action'))->toHaveCount(1)
        ->and(data_get($context, 'foreshadowings_requiring_action.0.id'))->toBe($due->getKey())
        ->and(data_get($context, 'foreshadowings_requiring_action.0.timing_status'))->toBe('due')
        ->and(data_get($context, 'foreshadowings_requiring_action.0.allowed_model_actions'))->toBe(['reinforce', 'pay_off'])
        ->and(data_get($context, 'foreshadowings_requiring_action.0'))->toHaveKeys([
            'description', 'promised_payoff', 'content_status', 'content_status_source',
            'projection_status', 'important_events',
        ]);
});

test('critical overdue foreshadowing stops the planner before a run or provider call', function () {
    [$chapter, $character] = plannerChapter();
    $chapter->update(['sequence' => 2]);
    Foreshadowing::factory()->for($chapter->novel)->create([
        'title' => '必须回收的王冠裂痕',
        'importance' => 'critical',
        'status' => ForeshadowingStatus::Reinforced,
        'due_from_chapter' => 1,
        'due_to_chapter' => 1,
    ]);
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(plannerPayload($character->getKey())));
    app()->instance(AiProvider::class, $fake);

    try {
        app(ChapterPlanner::class)->generate($chapter->getKey());
        test()->fail('Expected overdue critical foreshadowing to stop automatic planning.');
    } catch (GenerationPreflightException $exception) {
        expect($exception->reason)->toBe('critical_foreshadowing_overdue')
            ->and($exception->getMessage())->toContain('必须回收的王冠裂痕')
            ->and($fake->requests())->toHaveCount(0)
            ->and($chapter->generationRuns()->count())->toBe(0);
    }
});

test('a new bible applies only after explicitly restarting the chapter pipeline', function () {
    [$chapter, $character] = plannerChapter();
    $fake = (new FakeAiProvider)
        ->enqueue(plannerResponse(plannerPayload($character->getKey())))
        ->enqueue(plannerResponse(plannerPayload($character->getKey(), ['tone' => '冷峻'])));
    app()->instance(AiProvider::class, $fake);
    $planner = app(ChapterPlanner::class);

    $firstPlan = $planner->generate($chapter->getKey());
    $firstBible = $chapter->novel->currentBible()->firstOrFail();
    $firstBible->update(['status' => BibleStatus::Superseded]);
    NovelBible::factory()->for($chapter->novel)->create([
        'version' => 2,
        'tone' => '冷峻',
        'style_profile' => array_replace($firstBible->style_profile, ['primary_style' => 'austere']),
        'status' => BibleStatus::Current,
    ]);

    $stillFrozen = $planner->generate($chapter->getKey());
    $restartedPlan = $planner->generate($chapter->getKey(), true);
    $runs = $chapter->generationRuns()->where('stage', GenerationStage::ChapterPlanning)->oldest('id')->get();

    expect($stillFrozen?->is($firstPlan))->toBeTrue()
        ->and($restartedPlan?->version)->toBe(2)
        ->and($fake->requests())->toHaveCount(2)
        ->and($runs->pluck('bible_version')->all())->toBe([1, 2])
        ->and($runs->pluck('input_hash')->unique()->count())->toBe(2)
        ->and($runs->flatMap(fn (GenerationRun $run) => $run->artifacts)->where('type', ArtifactType::ChapterPlan))->toHaveCount(2)
        ->and($runs->pluck('context_snapshot')->map(fn (array $snapshot): mixed => data_get($snapshot, 'style_contract_checksum'))->unique()->count())->toBe(2)
        ->and($fake->requests()[0]->prompt)->toContain('通俗爽快')
        ->and($fake->requests()[1]->prompt)->toContain('冷峻克制');
});

test('the planner receives closing restrictions and closure debt in completing mode', function () {
    [$chapter, $character] = plannerChapter();
    $chapter->novel->update(['status' => NovelStatus::Completing]);
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(plannerPayload($character->getKey())));
    app()->instance(AiProvider::class, $fake);

    app(ChapterPlanner::class)->generate($chapter->getKey());

    $request = $fake->requests()[0];
    $snapshot = $chapter->generationRuns()->sole()->context_snapshot;

    expect($request->systemPrompt)->toContain('当前处于收束阶段')
        ->and(data_get($snapshot, 'closing_restrictions.active'))->toBeTrue()
        ->and(data_get($snapshot, 'closing_restrictions.forbidden_new_elements'))->toBe([
            'core_character',
            'main_story_arc',
            'hard_world_rule',
            'high_importance_foreshadowing',
        ])
        ->and(data_get($snapshot, 'closure_debt.total'))->toBe(0)
        ->and(data_get($snapshot, 'closure_debt.critical'))->toBe(0);
});

test('the planner receives the previous canonical ending and requires a scene transition', function () {
    [$chapter, $character] = plannerChapter();
    $chapter->update(['sequence' => 2]);
    $previous = Chapter::factory()->for($chapter->novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
        'title' => '启程',
    ]);
    $previousRun = GenerationRun::factory()->for($chapter->novel)->for($previous)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $previousArtifact = GenerationArtifact::factory()->for($previousRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => '林舟与苏离沿石阶向魔法学院走去。',
    ]);
    $previous->update(['canonical_artifact_id' => $previousArtifact->getKey()]);
    $payload = plannerPayload($character->getKey());
    $payload['scene_plans'][0]['transition_from_previous'] = '写出抵达学院、办理登记并入住，随后过渡到次日清晨。';
    $fake = (new FakeAiProvider)->enqueue(plannerResponse($payload));
    app()->instance(AiProvider::class, $fake);

    app(ChapterPlanner::class)->generate($chapter->getKey());

    $snapshot = $chapter->generationRuns()->where('stage', GenerationStage::ChapterPlanning)->sole()->context_snapshot;
    expect(data_get($snapshot, 'previous_chapter_ending.text'))->toBe('林舟与苏离沿石阶向魔法学院走去。')
        ->and($fake->requests()[0]->prompt)->toContain('transition_from_previous')
        ->and($fake->requests()[0]->systemPrompt)->toContain('不得静默跳过');
});

test('the planner reads recent canonical chapter summaries in sequence order', function () {
    [$chapter, $character] = plannerChapter();
    $chapter->update(['sequence' => 3]);
    Chapter::factory()->for($chapter->novel)->create([
        'sequence' => 2,
        'status' => ChapterStatus::Canonical,
        'summary' => '第二章：主角取得港口通行证。',
    ]);
    Chapter::factory()->for($chapter->novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
        'summary' => '第一章：主角抵达旧港。',
    ]);
    Chapter::factory()->for($chapter->novel)->create([
        'sequence' => 99,
        'status' => ChapterStatus::Review,
        'summary' => '非正式章节摘要不得进入 Planner。',
    ]);
    $payload = plannerPayload($character->getKey());
    $payload['scene_plans'][0]['transition_from_previous'] = '承接上一章取得通行证的结果，写出主角从港务处前往码头。';
    $fake = (new FakeAiProvider)->enqueue(plannerResponse($payload));
    app()->instance(AiProvider::class, $fake);

    app(ChapterPlanner::class)->generate($chapter->getKey());

    $summaries = $chapter->generationRuns()
        ->where('stage', GenerationStage::ChapterPlanning)
        ->sole()
        ->context_snapshot['recent_summaries'];

    expect($summaries)->toBe([
        ['sequence' => 1, 'summary' => '第一章：主角抵达旧港。'],
        ['sequence' => 2, 'summary' => '第二章：主角取得港口通行证。'],
    ])->and($fake->requests()[0]->prompt)->not->toContain('非正式章节摘要不得进入 Planner');
});

test('duplicate delivery reuses the successful run and does not call the provider twice', function () {
    [$chapter, $character] = plannerChapter();
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(plannerPayload($character->getKey())));
    app()->instance(AiProvider::class, $fake);
    $planner = app(ChapterPlanner::class);

    $first = $planner->generate($chapter->getKey());
    $second = $planner->generate($chapter->getKey());

    expect($second?->is($first))->toBeTrue()
        ->and($chapter->plans()->count())->toBe(1)
        ->and($chapter->generationRuns()->count())->toBe(1)
        ->and($fake->requests())->toHaveCount(1);
});

test('explicit regeneration creates a new immutable artifact and plan version', function () {
    [$chapter, $character] = plannerChapter();
    $fake = (new FakeAiProvider)
        ->enqueue(plannerResponse(plannerPayload($character->getKey())))
        ->enqueue(plannerResponse(plannerPayload($character->getKey(), ['tone' => '压抑'])));
    app()->instance(AiProvider::class, $fake);
    $planner = app(ChapterPlanner::class);

    $planner->generate($chapter->getKey());
    $planner->generate($chapter->getKey(), true);

    expect($chapter->plans()->count())->toBe(2)
        ->and($chapter->plans()->where('version', 1)->sole()->status)->toBe(PlanStatus::Superseded)
        ->and($chapter->plans()->where('version', 2)->sole()->status)->toBe(PlanStatus::Ready)
        ->and($chapter->generationRuns()->count())->toBe(2)
        ->and($chapter->generationRuns()->latest('id')->first()->attempt)->toBe(2);
});

test('explicit regeneration safely replans a void chapter with generated scenes', function () {
    [$chapter, $character] = plannerChapter();
    $fake = (new FakeAiProvider)
        ->enqueue(plannerResponse(plannerPayload($character->getKey())))
        ->enqueue(plannerResponse(plannerPayload($character->getKey(), ['tone' => '压抑'])));
    app()->instance(AiProvider::class, $fake);
    $planner = app(ChapterPlanner::class);

    $planner->generate($chapter->getKey());
    $scene = $chapter->scenes()->sole();
    $sceneRun = GenerationRun::factory()->for($chapter)->for($scene)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $oldArtifact = GenerationArtifact::factory()->for($sceneRun)->create(['type' => ArtifactType::SceneDraft]);
    $scene->update(['status' => 'draft', 'current_artifact_id' => $oldArtifact->getKey()]);
    $chapter->update(['status' => ChapterStatus::Void]);

    $plan = $planner->generate($chapter->getKey(), true);

    expect($plan?->version)->toBe(2)
        ->and($chapter->fresh()->status)->toBe(ChapterStatus::Generating)
        ->and($scene->fresh()->status->value)->toBe('planned')
        ->and($scene->fresh()->current_artifact_id)->toBeNull()
        ->and($oldArtifact->fresh())->not->toBeNull();
});

test('invalid structured output fails the run without writing a plan or artifact', function () {
    [$chapter, $character] = plannerChapter();
    $payload = plannerPayload($character->getKey());
    unset($payload['scene_plans'][0]['outcome']);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(plannerResponse($payload)));

    try {
        app(ChapterPlanner::class)->generate($chapter->getKey());
        $this->fail('Expected plan validation to fail.');
    } catch (ValidationException) {
        expect($chapter->plans()->count())->toBe(0)
            ->and($chapter->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
            ->and($chapter->generationRuns()->sole()->error_code)->toBe('plan_validation_failed')
            ->and($chapter->generationRuns()->sole()->artifacts()->count())->toBe(0);
    }
});

test('the queue job does not retry schema or business validation failures', function () {
    [$chapter, $character] = plannerChapter();
    $payload = plannerPayload($character->getKey());
    unset($payload['scene_plans'][0]['outcome']);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(plannerResponse($payload)));

    (new PlanChapterJob($chapter->getKey()))->handle(app(ChapterPlanner::class));

    expect($chapter->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($chapter->generationRuns()->sole()->error_code)->toBe('plan_validation_failed')
        ->and($chapter->plans()->count())->toBe(0);
});

test('a canonical state change during provider execution blocks stale plan persistence', function () {
    [$chapter, $character] = plannerChapter();
    $payload = plannerPayload($character->getKey());
    $provider = new class($chapter->novel, plannerResponse($payload)) implements AiProvider
    {
        public function __construct(private Novel $novel, private AiResponse $response) {}

        public function generate(AiRequest $request): AiResponse
        {
            $nextState = StoryStateVersion::factory()->for($this->novel)->create([
                'version' => 1,
                'state' => ['timeline' => ['changed while planning']],
            ]);
            $this->novel->update(['canonical_state_version_id' => $nextState->getKey()]);

            return $this->response;
        }
    };
    app()->instance(AiProvider::class, $provider);

    try {
        app(ChapterPlanner::class)->generate($chapter->getKey());
        $this->fail('Expected state version conflict was not thrown.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('state_version_conflict')
            ->and($exception->retryable)->toBeFalse()
            ->and($chapter->plans()->count())->toBe(0)
            ->and($chapter->generationRuns()->sole()->artifacts()->count())->toBe(0)
            ->and($chapter->generationRuns()->sole()->status)->toBe(RunStatus::Failed);
    }
});

test('a retryable provider failure is recorded and rethrown by the queue job', function () {
    [$chapter] = plannerChapter();
    $exception = new AiProviderException('provider_timeout', 'timeout', true);
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue($exception));

    expect(fn () => (new PlanChapterJob($chapter->getKey()))->handle(app(ChapterPlanner::class)))
        ->toThrow(AiProviderException::class, 'timeout');

    expect($chapter->generationRuns()->sole()->status)->toBe(RunStatus::Failed)
        ->and($chapter->generationRuns()->sole()->error_code)->toBe('provider_timeout');
});

test('a stale running planner run is failed and recovered as a new attempt', function () {
    [$chapter, $character] = plannerChapter();
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(plannerPayload($character->getKey())));
    app()->instance(AiProvider::class, $fake);

    $stale = $chapter->generationRuns()->create([
        'novel_id' => $chapter->novel_id,
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => 'chapter_planning',
        'status' => RunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'stale-planner-run',
        'input_hash' => hash('sha256', 'stale'),
        'started_at' => now()->subSeconds((int) config('generation.stalled_run_after_seconds') + 1),
    ]);
    $stale->timestamps = false;
    $stale->updated_at = now()->subSeconds((int) config('generation.stalled_run_after_seconds') + 1);
    $stale->saveQuietly();

    app(ChapterPlanner::class)->generate($chapter->getKey());

    expect($stale->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stale->fresh()->error_code)->toBe('worker_interrupted')
        ->and($chapter->generationRuns()->count())->toBe(2)
        ->and($chapter->generationRuns()->latest('id')->first()->status)->toBe(RunStatus::Succeeded);
});

test('a paused novel cannot start a planner run', function () {
    [$chapter, $character, $outline, , $arc] = outlinePlannerChapter();
    $chapter->novel->update(['status' => NovelStatus::Paused]);
    $fake = (new FakeAiProvider)->enqueue(plannerResponse(outlinePlannerPayload($character, $outline, $arc)));
    app()->instance(AiProvider::class, $fake);

    expect(fn () => app(ChapterPlanner::class)->generate($chapter->getKey()))
        ->toThrow(AiProviderException::class, '小说已暂停');

    expect($chapter->generationRuns()->count())->toBe(0)
        ->and($chapter->plans()->count())->toBe(0)
        ->and($fake->requests())->toHaveCount(0)
        ->and($chapter->novel->fresh()->current_outline_id)->toBe($outline->getKey());
});
