<?php

return [
    // 只允许部署预定义引用；不读取任意用户提供的环境变量名。
    'credentials' => [
        'openai' => env('XNOVEL_OPENAI_API_KEY'),
        'deepseek' => env('XNOVEL_DEEPSEEK_API_KEY'),
    ],
    // Prompt 正文是否写入日志属于部署级安全策略，不允许在运行中的后台界面切换。
    'logging' => [
        'prompts' => env('AI_LOG_PROMPTS', false),
    ],
];
