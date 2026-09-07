<?php

return [
    'provider' => env('AI_PROVIDER', 'openai'),
    'model' => env('AI_MODEL', 'gpt-4.1-mini'),

    'models' => [
        'planner' => env('AI_MODEL_PLANNER', env('AI_MODEL', 'gpt-4.1-mini')),
        'writer' => env('AI_MODEL_WRITER', env('AI_MODEL', 'gpt-4.1-mini')),
        'assembler' => env('AI_MODEL_ASSEMBLER', env('AI_MODEL', 'gpt-4.1-mini')),
        'extractor' => env('AI_MODEL_EXTRACTOR', env('AI_MODEL', 'gpt-4.1-mini')),
        'reviewer' => env('AI_MODEL_REVIEWER', env('AI_MODEL', 'gpt-4.1-mini')),
        'rewrite' => env('AI_MODEL_REWRITE', env('AI_MODEL', 'gpt-4.1-mini')),
        'summary' => env('AI_MODEL_SUMMARY', env('AI_MODEL', 'gpt-4.1-mini')),
        'embedding' => env('AI_MODEL_EMBEDDING', env('AI_MODEL', 'gpt-4.1-mini')),
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
        'model' => env('AI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1536),
    ],

    'providers' => [
        'openai' => [
            'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('AI_API_KEY'),
            'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),
            'timeout' => (int) env('AI_TIMEOUT', 60),
        ],
    ],
];
