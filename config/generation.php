<?php

return [
    'stage_policies' => [
        'chapter_planning' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => [], 'non_terminal_codes' => ['novel_paused']],
        'scene_generation' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => ['schema', 'evidence', 'length'], 'non_terminal_codes' => ['novel_paused', 'previous_scene_incomplete']],
        'chapter_assembly' => ['max_attempts' => 2, 'backoff' => [10], 'repairs' => ['length'], 'non_terminal_codes' => ['novel_paused', 'assembly_input_incomplete', 'assembly_scene_incomplete', 'assembly_context_incomplete']],
        'event_extraction' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => ['evidence'], 'non_terminal_codes' => ['novel_paused']],
        // Coverage 语义复核是独立 Provider 阶段，技术失败不能伪装成正文缺失后进入 Rewrite。
        'coverage_judgment' => ['max_attempts' => 2, 'backoff' => [10], 'repairs' => [], 'non_terminal_codes' => ['novel_paused']],
        'review' => ['max_attempts' => 2, 'backoff' => [10], 'repairs' => [], 'non_terminal_codes' => ['novel_paused', 'review_prerequisite_missing', 'coverage_judgment_pending']],
        // Rewrite 有 initial/retry/final 三档技术预算，Queue 必须允许三次投递才能真正到达最终档。
        'rewrite' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => ['evidence', 'length'], 'non_terminal_codes' => ['novel_paused', 'rewrite_exhausted', 'coverage_judgment_required']],
        'commit' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => []],
        'memory_summary' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => []],
        'embedding' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => []],
        'ending_audit' => ['max_attempts' => 2, 'backoff' => [10], 'repairs' => []],
    ],
    'acceptance_tools_enabled' => (bool) env('GENERATION_ACCEPTANCE_TOOLS_ENABLED', false),
    'stalled_run_after_seconds' => (int) env('STALLED_RUN_AFTER_SECONDS', 480),
    'pending_job_seconds' => (int) env('GENERATION_PENDING_JOB_SECONDS', 900),
    'scene_context_token_budget' => (int) env('SCENE_CONTEXT_TOKEN_BUDGET', 12_000),
    'scene_job_soft_timeout_seconds' => (int) env('SCENE_JOB_SOFT_TIMEOUT_SECONDS', 300),
    'scene_job_safety_margin_seconds' => (int) env('SCENE_JOB_SAFETY_MARGIN_SECONDS', 20),
    'max_length_repair_attempts' => 1,
    'max_rewrite_length_repair_attempts' => 2,
    'max_coverage_repair_attempts' => 2,
    'max_scene_structure_repair_attempts' => 2,
    'previous_scene_tail_characters' => (int) env('PREVIOUS_SCENE_TAIL_CHARACTERS', 1_000),
    'chapter_min_length_ratio' => .85,
    'chapter_max_length_ratio' => 1.15,
    'max_event_evidence_repair_attempts' => 2,
    'review_pass_score' => (float) env('REVIEW_PASS_SCORE', 80),
    'max_rewrite_attempts' => (int) env('MAX_REWRITE_ATTEMPTS', 2),
];
