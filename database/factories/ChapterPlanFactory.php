<?php

namespace Database\Factories;

use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\Enums\NovelOutlineStatus;
use App\Enums\PlanStatus;
use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\NovelOutlineArc;
use App\Models\NovelOutlineBeat;
use App\Models\NovelOutlineMilestone;
use App\Models\StoryArc;
use App\Models\Volume;
use Database\Factories\Support\NormalizedOutlineDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChapterPlan> */
class ChapterPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'novel_outline_id' => function (array $attributes): int {
                $chapter = Chapter::query()->findOrFail($attributes['chapter_id']);
                $novel = $chapter->novel;
                $outline = $chapter->volume?->sourceOutlineVolume?->outline;
                if ($outline?->milestones()->exists() !== true) {
                    $outline = $novel->outlines()->whereHas('milestones')->first();
                }

                if ($outline === null) {
                    $outline = app(CreateNormalizedNovelOutlineVersionAction::class)->handle(
                        $novel,
                        NormalizedOutlineDefinition::make(),
                    );
                }

                // Chapter Plan Factory 建立可执行夹具时同步 Current 指针，符合运行时入口约束。
                $novel->outlines()->whereKeyNot($outline->getKey())->update(['status' => NovelOutlineStatus::Superseded]);
                $outline->update(['status' => NovelOutlineStatus::Current, 'applied_at' => now()]);
                $novel->update(['current_outline_id' => $outline->getKey()]);

                $sourceVolume = $outline->volumes()->firstOrFail();
                $volume = $chapter->volume;
                if ($volume === null) {
                    $volume = Volume::query()->firstOrCreate(
                        ['source_outline_volume_id' => $sourceVolume->getKey()],
                        [
                            'novel_id' => $novel->getKey(),
                            'sequence' => $sourceVolume->sequence,
                            'title' => $sourceVolume->title,
                            'goal' => $sourceVolume->goal,
                            'climax' => $sourceVolume->climax,
                            'target_words' => $sourceVolume->target_words,
                            'status' => VolumeStatus::Active,
                        ],
                    );
                    $chapter->update(['volume_id' => $volume->getKey()]);
                } else {
                    $volume->update(['status' => VolumeStatus::Active]);
                }

                $sourceArc = $sourceVolume->arcs()->whereNotNull('mainline_sequence')->firstOrFail();
                StoryArc::query()->firstOrCreate(
                    ['source_outline_arc_id' => $sourceArc->getKey()],
                    [
                        'novel_id' => $novel->getKey(),
                        'volume_id' => $volume->getKey(),
                        'sequence' => $sourceArc->sequence,
                        'type' => $sourceArc->type,
                        'title' => $sourceArc->title,
                        'goal' => $sourceArc->goal,
                        'stakes' => $sourceArc->stakes,
                        'completion_conditions' => $sourceArc->completion_conditions,
                        'progress' => 0,
                        'status' => StoryArcStatus::Active,
                    ],
                );

                return $outline->getKey();
            },
            'primary_outline_arc_id' => fn (array $attributes): int => NovelOutlineArc::query()
                ->where('novel_outline_id', $attributes['novel_outline_id'])
                ->whereNotNull('mainline_sequence')->orderBy('mainline_sequence')->firstOrFail()->getKey(),
            'primary_outline_beat_id' => fn (array $attributes): int => NovelOutlineBeat::query()
                ->where('novel_outline_id', $attributes['novel_outline_id'])
                ->whereNotNull('mainline_sequence')->orderBy('mainline_sequence')->firstOrFail()->getKey(),
            'primary_outline_milestone_id' => fn (array $attributes): int => NovelOutlineMilestone::query()
                ->where('novel_outline_id', $attributes['novel_outline_id'])
                ->orderBy('id')->firstOrFail()->getKey(),
            'version' => 1,
            'chapter_function' => fake()->sentence(),
            'arc_contribution' => fake()->sentence(),
            'arc_contributions' => [],
            'character_candidates' => [],
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
            'foreshadowing_actions' => [],
            'world_entity_candidates' => [],
            'scene_plans' => [[
                'goal' => fake()->sentence(),
                'conflict' => fake()->sentence(),
                'turn' => fake()->sentence(),
                'outcome' => fake()->sentence(),
                'outcome_allowed' => [],
                'outcome_forbidden' => [],
                'continuity_requirements' => [],
                'transition_from_previous' => null,
            ]],
            'status' => PlanStatus::Draft,
        ];
    }
}
