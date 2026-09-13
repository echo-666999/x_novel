<?php

return [
    'chapter_target_words' => 42,
    'scenes' => [
        [
            'goal' => '冲进旧港寻找可用的小舟',
            'conflict' => '守门人横刀阻拦',
            'turn' => '潮钟突然响起',
            'outcome' => '主角闯过港门',
            'outcome_allowed' => ['主角越过守门人'],
            'outcome_forbidden' => ['主角退出旧港'],
            'location' => '旧港',
            'time_anchor' => '黄昏',
            'content' => '我冲进旧港，守门人横刀挡路，潮钟忽然震响。',
            'rewritten_content' => '我撞开旧港，守门人横刀挡路，潮钟忽然震响。',
        ],
        [
            'goal' => '穿过封锁线抵达灯塔',
            'conflict' => '巡逻船从后追击',
            'turn' => '雾中显出灯塔轮廓',
            'outcome' => '主角进入灯塔水域',
            'outcome_allowed' => ['主角驶向灯塔'],
            'outcome_forbidden' => ['主角返回旧港'],
            'location' => '禁航水域',
            'time_anchor' => '入夜',
            'content' => '我夺下小舟，穿过封锁，终于驶向雾中灯塔。',
        ],
    ],
    'expected' => [
        'scene_plan_adherence' => [
            'goal' => 'fulfilled',
            'conflict' => 'fulfilled',
            'turn' => 'fulfilled',
            'outcome' => 'fulfilled',
        ],
        'assembly_plan_findings' => 0,
        'final_style_findings' => 0,
    ],
];
