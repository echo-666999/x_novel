<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Services\ChapterRepairRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('persisted findings map to deterministic repair layers and entries', function (array $finding, string $layer, string $entry) {
    $recommendation = app(ChapterRepairRecommendation::class)->fromFinding($finding);

    expect($recommendation['problem_layer'])->toBe($layer)
        ->and($recommendation['recovery_key'])->toBe($entry)
        ->and($recommendation['confirmed_evidence'])->not->toBeEmpty()
        ->and($recommendation['target_fields'])->not->toBeEmpty()
        ->and($recommendation['affected_stages'])->not->toBeEmpty()
        ->and($recommendation['recovery_entry'])->not->toBeEmpty();
})->with([
    'paragraph prose' => [[
        'code' => 'STYLE_LOCAL_ISSUE',
        'dimension' => 'style',
        'severity' => 'warning',
        'scope' => 'paragraph',
        'scene_id' => 12,
        'evidence' => '重复的句子',
    ], '局部正文', 'manual_scene_edit'],
    'scene structure' => [[
        'code' => 'SCENE_OUTCOME_CONFLICT',
        'dimension' => 'continuity',
        'severity' => 'error',
        'scope' => 'scene',
        'scene_id' => 12,
        'message' => '场景结果冲突。',
    ], 'Scene', 'regenerate_scene'],
    'chapter plan' => [[
        'code' => 'CHARACTER_CANDIDATE_NOT_INTRODUCED',
        'dimension' => 'plan',
        'severity' => 'warning',
        'scope' => 'chapter',
        'message' => '计划候选未引入。',
    ], 'Chapter Plan', 'edit_plan'],
    'milestone' => [[
        'code' => 'ARC_BEAT_MILESTONE_NOT_FULFILLED',
        'dimension' => 'plan',
        'severity' => 'warning',
        'scope' => 'chapter',
        'message' => 'Milestone 未满足。',
    ], 'Outline / Milestone', 'restart_from_outline'],
    'canonical fact' => [[
        'code' => 'LOCKED_FACT_CONFLICT',
        'dimension' => 'state',
        'severity' => 'hard',
        'scope' => 'scene',
        'related_fact_id' => 8,
        'message' => '与锁定事实冲突。',
    ], 'Canonical Fact / State', 'canonical_fact'],
]);

test('finding recommendation shows the problem before its verbatim evidence', function () {
    $recommendation = app(ChapterRepairRecommendation::class)->fromFinding([
        'code' => 'PLAN_DEVIATION',
        'dimension' => 'plan',
        'scope' => 'chapter',
        'message' => '正文偏离冻结的章节计划。',
        'evidence' => '她先洗手，随后才把水煮沸。',
    ]);

    expect($recommendation['confirmed_evidence'])->toBe([
        '问题：正文偏离冻结的章节计划。',
        '证据：她先洗手，随后才把水煮沸。',
    ]);
});

test('a passed review does not produce repair recommendations from retained findings', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $reviewRun = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Succeeded,
    ]);
    $reviewArtifact = GenerationArtifact::factory()->for($reviewRun)->create([
        'type' => ArtifactType::ReviewResult,
    ]);
    Review::factory()->for($reviewRun)->create([
        'artifact_id' => $reviewArtifact->getKey(),
        'decision' => ReviewDecision::Pass,
        'findings' => [[
            'code' => 'NON_BLOCKING_NOTE',
            'scope' => 'chapter',
            'message' => '保留的非阻塞审计说明。',
            'evidence' => '一段正文证据。',
        ]],
    ]);

    expect(app(ChapterRepairRecommendation::class)->forChapter($chapter))->toBe([]);
});

test('a successful deferred continuation does not remain an active business failure', function () {
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $deferred = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Failed,
        'error_code' => 'scene_stage_deferred',
        'error_message' => '已保存恢复点并交给新 Job。',
    ]);
    GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
        'attempt' => $deferred->attempt + 1,
    ]);

    expect(app(ChapterRepairRecommendation::class)->forChapter($chapter))->toBe([]);
});

test('review capacity failures require a new plan admission instead of retrying the same frozen plan', function () {
    $run = new GenerationRun([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Failed,
        'error_code' => 'review_capacity_mismatch',
        'error_message' => 'Reviewer Route 容量不足。',
    ]);
    $method = new ReflectionMethod(ChapterRepairRecommendation::class, 'fromRun');

    $recommendation = $method->invoke(app(ChapterRepairRecommendation::class), $run);

    expect($recommendation['problem_layer'])->toBe('Chapter Plan')
        ->and($recommendation['recovery_key'])->toBe('edit_plan')
        ->and($recommendation['confirmed_evidence'])->toBe(['问题：Reviewer Route 容量不足。'])
        ->and($recommendation['target_fields'])->toBe(['admission_snapshot.capacity.review（审校容量冻结配置）'])
        ->and($recommendation['recommended_action'])->toContain('新的 Plan Version');
});

test('scene budget exhaustion requires a new plan admission instead of replaying the frozen scene route', function () {
    $run = new GenerationRun([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Failed,
        'scene_id' => 12,
        'error_code' => 'scene_reasoning_budget_exhausted',
        'error_message' => 'Scene Draft 已耗尽冻结的最高推理预留。',
    ]);
    $method = new ReflectionMethod(ChapterRepairRecommendation::class, 'fromRun');

    $recommendation = $method->invoke(app(ChapterRepairRecommendation::class), $run);

    expect($recommendation['problem_layer'])->toBe('Chapter Plan')
        ->and($recommendation['recovery_key'])->toBe('edit_plan')
        ->and($recommendation['target_fields'])->toBe(['admission_snapshot.routes.writer.request_budgets（正文生成请求预算）'])
        ->and($recommendation['recommended_action'])->toContain('新的 Plan Version');
});

test('plan candidate validation failure recommends explicit regeneration instead of outline restart', function () {
    $run = new GenerationRun([
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Failed,
        'error_code' => 'plan_validation_failed',
        'error_message' => '持续状态缺少 establish。',
    ]);
    $method = new ReflectionMethod(ChapterRepairRecommendation::class, 'fromRun');

    $recommendation = $method->invoke(app(ChapterRepairRecommendation::class), $run);

    expect($recommendation['problem_layer'])->toBe('Chapter Plan')
        ->and($recommendation['recovery_key'])->toBe('regenerate_plan')
        ->and($recommendation['recommended_action'])->toContain('重新生成章节计划')
        ->and($recommendation['recovery_entry'])->toContain('重新生成章节计划');
});
