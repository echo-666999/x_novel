<?php

use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use App\Services\ChapterRepairRecommendation;

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
        ->and($recommendation['target_fields'])->toBe(['admission_snapshot.capacity.review'])
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
        ->and($recommendation['target_fields'])->toBe(['admission_snapshot.routes.writer.request_budgets'])
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
