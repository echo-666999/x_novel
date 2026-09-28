<?php

namespace Database\Factories;

use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Enums\NovelOutlineStatus;
use App\Enums\VolumeStatus;
use App\Models\Novel;
use App\Models\NovelOutlineVolume;
use App\Models\Volume;
use Database\Factories\Support\NormalizedOutlineDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Volume> */
class VolumeFactory extends Factory
{
    protected $model = Volume::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'source_outline_volume_id' => function (array $attributes): int {
                $novel = Novel::query()->findOrFail($attributes['novel_id']);
                $outline = $novel->outlines()->whereHas('milestones')->first()
                    ?? app(CreateNormalizedNovelOutlineVersionAction::class)->handle(
                        $novel,
                        NormalizedOutlineDefinition::make(),
                    );

                // 默认夹具采用同一 Current Outline，使 Planner 能验证运行态来源与权威定义一致。
                if ($novel->current_outline_id === null) {
                    $outline->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
                    $novel->update(['current_outline_id' => $outline->getKey()]);
                }

                $usedSourceIds = Volume::query()->where('novel_id', $novel->getKey())->pluck('source_outline_volume_id');
                $sourceVolume = $outline->volumes()->whereNotIn('id', $usedSourceIds)->first();
                if ($sourceVolume !== null) {
                    return $sourceVolume->getKey();
                }

                // 纯模型测试可能创建多个运行态 Volume；补充定义节点以维持唯一来源外键。
                $sequence = ((int) $outline->volumes()->max('sequence')) + 1;

                return NovelOutlineVolume::factory()->create([
                    'novel_outline_id' => $outline->getKey(),
                    'volume_key' => 'factory-volume-'.$sequence,
                    'sequence' => $sequence,
                ])->getKey();
            },
            'sequence' => fn (array $attributes): int => ((int) Volume::query()
                ->where('novel_id', $attributes['novel_id'])
                ->max('sequence')) + 1,
            'title' => fake()->words(3, true),
            'goal' => fake()->sentence(),
            'climax' => fake()->sentence(),
            'target_words' => 200_000,
            'status' => VolumeStatus::Planned,
            'summary' => null,
        ];
    }
}
