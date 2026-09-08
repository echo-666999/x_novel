<?php

return [
    'styles' => [
        'accessible_brisk' => ['name' => '通俗爽快', 'instruction' => '语言直接易读，以短句和自然对白推动冲突，减少冗长解释与修辞。', 'parameters' => [2, 4, 2, 2, 2, 1]],
        'passionate' => ['name' => '热血激昂', 'instruction' => '使用有力度的动词和鲜明情绪，突出行动、对抗与胜负反馈，避免空喊口号。', 'parameters' => [3, 3, 3, 2, 1, 2]],
        'light_humorous' => ['name' => '轻松幽默', 'instruction' => '语言口语自然，以人物反差、机智对白和情境幽默制造轻松感。', 'parameters' => [2, 4, 2, 2, 4, 2]],
        'absurd_comedy' => ['name' => '沙雕搞笑', 'instruction' => '允许夸张、反套路和密集包袱，但笑点必须服务人物与剧情，避免无关梗堆砌。', 'parameters' => [2, 5, 2, 1, 5, 1]],
        'austere' => ['name' => '冷峻克制', 'instruction' => '使用简短有力的句子，少解释、少煽情，以动作、细节和留白表达压力。', 'parameters' => [2, 2, 3, 2, 1, 3]],
        'steady_weighty' => ['name' => '沉稳厚重', 'instruction' => '语言沉稳克制，强调真实感、时代感和事件重量，以扎实细节建立可信度。', 'parameters' => [3, 2, 3, 2, 1, 3]],
        'delicate_emotional' => ['name' => '细腻情感', 'instruction' => '细致呈现心理、微表情和关系变化，让情绪通过具体反应逐步累积。', 'parameters' => [3, 3, 3, 5, 2, 3]],
        'warm_healing' => ['name' => '温馨治愈', 'instruction' => '语言柔和生活化，重视日常互动与烟火气，让温暖来自具体行动。', 'parameters' => [2, 4, 3, 3, 3, 2]],
        'painful_oppressive' => ['name' => '虐心压抑', 'instruction' => '强化困境、心理拉扯和压迫感，保持情绪因果，避免为虐而虐。', 'parameters' => [3, 2, 4, 5, 1, 3]],
        'suspenseful' => ['name' => '悬疑紧张', 'instruction' => '严格控制信息释放，使用清晰线索、短句和段尾悬念维持阅读推动力。', 'parameters' => [2, 3, 4, 3, 1, 2]],
        'uncanny_horror' => ['name' => '诡谲惊悚', 'instruction' => '通过环境、感官和未知细节制造持续不安，避免直接解释全部异常。', 'parameters' => [4, 2, 5, 3, 1, 4]],
        'classical_elegant' => ['name' => '古典雅致', 'instruction' => '用词含蓄雅致并重视意境，保持可读性，避免堆砌典故与过度文言。', 'parameters' => [5, 2, 4, 3, 1, 5]],
        'plain_realist' => ['name' => '白描写实', 'instruction' => '少用修辞，以准确动作、生活细节和自然对白呈现人物与环境。', 'parameters' => [1, 4, 4, 2, 1, 2]],
        'epic_grand' => ['name' => '史诗宏大', 'instruction' => '长短句结合，兼顾个人命运、群像和世界尺度，重大事件保持恢弘与重量。', 'parameters' => [4, 2, 4, 3, 1, 4]],
    ],
    'parameter_keys' => [
        'ornateness', 'dialogue_ratio', 'description_density',
        'psychology_density', 'humor_level', 'literary_level',
    ],
    'language_eras' => [
        'modern_spoken' => '现代口语', 'modern_literary' => '现代文学', 'period' => '年代感',
        'semi_classical' => '半白半古', 'vernacular_ancient' => '古风白话', 'classical' => '古典',
    ],
    'paces' => ['slow_burn' => '慢热', 'relaxed' => '舒缓', 'balanced' => '适中', 'fast' => '快节奏', 'rapid' => '极速爽文'],
    'tones' => ['light' => '轻松', 'passionate' => '热血', 'dark' => '黑暗', 'warm' => '温暖', 'oppressive' => '压抑', 'serious' => '正剧'],
    'povs' => ['first_person' => '第一人称', 'third_limited' => '第三人称限知', 'third_omniscient' => '第三人称全知', 'multi_pov' => '多视角限知'],
    'platforms' => ['general' => '通用网文', 'qidian' => '起点中文网', 'fanqie' => '番茄小说', 'jinjiang' => '晋江文学城', 'qimao' => '七猫小说', 'other' => '其他'],
];
