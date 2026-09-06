<?php

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChapterPlan> */
class ChapterPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'version' => 1,
            'chapter_function' => fake()->sentence(),
            'arc_contribution' => fake()->sentence(),
            'reader_promise' => fake()->sentence(),
            'target_words' => 3_000,
            'pov_character_id' => null,
            'tone' => '紧张',
            'time_anchor' => '当日傍晚',
            'hook_type' => '悬念',
            'must_reveal' => [],
            'may_hint' => [],
            'must_not_reveal' => [],
            'required_facts' => [],
            'forbidden_conflicts' => [],
            'due_foreshadowings' => [],
            'scene_plans' => [[
                'goal' => fake()->sentence(),
                'conflict' => fake()->sentence(),
                'turn' => fake()->sentence(),
                'outcome' => fake()->sentence(),
            ]],
            'status' => PlanStatus::Draft,
        ];
    }
}
