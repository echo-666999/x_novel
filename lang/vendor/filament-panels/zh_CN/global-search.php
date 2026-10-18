<?php

// 补齐当前 Filament 的搜索无障碍提示，避免把翻译键显示给用户。
return [
    'field' => [
        'label' => '全局搜索',
        'placeholder' => '搜索',
        'instructions' => '按向下方向键浏览结果，上下方向键切换，Tab 进入操作，Escape 关闭结果。',
    ],
    'results_label' => '全局搜索结果',
    'results_count' => '显示 :count 条结果。',
    'loading_message' => '正在搜索…',
    'failure_message' => '搜索未完成，请刷新页面后重试。',
    'no_results_message' => '未找到搜索结果。',
];
