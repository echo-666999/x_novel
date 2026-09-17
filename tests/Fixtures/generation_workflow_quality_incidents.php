<?php

return [
    'run_350_review_response' => [
        'score' => 92.30,
        'dimension_statuses' => [
            'continuity' => 'pass',
            'plan' => 'pass',
            'character' => 'pass',
            'progress' => 'pass',
            'repetition' => 'issues_found',
            'pacing' => 'pass',
            'style' => 'issues_found',
        ],
        'findings' => [[
            'code' => 'EXCESSIVE_REPETITION',
            'dimension' => 'repetition',
            'severity' => 'warning',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'message' => '关键约束被重复表达。',
            'evidence' => '林舟守住城门',
        ]],
        'expected_style_status' => 'pass',
        'expected_decision' => 'PASS',
    ],
    'chapter_12_coverage_false_negative' => [
        'content' => '林舟守住城门，也兑现了向同伴作出的承诺。',
        'scene_plan' => [
            'goal' => '守住城门',
            'conflict' => '敌军逼近',
            'turn' => '同伴兑现增援承诺',
            'outcome' => '城门守住',
        ],
        'reported_missing' => ['goal', 'outcome'],
        'repaired_coverage' => [
            'goal' => ['status' => 'fulfilled', 'evidence' => '林舟守住城门'],
            'conflict' => ['status' => 'fulfilled', 'evidence' => '守住城门'],
            'turn' => ['status' => 'fulfilled', 'evidence' => '兑现了向同伴作出的承诺'],
            'outcome' => ['status' => 'fulfilled', 'evidence' => '守住城门'],
        ],
    ],
    'chapter_11_rewrite_exhaustion' => [
        'successful_automatic_attempts' => 2,
        'remaining_finding' => [
            'code' => 'STYLE_MISMATCH',
            'dimension' => 'style',
            'severity' => 'error',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'message' => '两轮修订后仍存在文风偏差。',
            'evidence' => '林舟守住城门',
        ],
        'expected_decision' => 'NEEDS_ATTENTION',
    ],
    'incomplete_v0_baseline' => [
        'schema_version' => 1,
        'characters' => [],
    ],
    'header_actions' => [
        'verifyRebuild' => "openStoryStateDialog('verify')",
        'rebuildProjections' => "openStoryStateDialog('projections')",
        'manualCorrection' => "openStoryStateDialog('manual')",
    ],
];
