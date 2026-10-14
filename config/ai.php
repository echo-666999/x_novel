<?php

return [
    // Prompt 正文是否写入日志属于部署级安全策略，不允许在运行中的后台界面切换。
    'logging' => [
        'prompts' => env('AI_LOG_PROMPTS', false),
    ],
];
