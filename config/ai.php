<?php

return [
    'provider' => env('AI_PROVIDER', 'openai'),
    'model' => env('AI_MODEL', 'gpt-4.1-mini'),

    'providers' => [
        'openai' => [
            'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('AI_API_KEY'),
            'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),
            'timeout' => (int) env('AI_TIMEOUT', 60),
        ],
    ],
];
