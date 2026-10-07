<?php

use App\Actions\Chapters\InvalidateChapterPlanDownstreamAction;
use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\BibleStatus;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\GenerateSceneJob;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Scene;
use App\Services\GenerationOutputCapacityGuard;
use App\Services\OutlineProgressResolver;
use App\Services\PlanAdmissionService;
use App\Services\PlanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function admissionReadyPlan(int $sceneCount = 2): ChapterPlan
{
    seedVerifiedChapterModelProfiles();
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    app(InitializeNovelStateAction::class)->handle($novel);
    NovelBible::factory()->for($novel)->create();
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Generating,
    ]);
    $pov = Character::factory()->for($novel)->create();
    $scenePlans = collect(range(1, $sceneCount))->map(fn (int $sequence): array => [
        'goal' => "完成场景 {$sequence} 目标",
        'conflict' => "处理场景 {$sequence} 冲突",
        'turn' => "触发场景 {$sequence} 转折",
        'outcome' => "取得场景 {$sequence} 结果",
        'outcome_allowed' => [],
        'outcome_forbidden' => [],
        'continuity_requirements' => [],
        'pov_character_id' => $pov->getKey(),
        'location' => '雾港',
        'time_anchor' => '当日黄昏',
        'transition_from_previous' => $sequence === 1 ? null : '承接上一场景的行动结果。',
    ])->all();
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'status' => PlanStatus::Ready,
        'pov_character_id' => $pov->getKey(),
        'target_words' => 3_000,
        'scene_plans' => $scenePlans,
    ]);
    $target = app(OutlineProgressResolver::class)->resolve($novel->fresh());
    $plan->update([
        'arc_contributions' => [[
            'role' => 'primary',
            'arc_id' => $target->arcId,
            'beat_key' => $target->beat['key'],
            'beat_index' => $target->beat['sequence'],
            'milestone_key' => $target->milestone['key'],
            'milestone_sequence' => $target->milestone['sequence'],
            'target_scene_sequence' => 1,
            'acceptance_criteria' => $target->milestone['acceptance_criteria'][0],
        ]],
        'must_reveal' => $target->milestone['must_include'],
        'must_not_reveal' => array_values(array_unique([
            ...$target->beat['must_not_include'],
            ...$target->milestone['must_not_include'],
        ])),
    ]);

    return $plan->fresh();
}

test('a valid plan is admitted with frozen sources routes and capacity without calling a provider', function () {
    $plan = admissionReadyPlan();
    $provider = new FakeAiProvider;
    app()->instance(AiProvider::class, $provider);

    $admitted = app(PlanAdmissionService::class)->admit($plan);

    expect($admitted->checksum)->toHaveLength(64)
        ->and($admitted->input_hash)->toHaveLength(64)
        ->and($admitted->admitted_at)->not->toBeNull()
        ->and(data_get($admitted->admission_snapshot, 'schema_version'))->toBe(2)
        ->and(data_get($admitted->admission_snapshot, 'state_version'))->toBe(0)
        ->and(data_get($admitted->admission_snapshot, 'outline_checksum'))->toHaveLength(64)
        ->and(data_get($admitted->admission_snapshot, 'routes.writer.provider'))->not->toBeEmpty()
        ->and(data_get($admitted->admission_snapshot, 'routes.reviewer.prompt_version'))->not->toBeEmpty()
        ->and(data_get($admitted->admission_snapshot, 'capacity.scene_allocations'))->toHaveCount(2)
        ->and(data_get($admitted->admission_snapshot, 'routes.extractor.model_capacity.model_price_id'))->toBeInt()
        ->and(data_get($admitted->admission_snapshot, 'routes.extractor.model_capacity.context_window_tokens'))->toBe(1_050_000)
        ->and(data_get($admitted->admission_snapshot, 'routes.extractor.model_capacity.max_output_tokens'))->toBe(128_000)
        ->and(data_get($admitted->admission_snapshot, 'routes.extractor.request_budgets.initial.output_tokens'))->toBe(4_000)
        ->and(data_get($admitted->admission_snapshot, 'routes.extractor.request_budgets.initial.reasoning_reserve_tokens'))->toBe(0)
        ->and(data_get($admitted->admission_snapshot, 'routes.extractor.request_budgets.initial.max_completion_tokens'))->toBe(4_000)
        ->and(data_get($admitted->admission_snapshot, 'capacity.event_extraction.max_completion_tokens'))->toBe(12_000)
        ->and(data_get($admitted->admission_snapshot, 'capacity.rewrite.max_completion_tokens'))->toBeGreaterThan(0)
        ->and($plan->chapter->generationRuns()->count())->toBe(0)
        ->and($provider->requests())->toBe([]);
});

test('plan checksum ignores associative key order while preserving list order', function () {
    $persisted = admissionReadyPlan(1);
    $scene = $persisted->scene_plans[0];
    $scene['outcome_allowed'] = ['先确认水声', '再停止喂乳'];

    // 刻意调整对象键顺序，验证规范化哈希不依赖 Provider 返回 Map 的排列方式。
    $sceneWithProviderOrder = [
        'goal' => $scene['goal'],
        'conflict' => $scene['conflict'],
        'turn' => $scene['turn'],
        'outcome' => $scene['outcome'],
        'outcome_allowed' => $scene['outcome_allowed'],
        'outcome_forbidden' => $scene['outcome_forbidden'],
        'continuity_requirements' => $scene['continuity_requirements'],
        'pov_character_id' => $scene['pov_character_id'],
        'location' => $scene['location'],
        'time_anchor' => $scene['time_anchor'],
        'transition_from_previous' => $scene['transition_from_previous'],
    ];
    $candidate = new ChapterPlan([
        ...$persisted->semanticPayload(),
        'scene_plans' => [$sceneWithProviderOrder],
        'version' => $persisted->version,
        'status' => PlanStatus::Ready,
    ]);
    $candidate->setRelation('chapter', $persisted->chapter);
    $checksumBeforeSave = $candidate->semanticChecksum();
    $admission = app(PlanAdmissionService::class)->prepare($candidate);

    $persisted->update([
        ...$candidate->semanticPayload(),
        ...$admission,
    ]);
    $reloaded = $persisted->fresh();

    expect($reloaded->semanticChecksum())->toBe($checksumBeforeSave)
        ->and(fn () => app(PlanAdmissionService::class)->admit($reloaded))->not->toThrow(ValidationException::class);

    $reversedList = $reloaded->scene_plans;
    $reversedList[0]['outcome_allowed'] = array_reverse($reversedList[0]['outcome_allowed']);
    $reloaded->scene_plans = $reversedList;

    // List 顺序会改变执行含义，必须继续形成不同 checksum。
    expect($reloaded->semanticChecksum())->not->toBe($checksumBeforeSave);
});

test('plan admission freezes verified model capacity and normalized budgets for every downstream provider stage', function (AiStage $stage) {
    $plan = app(PlanAdmissionService::class)->admit(admissionReadyPlan());
    $route = data_get($plan->admission_snapshot, "routes.{$stage->value}");

    // Scene 1 之前一次冻结全部下游合同，恢复期间不得再从当前后台配置拼装容量。
    expect($route)->toBeArray()
        ->and(data_get($route, 'model_capacity.model_price_id'))->toBeInt()
        ->and(data_get($route, 'model_capacity.context_window_tokens'))->toBeGreaterThan(0)
        ->and(data_get($route, 'model_capacity.max_output_tokens'))->toBeGreaterThan(0)
        ->and(data_get($route, 'model_capacity.supports_structured_output'))->toBeTrue()
        ->and(data_get($route, 'request_budgets.initial.output_tokens'))->toBeGreaterThan(0)
        ->and(data_get($route, 'request_budgets.initial.max_completion_tokens'))
        ->toBe(data_get($route, 'request_budgets.initial.output_tokens') + data_get($route, 'request_budgets.initial.reasoning_reserve_tokens'));
})->with([
    'writer' => [AiStage::Writer],
    'extractor' => [AiStage::Extractor],
    'reviewer' => [AiStage::Reviewer],
    'rewrite' => [AiStage::Rewrite],
    'summary' => [AiStage::Summary],
]);

test('all downstream provider stages reject a request budget above the plan admission frozen route', function (AiStage $stage) {
    $plan = app(PlanAdmissionService::class)->admit(admissionReadyPlan());
    $maximum = (int) collect(data_get($plan->admission_snapshot, "routes.{$stage->value}.request_budgets", []))
        ->max(fn (mixed $budget): int => is_array($budget) ? (int) ($budget['max_completion_tokens'] ?? 0) : 0);

    expect(fn () => app(GenerationOutputCapacityGuard::class)->assertWithinFrozenRoute(
        $plan->chapter,
        $stage,
        $maximum + 1,
    ))->toThrow(AiProviderException::class, '超过 Plan Admission 冻结容量');
})->with([
    'writer' => [AiStage::Writer],
    'extractor' => [AiStage::Extractor],
    'reviewer' => [AiStage::Reviewer],
    'rewrite' => [AiStage::Rewrite],
    'summary' => [AiStage::Summary],
]);

test('plan admission rejects a missing model capacity profile without calling a provider', function () {
    $plan = admissionReadyPlan();
    AIModelPrice::query()->delete();
    $provider = new FakeAiProvider;
    app()->instance(AiProvider::class, $provider);

    expect(fn () => app(PlanAdmissionService::class)->prepare($plan))
        ->toThrow(ValidationException::class, '[MODEL_CAPACITY_NOT_VERIFIED]');

    expect($provider->requests())->toBe([])
        ->and($plan->chapter->generationRuns()->count())->toBe(0);
});

test('plan admission freezes model capacity and request budgets independently from later settings changes', function () {
    config()->set('generation.chapter_request_budgets.extractor.initial.reasoning_reserve_tokens', 6_000);
    config()->set('generation.chapter_request_budgets.extractor.retry.reasoning_reserve_tokens', 6_000);
    config()->set('generation.chapter_request_budgets.extractor.final.reasoning_reserve_tokens', 6_000);
    $plan = app(PlanAdmissionService::class)->admit(admissionReadyPlan());
    $snapshot = $plan->admission_snapshot;
    $profileId = (int) data_get($snapshot, 'routes.extractor.model_capacity.model_price_id');

    AIModelPrice::query()->whereKey($profileId)->update([
        'context_window_tokens' => 2_000_000,
        'max_output_tokens' => 256_000,
    ]);
    config()->set('generation.chapter_request_budgets.extractor.initial.output_tokens', 9_000);

    expect(data_get($plan->fresh()->admission_snapshot, 'routes.extractor.model_capacity.context_window_tokens'))->toBe(1_050_000)
        ->and(data_get($plan->fresh()->admission_snapshot, 'routes.extractor.model_capacity.max_output_tokens'))->toBe(128_000)
        ->and(data_get($plan->fresh()->admission_snapshot, 'routes.extractor.request_budgets.initial'))->toBe([
            'output_tokens' => 4_000,
            'reasoning_reserve_tokens' => 6_000,
            'max_completion_tokens' => 10_000,
        ]);
});

test('plan admission rejects a configured request budget above the selected model static capacity', function () {
    $plan = admissionReadyPlan();
    AIModelPrice::query()->update([
        'context_window_tokens' => 20_000,
        'max_output_tokens' => 10_000,
    ]);

    expect(fn () => app(PlanAdmissionService::class)->prepare($plan))
        ->toThrow(ValidationException::class, '[REQUEST_BUDGET_EXCEEDS_MODEL_CAPACITY]');
});

test('capacity guard accounts for the estimated input against the frozen context window', function () {
    $plan = app(PlanAdmissionService::class)->admit(admissionReadyPlan());

    expect(fn () => app(GenerationOutputCapacityGuard::class)->assertWithinFrozenRoute(
        $plan->chapter,
        AiStage::Extractor,
        4_000,
        1_049_000,
    ))->toThrow(AiProviderException::class, '当前剩余上下文 1000');
});

test('a legacy admission snapshot is rejected without being silently rewritten', function () {
    $plan = app(PlanAdmissionService::class)->admit(admissionReadyPlan());
    $snapshot = $plan->admission_snapshot;
    $snapshot['schema_version'] = 1;
    $plan->update(['admission_snapshot' => $snapshot]);

    $result = app(PlanValidator::class)->validate($plan->fresh(), $snapshot);

    expect(collect($result->findings)->pluck('code'))
        ->toContain('ADMISSION_CONTRACT_VERSION_UNSUPPORTED')
        ->and(data_get($plan->fresh()->admission_snapshot, 'schema_version'))->toBe(1);
});

test('a newly generated plan freezes the bible version of its planning run instead of an older ready plan anchor', function () {
    $plan = admissionReadyPlan();
    GenerationRun::factory()->for($plan->chapter->novel)->for($plan->chapter)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
        'bible_version' => 1,
        'context_snapshot' => ['chapter_plan_id' => $plan->getKey()],
    ]);
    $plan->chapter->novel->bibles()->update(['status' => BibleStatus::Superseded]);
    NovelBible::factory()->for($plan->chapter->novel)->create([
        'version' => 2,
        'status' => BibleStatus::Current,
    ]);
    $candidate = new ChapterPlan([
        ...$plan->semanticPayload(),
        'version' => 2,
        'status' => PlanStatus::Ready,
    ]);
    $candidate->setRelation('chapter', $plan->chapter);

    $admission = app(PlanAdmissionService::class)->prepare($candidate, 2);

    expect(data_get($admission, 'admission_snapshot.bible_version'))->toBe(2);
});

test('the admission gate blocks a non contiguous scene plan before scene one with actionable details', function () {
    Queue::fake();
    $plan = admissionReadyPlan();
    $provider = new FakeAiProvider;
    app()->instance(AiProvider::class, $provider);
    $scenePlans = $plan->scene_plans;
    $plan->update(['scene_plans' => [1 => $scenePlans[0], 2 => $scenePlans[1]]]);

    try {
        app(AdvanceChapterPipelineAction::class)->handle($plan->chapter_id);
        $this->fail('Plan Admission 应阻止 Scene 1。');
    } catch (ValidationException $exception) {
        $message = implode(' ', $exception->errors()['plan'] ?? []);
        expect($message)->toContain('[INVALID_SCENE_SEQUENCE]')
            ->and($message)->toContain('字段：scene_plans')
            ->and($message)->toContain('关联记录：chapter_plan:'.$plan->getKey())
            ->and($message)->toContain('修复动作：');
    }

    expect($plan->chapter->generationRuns()->where('stage', GenerationStage::SceneGeneration)->count())->toBe(0)
        ->and($provider->requests())->toBe([]);
    Queue::assertNotPushed(GenerateSceneJob::class);
});

test('missing frozen routes and source versions invalidate an admitted plan', function () {
    $plan = app(PlanAdmissionService::class)->admit(admissionReadyPlan());
    $snapshot = $plan->admission_snapshot;
    unset($snapshot['routes']['writer'], $snapshot['state_version']);
    $snapshot['bible_version'] = 999;
    $snapshot['outline_version'] = 999;
    $snapshot['handoff_checksum'] = hash('sha256', 'stale-handoff');
    $plan->update(['admission_snapshot' => $snapshot]);

    $result = app(PlanValidator::class)->validate($plan->fresh(), $snapshot);
    $findings = collect($result->findings);
    $route = $findings->firstWhere('code', 'PROVIDER_ROUTE_NOT_FROZEN');

    expect($findings->pluck('code'))->toContain(
        'ADMISSION_SOURCE_NOT_FROZEN',
        'ADMISSION_STATE_VERSION_MISMATCH',
        'ADMISSION_BIBLE_VERSION_MISMATCH',
        'ADMISSION_OUTLINE_MISMATCH',
        'ADMISSION_HANDOFF_MISMATCH',
        'PROVIDER_ROUTE_NOT_FROZEN',
    )
        ->and($route?->field)->toBe('admission_snapshot.routes.writer')
        ->and($route?->relatedRecord)->toBe('chapter_plan:'.$plan->getKey())
        ->and($route?->repairAction)->not->toBeEmpty();

    expect(fn () => app(PlanAdmissionService::class)->admit($plan->fresh()))
        ->toThrow(ValidationException::class);
});

test('writer and reviewer capacity are checked before admission', function () {
    $plan = admissionReadyPlan(1);
    config()->set('generation.chapter_request_budgets.writer.initial.output_tokens', 10);
    config()->set('generation.chapter_request_budgets.writer.retry.output_tokens', 10);
    config()->set('generation.chapter_request_budgets.writer.final.output_tokens', 10);
    config()->set('generation.review_context_token_budget', 10);

    try {
        app(PlanAdmissionService::class)->prepare($plan);
        $this->fail('容量不足的 Plan 不应通过 Admission。');
    } catch (ValidationException $exception) {
        $message = implode(' ', $exception->errors()['plan'] ?? []);
        expect($message)->toContain('[SCENE_OUTPUT_CAPACITY_EXCEEDED]')
            ->and($message)->toContain('[REVIEW_CAPACITY_EXCEEDED]');
    }
});

test('the same admission input reuses the ready plan and a semantic change invalidates only its chapter pointers', function () {
    $service = app(PlanAdmissionService::class);
    $plan = $service->admit(admissionReadyPlan());
    $chapter = $plan->chapter;
    $novel = $chapter->novel;
    app(SyncScenesFromChapterPlanAction::class)->execute($chapter);

    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::SceneDraft]);
    $scene = $chapter->scenes()->orderBy('sequence')->firstOrFail();
    $scene->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);

    $otherChapter = Chapter::factory()->for($novel)->create(['sequence' => 2, 'status' => ChapterStatus::Generating]);
    $otherRun = GenerationRun::factory()->for($novel)->for($otherChapter)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $otherArtifact = GenerationArtifact::factory()->for($otherRun)->create(['type' => ArtifactType::SceneDraft]);
    $otherScene = Scene::factory()->for($otherChapter)->create([
        'sequence' => 1,
        'status' => SceneStatus::Draft,
        'current_artifact_id' => $otherArtifact->getKey(),
    ]);

    $sameCandidate = new ChapterPlan([
        ...$plan->semanticPayload(),
        'version' => 2,
        'status' => PlanStatus::Ready,
    ]);
    $sameCandidate->setRelation('chapter', $chapter);
    $sameAdmission = $service->prepare($sameCandidate);

    expect($sameAdmission['input_hash'])->toBe($plan->input_hash)
        ->and($service->reusableReadyPlan($chapter, $sameAdmission['input_hash'])?->is($plan))->toBeTrue();

    $changedCandidate = new ChapterPlan([
        ...$plan->semanticPayload(),
        'reader_promise' => '变化后的读者承诺',
        'version' => 2,
        'status' => PlanStatus::Ready,
    ]);
    $changedCandidate->setRelation('chapter', $chapter);
    $changedAdmission = $service->prepare($changedCandidate);
    $plan->update(['status' => PlanStatus::Superseded]);
    $chapter->plans()->create([
        ...$changedCandidate->semanticPayload(),
        'version' => 2,
        'status' => PlanStatus::Ready,
        ...$changedAdmission,
    ]);
    app(InvalidateChapterPlanDownstreamAction::class)->execute($chapter);

    expect($changedAdmission['input_hash'])->not->toBe($plan->input_hash)
        ->and($scene->fresh()->status)->toBe(SceneStatus::Planned)
        ->and($scene->fresh()->current_artifact_id)->toBeNull()
        ->and($artifact->fresh())->not->toBeNull()
        ->and($run->fresh())->not->toBeNull()
        ->and($otherScene->fresh()->status)->toBe(SceneStatus::Draft)
        ->and($otherScene->fresh()->current_artifact_id)->toBe($otherArtifact->getKey());
});
