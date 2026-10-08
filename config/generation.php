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
    'planner_max_output_tokens' => (int) env('PLANNER_MAX_OUTPUT_TOKENS', 12_000),
    'planner_retry_max_output_tokens' => (int) env('PLANNER_RETRY_MAX_OUTPUT_TOKENS', 16_000),
    'planner_final_retry_max_output_tokens' => (int) env('PLANNER_FINAL_RETRY_MAX_OUTPUT_TOKENS', 24_000),
    'outline_request_budgets' => [
        'outline_foundation' => [
            'output_tokens' => (int) env('OUTLINE_FOUNDATION_OUTPUT_TOKENS', 8_000),
            'reasoning_reserve_tokens' => (int) env('OUTLINE_FOUNDATION_REASONING_RESERVE_TOKENS', 4_000),
        ],
        'outline_structure' => [
            'output_tokens' => (int) env('OUTLINE_STRUCTURE_OUTPUT_TOKENS', 12_000),
            'reasoning_reserve_tokens' => (int) env('OUTLINE_STRUCTURE_REASONING_RESERVE_TOKENS', 4_000),
        ],
        'outline_arc_beats' => [
            'output_tokens' => (int) env('OUTLINE_ARC_BEATS_OUTPUT_TOKENS', 8_000),
            'reasoning_reserve_tokens' => (int) env('OUTLINE_ARC_BEATS_REASONING_RESERVE_TOKENS', 4_000),
        ],
        'outline_beat_detail' => [
            'output_tokens' => (int) env('OUTLINE_BEAT_DETAIL_OUTPUT_TOKENS', 5_000),
            'reasoning_reserve_tokens' => (int) env('OUTLINE_BEAT_DETAIL_REASONING_RESERVE_TOKENS', 3_000),
        ],
    ],
    'scene_context_token_budget' => (int) env('SCENE_CONTEXT_TOKEN_BUDGET', 12_000),
    'scene_max_output_tokens' => (int) env('SCENE_MAX_OUTPUT_TOKENS', 12_000),
    'scene_retry_max_output_tokens' => (int) env('SCENE_RETRY_MAX_OUTPUT_TOKENS', 16_000),
    'scene_final_retry_max_output_tokens' => (int) env('SCENE_FINAL_RETRY_MAX_OUTPUT_TOKENS', 24_000),
    'scene_job_soft_timeout_seconds' => (int) env('SCENE_JOB_SOFT_TIMEOUT_SECONDS', 300),
    'scene_job_safety_margin_seconds' => (int) env('SCENE_JOB_SAFETY_MARGIN_SECONDS', 20),
    'max_length_repair_attempts' => 1,
    'max_rewrite_length_repair_attempts' => 2,
    'max_coverage_repair_attempts' => 2,
    'max_scene_structure_repair_attempts' => 2,
    'previous_scene_tail_characters' => (int) env('PREVIOUS_SCENE_TAIL_CHARACTERS', 1_000),
    'chapter_min_length_ratio' => .85,
    'chapter_max_length_ratio' => 1.15,
    'event_extraction_max_output_tokens' => (int) env('EVENT_EXTRACTION_MAX_OUTPUT_TOKENS', 4_000),
    'event_extraction_retry_max_output_tokens' => (int) env('EVENT_EXTRACTION_RETRY_MAX_OUTPUT_TOKENS', 8_000),
    'event_extraction_final_retry_max_output_tokens' => (int) env('EVENT_EXTRACTION_FINAL_RETRY_MAX_OUTPUT_TOKENS', 12_000),
    'max_event_evidence_repair_attempts' => 2,
    'review_max_output_tokens' => (int) env('REVIEW_MAX_OUTPUT_TOKENS', 12_000),
    'review_context_token_budget' => (int) env('REVIEW_CONTEXT_TOKEN_BUDGET', 32_000),
    'review_pass_score' => (float) env('REVIEW_PASS_SCORE', 80),
    'rewrite_max_output_tokens' => (int) env('REWRITE_MAX_OUTPUT_TOKENS', 16_000),
    'rewrite_retry_max_output_tokens' => (int) env('REWRITE_RETRY_MAX_OUTPUT_TOKENS', 20_000),
    'rewrite_final_retry_max_output_tokens' => (int) env('REWRITE_FINAL_RETRY_MAX_OUTPUT_TOKENS', 24_000),
    'max_rewrite_attempts' => (int) env('MAX_REWRITE_ATTEMPTS', 2),
    'summary_max_output_tokens' => (int) env('SUMMARY_MAX_OUTPUT_TOKENS', 1_200),
    'summary_retry_max_output_tokens' => (int) env('SUMMARY_RETRY_MAX_OUTPUT_TOKENS', 2_400),
    'summary_final_retry_max_output_tokens' => (int) env('SUMMARY_FINAL_RETRY_MAX_OUTPUT_TOKENS', 4_000),
    // Chapter Pipeline 将可见输出与推理预留分开冻结；旧 max_output 配置继续表示可见输出额度。
    'chapter_request_budgets' => [
        'planner' => [
            'initial' => ['output_tokens' => (int) env('PLANNER_MAX_OUTPUT_TOKENS', 12_000), 'reasoning_reserve_tokens' => (int) env('PLANNER_REASONING_RESERVE_TOKENS', 0)],
            'retry' => ['output_tokens' => (int) env('PLANNER_RETRY_MAX_OUTPUT_TOKENS', 16_000), 'reasoning_reserve_tokens' => (int) env('PLANNER_RETRY_REASONING_RESERVE_TOKENS', env('PLANNER_REASONING_RESERVE_TOKENS', 0))],
            'final' => ['output_tokens' => (int) env('PLANNER_FINAL_RETRY_MAX_OUTPUT_TOKENS', 24_000), 'reasoning_reserve_tokens' => (int) env('PLANNER_FINAL_RETRY_REASONING_RESERVE_TOKENS', env('PLANNER_RETRY_REASONING_RESERVE_TOKENS', env('PLANNER_REASONING_RESERVE_TOKENS', 0)))],
        ],
        'writer' => [
            'initial' => ['output_tokens' => (int) env('SCENE_MAX_OUTPUT_TOKENS', 12_000), 'reasoning_reserve_tokens' => (int) env('SCENE_REASONING_RESERVE_TOKENS', 0)],
            'retry' => ['output_tokens' => (int) env('SCENE_RETRY_MAX_OUTPUT_TOKENS', 16_000), 'reasoning_reserve_tokens' => (int) env('SCENE_RETRY_REASONING_RESERVE_TOKENS', env('SCENE_REASONING_RESERVE_TOKENS', 0))],
            'final' => ['output_tokens' => (int) env('SCENE_FINAL_RETRY_MAX_OUTPUT_TOKENS', 24_000), 'reasoning_reserve_tokens' => (int) env('SCENE_FINAL_RETRY_REASONING_RESERVE_TOKENS', env('SCENE_RETRY_REASONING_RESERVE_TOKENS', env('SCENE_REASONING_RESERVE_TOKENS', 0)))],
        ],
        'extractor' => [
            // DeepSeek 已出现 4,000 Token 全部用于隐藏推理的真实响应；三档递增预留让推理耗尽可以安全升级，而不是原参数重试。
            'initial' => ['output_tokens' => (int) env('EVENT_EXTRACTION_MAX_OUTPUT_TOKENS', 4_000), 'reasoning_reserve_tokens' => (int) env('EVENT_EXTRACTION_REASONING_RESERVE_TOKENS', 8_000)],
            'retry' => ['output_tokens' => (int) env('EVENT_EXTRACTION_RETRY_MAX_OUTPUT_TOKENS', 8_000), 'reasoning_reserve_tokens' => (int) env('EVENT_EXTRACTION_RETRY_REASONING_RESERVE_TOKENS', 16_000)],
            'final' => ['output_tokens' => (int) env('EVENT_EXTRACTION_FINAL_RETRY_MAX_OUTPUT_TOKENS', 12_000), 'reasoning_reserve_tokens' => (int) env('EVENT_EXTRACTION_FINAL_RETRY_REASONING_RESERVE_TOKENS', 24_000)],
        ],
        'reviewer' => [
            'initial' => ['output_tokens' => (int) env('REVIEW_MAX_OUTPUT_TOKENS', 12_000), 'reasoning_reserve_tokens' => (int) env('REVIEW_REASONING_RESERVE_TOKENS', 0)],
        ],
        'rewrite' => [
            'initial' => ['output_tokens' => (int) env('REWRITE_MAX_OUTPUT_TOKENS', 16_000), 'reasoning_reserve_tokens' => (int) env('REWRITE_REASONING_RESERVE_TOKENS', 0)],
            'retry' => ['output_tokens' => (int) env('REWRITE_RETRY_MAX_OUTPUT_TOKENS', 20_000), 'reasoning_reserve_tokens' => (int) env('REWRITE_RETRY_REASONING_RESERVE_TOKENS', env('REWRITE_REASONING_RESERVE_TOKENS', 0))],
            'final' => ['output_tokens' => (int) env('REWRITE_FINAL_RETRY_MAX_OUTPUT_TOKENS', 24_000), 'reasoning_reserve_tokens' => (int) env('REWRITE_FINAL_RETRY_REASONING_RESERVE_TOKENS', env('REWRITE_RETRY_REASONING_RESERVE_TOKENS', env('REWRITE_REASONING_RESERVE_TOKENS', 0)))],
        ],
        'summary' => [
            'initial' => ['output_tokens' => (int) env('SUMMARY_MAX_OUTPUT_TOKENS', 1_200), 'reasoning_reserve_tokens' => (int) env('SUMMARY_REASONING_RESERVE_TOKENS', 0)],
            'retry' => ['output_tokens' => (int) env('SUMMARY_RETRY_MAX_OUTPUT_TOKENS', 2_400), 'reasoning_reserve_tokens' => (int) env('SUMMARY_RETRY_REASONING_RESERVE_TOKENS', env('SUMMARY_REASONING_RESERVE_TOKENS', 0))],
            'final' => ['output_tokens' => (int) env('SUMMARY_FINAL_RETRY_MAX_OUTPUT_TOKENS', 4_000), 'reasoning_reserve_tokens' => (int) env('SUMMARY_FINAL_RETRY_REASONING_RESERVE_TOKENS', env('SUMMARY_RETRY_REASONING_RESERVE_TOKENS', env('SUMMARY_REASONING_RESERVE_TOKENS', 0)))],
        ],
    ],
    // 修复子请求拥有独立的可见输出与推理预留；父阶段大预算不能替代精确的子阶段合同。
    'chapter_repair_request_budgets' => [
        'extractor' => [
            'scene_structure' => [
                'initial' => ['output_tokens' => (int) env('SCENE_STRUCTURE_REPAIR_OUTPUT_TOKENS', 1_000), 'reasoning_reserve_tokens' => (int) env('SCENE_STRUCTURE_REPAIR_REASONING_RESERVE_TOKENS', 8_000)],
                'retry' => ['output_tokens' => (int) env('SCENE_STRUCTURE_REPAIR_RETRY_OUTPUT_TOKENS', 2_000), 'reasoning_reserve_tokens' => (int) env('SCENE_STRUCTURE_REPAIR_RETRY_REASONING_RESERVE_TOKENS', 16_000)],
            ],
            'coverage_evidence' => [
                'initial' => ['output_tokens' => (int) env('COVERAGE_EVIDENCE_REPAIR_OUTPUT_TOKENS', 1_000), 'reasoning_reserve_tokens' => (int) env('COVERAGE_EVIDENCE_REPAIR_REASONING_RESERVE_TOKENS', 8_000)],
                'retry' => ['output_tokens' => (int) env('COVERAGE_EVIDENCE_REPAIR_RETRY_OUTPUT_TOKENS', 2_000), 'reasoning_reserve_tokens' => (int) env('COVERAGE_EVIDENCE_REPAIR_RETRY_REASONING_RESERVE_TOKENS', 16_000)],
            ],
            'foreshadowing_coverage' => [
                'initial' => ['output_tokens' => (int) env('FORESHADOWING_COVERAGE_REPAIR_OUTPUT_TOKENS', 1_000), 'reasoning_reserve_tokens' => (int) env('FORESHADOWING_COVERAGE_REPAIR_REASONING_RESERVE_TOKENS', 8_000)],
                'retry' => ['output_tokens' => (int) env('FORESHADOWING_COVERAGE_REPAIR_RETRY_OUTPUT_TOKENS', 2_000), 'reasoning_reserve_tokens' => (int) env('FORESHADOWING_COVERAGE_REPAIR_RETRY_REASONING_RESERVE_TOKENS', 16_000)],
            ],
            'event_evidence' => [
                'initial' => ['output_tokens' => (int) env('EVENT_EVIDENCE_REPAIR_OUTPUT_TOKENS', 1_000), 'reasoning_reserve_tokens' => (int) env('EVENT_EVIDENCE_REPAIR_REASONING_RESERVE_TOKENS', 8_000)],
                'retry' => ['output_tokens' => (int) env('EVENT_EVIDENCE_REPAIR_RETRY_OUTPUT_TOKENS', 4_000), 'reasoning_reserve_tokens' => (int) env('EVENT_EVIDENCE_REPAIR_RETRY_REASONING_RESERVE_TOKENS', 16_000)],
            ],
        ],
        'reviewer' => [
            'coverage_judgment' => [
                'initial' => ['output_tokens' => (int) env('COVERAGE_JUDGMENT_OUTPUT_TOKENS', 1_500), 'reasoning_reserve_tokens' => (int) env('COVERAGE_JUDGMENT_REASONING_RESERVE_TOKENS', 8_000)],
                'retry' => ['output_tokens' => (int) env('COVERAGE_JUDGMENT_RETRY_OUTPUT_TOKENS', 3_000), 'reasoning_reserve_tokens' => (int) env('COVERAGE_JUDGMENT_RETRY_REASONING_RESERVE_TOKENS', 16_000)],
            ],
            'review_schema' => [
                'initial' => ['output_tokens' => (int) env('REVIEW_SCHEMA_REPAIR_OUTPUT_TOKENS', 1_500), 'reasoning_reserve_tokens' => (int) env('REVIEW_SCHEMA_REPAIR_REASONING_RESERVE_TOKENS', 8_000)],
            ],
            'arc_completion' => [
                'initial' => ['output_tokens' => (int) env('ARC_COMPLETION_REPAIR_OUTPUT_TOKENS', 1_000), 'reasoning_reserve_tokens' => (int) env('ARC_COMPLETION_REPAIR_REASONING_RESERVE_TOKENS', 8_000)],
            ],
        ],
        'rewrite' => [
            'coverage_evidence' => [
                'initial' => ['output_tokens' => (int) env('REWRITE_COVERAGE_REPAIR_OUTPUT_TOKENS', 1_000), 'reasoning_reserve_tokens' => (int) env('REWRITE_COVERAGE_REPAIR_REASONING_RESERVE_TOKENS', 8_000)],
                'retry' => ['output_tokens' => (int) env('REWRITE_COVERAGE_REPAIR_RETRY_OUTPUT_TOKENS', 2_000), 'reasoning_reserve_tokens' => (int) env('REWRITE_COVERAGE_REPAIR_RETRY_REASONING_RESERVE_TOKENS', 16_000)],
            ],
            'length_repair' => [
                'initial' => ['output_tokens' => (int) env('SCENE_LENGTH_REPAIR_OUTPUT_TOKENS', 4_000), 'reasoning_reserve_tokens' => (int) env('SCENE_LENGTH_REPAIR_REASONING_RESERVE_TOKENS', 8_000)],
                'retry' => ['output_tokens' => (int) env('SCENE_LENGTH_REPAIR_RETRY_OUTPUT_TOKENS', 8_000), 'reasoning_reserve_tokens' => (int) env('SCENE_LENGTH_REPAIR_RETRY_REASONING_RESERVE_TOKENS', 16_000)],
            ],
        ],
    ],
];
