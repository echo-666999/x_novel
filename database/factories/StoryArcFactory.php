<?php

namespace Database\Factories;

use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\NovelOutlineArc;
use App\Models\NovelOutlineVolume;
use App\Models\StoryArc;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoryArc> */
class StoryArcFactory extends Factory
{
    protected $model = StoryArc::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'volume_id' => null,
            'source_outline_arc_id' => function (array $attributes): int {
                // 运行态 Arc 的测试定义必须挂到同一本小说的既有关系化 Outline。
                if (filled($attributes['volume_id'] ?? null)) {
                    $sourceVolume = Volume::query()->findOrFail($attributes['volume_id'])->sourceOutlineVolume;
                } else {
                    $outline = NovelOutline::query()->where('novel_id', $attributes['novel_id'])->first()
                        ?? NovelOutline::factory()->create(['novel_id' => $attributes['novel_id']]);
                    $sourceVolume = NovelOutlineVolume::query()->where('novel_outline_id', $outline->getKey())->first()
                        ?? NovelOutlineVolume::factory()->create(['novel_outline_id' => $outline->getKey()]);
                }

                $usedSourceIds = StoryArc::query()
                    ->where('novel_id', $attributes['novel_id'])
                    ->pluck('source_outline_arc_id');
                $sourceArc = $sourceVolume->arcs()->whereNotIn('id', $usedSourceIds)->first();
                if ($sourceArc !== null) {
                    return $sourceArc->getKey();
                }

                $sequence = ((int) NovelOutlineArc::query()
                    ->where('novel_outline_volume_id', $sourceVolume->getKey())
                    ->max('sequence')) + 1;
                $mainlineSequence = ((int) NovelOutlineArc::query()
                    ->where('novel_outline_id', $sourceVolume->novel_outline_id)
                    ->whereNotNull('mainline_sequence')
                    ->max('mainline_sequence')) + 1;

                return NovelOutlineArc::factory()->create([
                    'novel_outline_id' => $sourceVolume->novel_outline_id,
                    'novel_outline_volume_id' => $sourceVolume->getKey(),
                    'arc_key' => 'factory-arc-'.$sourceVolume->getKey().'-'.$sequence,
                    'sequence' => $sequence,
                    'mainline_sequence' => $mainlineSequence,
                ])->getKey();
            },
            'sequence' => fn (array $attributes): int => ((int) StoryArc::query()
                ->where('novel_id', $attributes['novel_id'])
                ->where('volume_id', $attributes['volume_id'] ?? null)
                ->max('sequence')) + 1,
            'type' => StoryArcType::Main,
            'title' => fake()->sentence(4),
            'goal' => fake()->sentence(),
            'stakes' => fake()->sentence(),
            'completion_conditions' => [fake()->sentence()],
            'progress' => 0,
            'status' => StoryArcStatus::Planned,
        ];
    }

    public function forVolume(Volume $volume): static
    {
        return $this->state(fn (): array => [
            'novel_id' => $volume->novel_id,
            'volume_id' => $volume->getKey(),
            'source_outline_arc_id' => function () use ($volume): int {
                $sourceVolume = $volume->sourceOutlineVolume;
                $usedSourceIds = StoryArc::query()
                    ->where('novel_id', $volume->novel_id)
                    ->pluck('source_outline_arc_id');
                $sourceArc = $sourceVolume->arcs()->whereNotIn('id', $usedSourceIds)->first();
                if ($sourceArc !== null) {
                    return $sourceArc->getKey();
                }

                $sequence = ((int) NovelOutlineArc::query()
                    ->where('novel_outline_volume_id', $sourceVolume->getKey())
                    ->max('sequence')) + 1;
                $mainlineSequence = ((int) NovelOutlineArc::query()
                    ->where('novel_outline_id', $sourceVolume->novel_outline_id)
                    ->whereNotNull('mainline_sequence')
                    ->max('mainline_sequence')) + 1;

                return NovelOutlineArc::factory()->create([
                    'novel_outline_id' => $sourceVolume->novel_outline_id,
                    'novel_outline_volume_id' => $sourceVolume->getKey(),
                    'arc_key' => 'factory-arc-'.$sourceVolume->getKey().'-'.$sequence,
                    'sequence' => $sequence,
                    'mainline_sequence' => $mainlineSequence,
                ])->getKey();
            },
        ]);
    }
}
