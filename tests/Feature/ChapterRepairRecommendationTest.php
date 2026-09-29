<?php

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
