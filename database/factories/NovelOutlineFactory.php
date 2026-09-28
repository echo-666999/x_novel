<?php

namespace Database\Factories;

use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Models\Novel;
use App\Models\NovelOutline;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NovelOutline> */
class NovelOutlineFactory extends Factory
{
    protected $model = NovelOutline::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            // 同一本小说的测试夹具也必须遵守版本唯一约束，避免工厂级联创建时固定写入 version=1。
            'version' => fn (array $attributes): int => ((int) NovelOutline::query()
                ->where('novel_id', $attributes['novel_id'])
                ->max('version')) + 1,
            'status' => NovelOutlineStatus::Draft,
            'source' => NovelOutlineSource::Manual,
            'schema_version' => 1,
            'title' => fake()->sentence(4),
            'summary' => fake()->sentence(),
            'must_include' => [],
            'must_not_include' => [],
            // 没有子节点的 Factory 只用于模型关系测试；完整版本应通过创建 Action 建立。
            'checksum' => hash('sha256', 'empty-outline-definition'),
            'source_artifact_id' => null,
            'based_on_outline_id' => null,
            'created_by' => null,
            'applied_at' => null,
        ];
    }
}
