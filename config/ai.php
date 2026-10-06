<?php

return [
    'provider' => env('AI_PROVIDER', 'openai'),
    'model' => env('AI_MODEL', 'gpt-5.6-luna'),

    'logging' => [
        'prompts' => env('AI_LOG_PROMPTS', false),
    ],

    // 管理后台维护的数据库模型路由优先于这里的环境变量。
    // 四个 Outline Stage 必须各自配置，禁止回退到 Planner 或通用 AI_MODEL。
    'models' => [
        'outline_foundation' => env('AI_MODEL_OUTLINE_FOUNDATION'),
        'outline_structure' => env('AI_MODEL_OUTLINE_STRUCTURE'),
        'outline_arc_beats' => env('AI_MODEL_OUTLINE_ARC_BEATS'),
        'outline_beat_detail' => env('AI_MODEL_OUTLINE_BEAT_DETAIL'),
        'planner' => env('AI_MODEL_PLANNER', env('AI_MODEL', 'gpt-5.6-luna')),
        'writer' => env('AI_MODEL_WRITER', env('AI_MODEL', 'gpt-5.6-luna')),
        'extractor' => env('AI_MODEL_EXTRACTOR', env('AI_MODEL', 'gpt-5.6-luna')),
        'reviewer' => env('AI_MODEL_REVIEWER', env('AI_MODEL', 'gpt-5.6-luna')),
        'rewrite' => env('AI_MODEL_REWRITE', env('AI_MODEL', 'gpt-5.6-luna')),
        'summary' => env('AI_MODEL_SUMMARY', env('AI_MODEL', 'gpt-5.6-luna')),
    ],

    'stage_providers' => [
        'outline_foundation' => env('AI_PROVIDER_OUTLINE_FOUNDATION'),
        'outline_structure' => env('AI_PROVIDER_OUTLINE_STRUCTURE'),
        'outline_arc_beats' => env('AI_PROVIDER_OUTLINE_ARC_BEATS'),
        'outline_beat_detail' => env('AI_PROVIDER_OUTLINE_BEAT_DETAIL'),
    ],

    'reasoning_efforts' => [
        'outline_foundation' => env('AI_REASONING_EFFORT_OUTLINE_FOUNDATION'),
        'outline_structure' => env('AI_REASONING_EFFORT_OUTLINE_STRUCTURE'),
        'outline_arc_beats' => env('AI_REASONING_EFFORT_OUTLINE_ARC_BEATS'),
        'outline_beat_detail' => env('AI_REASONING_EFFORT_OUTLINE_BEAT_DETAIL'),
    ],

    'cost' => [
        'currency' => env('AI_COST_CURRENCY', 'USD'),
        'input_per_million' => (float) env('AI_INPUT_COST_PER_MILLION', 0),
        'cached_input_per_million' => (float) env('AI_CACHED_INPUT_COST_PER_MILLION', 0),
        'output_per_million' => (float) env('AI_OUTPUT_COST_PER_MILLION', 0),
    ],

    'budget' => [
        'daily_hard_limit' => env('AI_DAILY_HARD_LIMIT'),
        'novel_total_limit' => env('AI_NOVEL_TOTAL_LIMIT'),
        'chapter_max_cost' => env('AI_CHAPTER_MAX_COST'),
    ],

    'embedding' => [
        'provider' => 'openai',
        'model' => env('AI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1536),
    ],

    'providers' => [
        'openai' => [
            'enabled' => true,
            'base_url' => env('OPENAI_BASE_URL') ?: env('AI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY') ?: env('AI_API_KEY'),
            'connect_timeout' => (int) (env('OPENAI_CONNECT_TIMEOUT') ?: env('AI_CONNECT_TIMEOUT', 10)),
            'timeout' => (int) (env('OPENAI_TIMEOUT') ?: env('AI_TIMEOUT', 150)),
            'using_legacy_base_url' => ! env('OPENAI_BASE_URL') && filled(env('AI_BASE_URL')),
            'using_legacy_api_key' => ! env('OPENAI_API_KEY') && filled(env('AI_API_KEY')),
        ],
        'deepseek' => [
            'enabled' => false,
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'connect_timeout' => (int) env('DEEPSEEK_CONNECT_TIMEOUT', 10),
            'timeout' => (int) env('DEEPSEEK_TIMEOUT', 150),
        ],
    ],
];
