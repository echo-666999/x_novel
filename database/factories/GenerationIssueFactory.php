<?php

namespace Database\Factories;

use App\Models\NovelGenerationTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 问题夹具继承任务归属，防止伪造跨小说外键。 */
class GenerationIssueFactory extends Factory
{
    public function definition(): array
    {
        return ['generation_task_id' => NovelGenerationTask::factory(),
            'novel_id' => fn (array $attributes) => NovelGenerationTask::findOrFail($attributes['generation_task_id'])->novel_id,
            'fingerprint' => hash('sha256', fake()->uuid()), 'category' => 'technical',
            'error_code' => 'test_timeout', 'user_message' => '测试请求超时', 'status' => 'open'];
    }
}
