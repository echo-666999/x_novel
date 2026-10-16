<?php

return [
    /* 每次实际 Provider 请求保存完整 Prompt、Schema 与参数，并以 checksum 防止同一 Run 漂移。 */
    'reproducibility' => [
        'schema_version' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider 阶段 Prompt 基础版本
    |--------------------------------------------------------------------------
    |
    | 这里只登记当前代码实际发送给 Provider 的 Prompt。有效版本还会由
    | PromptVersionResolver 追加该阶段使用的共享叙事策略版本，并共同进入
    | Generation Run、Context Snapshot、Input Hash 与幂等键。
    |
    */
    'versions' => [
        'outline_foundation' => 'novel-outline-foundation-v6',
        'outline_structure' => 'novel-outline-structure-v7',
        'outline_arc_beats' => 'novel-outline-arc-beats-v9',
        'outline_beat_detail' => 'novel-outline-beat-detail-v6',
        'planner' => 'chapter-planner-v14',
        'writer' => 'scene-writer-v16',
        'extractor' => 'event-extractor-v9',
        'reviewer' => 'reviewer-v20',
        'rewrite' => 'rewrite-v14',
        'summary' => 'summary-v2',
    ],

    /*
    |--------------------------------------------------------------------------
    | 共享叙事策略版本
    |--------------------------------------------------------------------------
    |
    | NarrativeProsePolicy 的四个方法独立演进。只修改其中一种策略时，只需
    | 升级对应版本，避免无关阶段的历史 Artifact 与 Resume 合同同时失效。
    |
    */
    'policies' => [
        'planning' => 'natural-planning-v3',
        'writing' => 'natural-writing-v3',
        'reviewing' => 'natural-reviewing-v3',
        'summary' => 'natural-summary-v3',
    ],

    /* 每个 Provider 阶段实际拼接的共享策略；未列出的阶段保持独立版本。 */
    'stage_policies' => [
        'outline_foundation' => 'planning',
        'outline_structure' => 'planning',
        'outline_arc_beats' => 'planning',
        'outline_beat_detail' => 'planning',
        'planner' => 'planning',
        'writer' => 'writing',
        'reviewer' => 'reviewing',
        'rewrite' => 'writing',
        'summary' => 'summary',
    ],

    /* 不属于固定 AiStage、但会直接调用 Provider 的局部任务版本。 */
    'tasks' => [
        'outline_node_regeneration' => [
            'version' => 'novel-outline-node-v6',
            'policy' => 'planning',
        ],
    ],
];
