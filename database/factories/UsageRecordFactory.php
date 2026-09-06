<?php

namespace Database\Factories;

use App\Models\UsageRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageRecord> */
class UsageRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'generation_run_id' => null,
            'novel_id' => null,
            'chapter_id' => null,
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'input_tokens' => fake()->numberBetween(100, 2_000),
            'output_tokens' => fake()->numberBetween(100, 2_000),
            'cached_tokens' => 0,
            'latency_ms' => fake()->numberBetween(100, 5_000),
            'estimated_cost' => fake()->randomFloat(6, 0, 1),
            'request_id' => fake()->uuid(),
        ];
    }
}
