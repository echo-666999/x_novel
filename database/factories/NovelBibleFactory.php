<?php

namespace Database\Factories;

use App\Enums\BibleStatus;
use App\Models\Novel;
use App\Models\NovelBible;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NovelBible> */
class NovelBibleFactory extends Factory
{
    protected $model = NovelBible::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'version' => 1,
            'logline' => fake()->sentence(),
            'themes' => ['成长', '选择'],
            'tone' => '克制而紧张',
            'pov' => '第三人称限知',
            'tense' => '过去时',
            'taboos' => ['机械降神'],
            'hard_constraints' => ['主角不能复活'],
            'ending_contract' => [
                'final_protagonist_state' => '完成成长并承担选择的后果',
                'main_conflict_resolution' => '主线冲突得到明确解决',
                'theme_payoff' => '选择必须伴随后果',
                'required_foreshadowing_payoff' => ['关键线索得到兑现'],
                'character_arc_requirements' => ['主角完成从逃避到承担的转变'],
                'allowed_open_endings' => ['次要角色的远行可以保留开放性'],
            ],
            'status' => BibleStatus::Current,
        ];
    }
}
