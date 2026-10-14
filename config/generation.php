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
    'outline_request_budgets' => [
        'outline_foundation' => [
            // 历史 8k 总预算曾被推理全部耗尽；12k 总预算的成功样本又已接近上限，因此同时扩充可见输出与推理空间。
            'output_tokens' => 12_000,
            'reasoning_reserve_tokens' => 8_000,
        ],
        'outline_structure' => [
            'output_tokens' => 12_000,
            'reasoning_reserve_tokens' => 4_000,
        ],
        'outline_arc_beats' => [
            'output_tokens' => 8_000,
            'reasoning_reserve_tokens' => 4_000,
        ],
        'outline_beat_detail' => [
            'output_tokens' => 5_000,
            'reasoning_reserve_tokens' => 3_000,
        ],
    ],
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
    // Chapter Pipeline 将可见输出与推理预留分开冻结；旧 max_output 配置继续表示可见输出额度。
    'chapter_request_budgets' => [
        'planner' => [
            // 当前 high 推理路由已有 1,809 Token 的实际推理消耗；递增预留使推理耗尽后能够进入更高档。
            'initial' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 4_000],
            'retry' => ['output_tokens' => 16_000, 'reasoning_reserve_tokens' => 8_000],
            'final' => ['output_tokens' => 24_000, 'reasoning_reserve_tokens' => 16_000],
        ],
        'writer' => [
            // DeepSeek 留空 reasoning_effort 时仍默认 high；Writer 必须为隐藏推理预留递增空间。
            'initial' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 8_000],
            'retry' => ['output_tokens' => 16_000, 'reasoning_reserve_tokens' => 16_000],
            'final' => ['output_tokens' => 24_000, 'reasoning_reserve_tokens' => 24_000],
        ],
        'extractor' => [
            // 28k 总预算样本曾消耗 26,559 推理 Token 后截断，40k 总预算随后成功；提高首档并保留逐档升级空间。
            'initial' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 32_000],
            'retry' => ['output_tokens' => 8_000, 'reasoning_reserve_tokens' => 40_000],
            'final' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 48_000],
        ],
        'reviewer' => [
            // 当前 high 推理审校样本最高使用 4,421 推理 Token；Reviewer 只有单档，需在首次请求中直接预留。
            'initial' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 8_000],
        ],
        'rewrite' => [
            // DeepSeek 重写样本最高使用 7,591 推理 Token；递增预留避免总预算被隐藏推理提前占满。
            'initial' => ['output_tokens' => 16_000, 'reasoning_reserve_tokens' => 8_000],
            'retry' => ['output_tokens' => 20_000, 'reasoning_reserve_tokens' => 16_000],
            'final' => ['output_tokens' => 24_000, 'reasoning_reserve_tokens' => 24_000],
        ],
        'summary' => [
            // DeepSeek 留空 reasoning_effort 时仍可能默认推理；Summary 同样需要冻结隐藏推理空间。
            'initial' => ['output_tokens' => 1_200, 'reasoning_reserve_tokens' => 8_000],
            'retry' => ['output_tokens' => 2_400, 'reasoning_reserve_tokens' => 16_000],
            'final' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 24_000],
        ],
    ],
    // 修复子请求拥有独立的可见输出与推理预留；父阶段大预算不能替代精确的子阶段合同。
    'chapter_repair_request_budgets' => [
        'extractor' => [
            'scene_structure' => [
                'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
                'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 16_000],
            ],
            'coverage_evidence' => [
                // 9k 总预算在近期样本中反复耗尽，18k 档成功；将成功档能力前移并保留一次增量重试。
                'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 16_000],
                'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 24_000],
            ],
            'foreshadowing_coverage' => [
                'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
                'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 16_000],
            ],
            'event_evidence' => [
                'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
                'retry' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 16_000],
            ],
        ],
        'reviewer' => [
            'coverage_judgment' => [
                'initial' => ['output_tokens' => 1_500, 'reasoning_reserve_tokens' => 8_000],
                'retry' => ['output_tokens' => 3_000, 'reasoning_reserve_tokens' => 16_000],
            ],
            'review_schema' => [
                'initial' => ['output_tokens' => 1_500, 'reasoning_reserve_tokens' => 8_000],
            ],
            'arc_completion' => [
                'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
            ],
        ],
        'rewrite' => [
            'coverage_evidence' => [
                'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
                'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 16_000],
            ],
            'length_repair' => [
                'initial' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 8_000],
                'retry' => ['output_tokens' => 8_000, 'reasoning_reserve_tokens' => 16_000],
            ],
        ],
    ],
];
