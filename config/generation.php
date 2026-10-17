<?php

return [
    // 新链一次请求一个 Run；队列等待不累计为模型执行时间。
    'execution' => ['max_attempts' => 3,
        'protocol_margin_tokens' => 256, 'lease_seconds' => 390, 'dispatch_seconds' => 120, 'backoff' => [10, 30]],
    /*
    |--------------------------------------------------------------------------
    | 生成阶段失败与重试策略
    |--------------------------------------------------------------------------
    |
    | max_attempts：Queue 最多执行次数，包含首次执行。
    | backoff：每次重试前的等待秒数，按数组顺序应用。
    | repairs：该阶段允许调用的局部修复类型；空数组表示不调用修复器。
    | non_terminal_codes：遇到这些 Provider 错误码时，不把当前阶段直接标记为终态失败。
    |
    */
    'stage_policies' => [
        // 章节规划：生成并校验本章的 Chapter Plan。
        'chapter_planning' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => [], 'non_terminal_codes' => ['novel_paused']],
        // 场景生成：按顺序生成 Scene Draft，并允许结构、证据和长度修复。
        'scene_generation' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => ['schema', 'evidence', 'length'], 'non_terminal_codes' => ['novel_paused', 'previous_scene_incomplete']],
        // 章节组装：合并已完成的 Scene Draft，并允许长度修复。
        'chapter_assembly' => ['max_attempts' => 2, 'backoff' => [10], 'repairs' => ['length'], 'non_terminal_codes' => ['novel_paused', 'assembly_input_incomplete', 'assembly_scene_incomplete', 'assembly_context_incomplete']],
        // 事件提取：从章节草稿中提取会影响后续状态的事件，并允许证据修复。
        'event_extraction' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => ['evidence'], 'non_terminal_codes' => ['novel_paused']],
        // Coverage 语义复核是独立 Provider 阶段，技术失败不能伪装成正文缺失后进入 Rewrite。
        'coverage_judgment' => ['max_attempts' => 2, 'backoff' => [10], 'repairs' => [], 'non_terminal_codes' => ['novel_paused']],
        // 章节审校：在确定性前置校验和 Coverage 复核完成后生成 Review。
        'review' => ['max_attempts' => 2, 'backoff' => [10], 'repairs' => ['contract', 'evidence'], 'non_terminal_codes' => ['novel_paused', 'review_prerequisite_missing', 'coverage_judgment_pending', 'review_contract_output_invalid', 'review_evidence_output_invalid']],
        // 审校合同修复：独立补齐模型漏掉的冻结审计数组，不修改正文或其他审校结论。
        'review_repair' => ['max_attempts' => 1, 'backoff' => [], 'repairs' => [], 'non_terminal_codes' => ['novel_paused']],
        // Rewrite 有 initial/retry/final 三档技术预算，Queue 必须允许三次投递才能真正到达最终档。
        'rewrite' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => ['evidence', 'length'], 'non_terminal_codes' => ['novel_paused', 'rewrite_exhausted', 'coverage_judgment_required']],
        // 正式提交：通过 Canonical Commit 将已通过审校的章节写入正式故事状态。
        'commit' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => []],
        // 正式摘要：为 Canonical Chapter 生成后续上下文与记忆所需的摘要。
        'memory_summary' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => []],
        // 向量化：为正式记忆生成 Embedding。
        'embedding' => ['max_attempts' => 3, 'backoff' => [10, 30], 'repairs' => []],
        // 结局审计：检查 Ending Contract 和收束债务是否满足完结条件。
        'ending_audit' => ['max_attempts' => 2, 'backoff' => [10], 'repairs' => []],
    ],

    // 是否显示仅供验收使用的生成诊断工具；默认不显示。
    'acceptance_tools_enabled' => (bool) env('GENERATION_ACCEPTANCE_TOOLS_ENABLED', false),

    // Generation Run 在多久未更新后可判定为 Worker 丢失或任务卡死，单位：秒。
    'stalled_run_after_seconds' => (int) env('STALLED_RUN_AFTER_SECONDS', 480),

    // queued Run 必须在该时间内被 Worker 认领；过期后由定时恢复任务重新投递，单位：秒。
    'queued_run_lease_seconds' => 300,

    // 生成 Job 的唯一锁和重复派发抑制时长，单位：秒。
    'pending_job_seconds' => (int) env('GENERATION_PENDING_JOB_SECONDS', 900),

    // Worker 丢失或提交后断点允许的自动恢复次数；达到上限后持久化安全停止原因。
    'automatic_recovery' => [
        'max_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | 内容自愈硬上限
    |--------------------------------------------------------------------------
    |
    | max_attempts 按章节和修复类型独立累计；max_cost 已停用，金额限制统一由用户主动策略决定；
    | max_elapsed_seconds 从修复审计 Run 创建时开始计算，并覆盖队列等待时间。
    | Provider 请求会按“已发生费用 + 本次最大可能费用”预检，不能透支修复额度。
    |
    */
    'content_self_healing' => [
        'plan_validation' => ['max_attempts' => 2, 'max_cost' => null, 'max_elapsed_seconds' => 900],
        'chapter_structure' => ['max_attempts' => 1, 'max_cost' => null, 'max_elapsed_seconds' => 1_800],
        'rewrite_exhaustion' => ['max_attempts' => 1, 'max_cost' => null, 'max_elapsed_seconds' => 1_800],
        'critical_foreshadowing' => ['max_attempts' => 1, 'max_cost' => null, 'max_elapsed_seconds' => 900],
    ],

    // 单个场景构建 Prompt 上下文时可使用的 Token 预算。
    'scene_context_token_budget' => (int) env('SCENE_CONTEXT_TOKEN_BUDGET', 12_000),

    // 单个场景生成 Job 的软执行时限，单位：秒；发起 Provider 请求前会检查剩余时间。
    'scene_job_soft_timeout_seconds' => (int) env('SCENE_JOB_SOFT_TIMEOUT_SECONDS', 300),

    // 场景 Job 为 Provider 请求完成和本地收尾预留的安全时间，单位：秒。
    'scene_job_safety_margin_seconds' => (int) env('SCENE_JOB_SAFETY_MARGIN_SECONDS', 20),

    // Scene Draft 未达到长度要求时，最多执行的长度修复次数。
    // Scene 长度修复对应已冻结的 initial / retry 两档预算；两档都失败后才终止。
    'max_length_repair_attempts' => 2,

    // Rewrite Draft 未达到长度要求时，最多执行的长度修复次数。
    'max_rewrite_length_repair_attempts' => 2,

    // Plan 与伏笔 Coverage 缺少可验证证据时，最多执行的证据修复次数。
    'max_coverage_repair_attempts' => 2,

    // Scene Draft 结构不符合契约时，最多执行的结构修复次数。
    'max_scene_structure_repair_attempts' => 2,

    // 传给下一场景作为衔接上下文的上一场景末尾字符数。
    'previous_scene_tail_characters' => (int) env('PREVIOUS_SCENE_TAIL_CHARACTERS', 1_000),

    // 章节正文允许的最小字数比例，相对于 Chapter Plan 的目标字数。
    'chapter_min_length_ratio' => .85,

    // 章节正文允许的最大字数比例，相对于 Chapter Plan 的目标字数。
    'chapter_max_length_ratio' => 1.15,

    // Story Event 缺少正文逐字证据时，最多执行的证据修复次数。
    'max_event_evidence_repair_attempts' => 2,

    // Review 加权分数达到该值后，才满足自动通过的分数条件。
    'review_pass_score' => (float) env('REVIEW_PASS_SCORE', 80),

    // 同一章节最多允许执行的自动 Rewrite 次数。
    'max_rewrite_attempts' => (int) env('MAX_REWRITE_ATTEMPTS', 2),
];
