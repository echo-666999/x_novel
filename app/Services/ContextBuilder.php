<?php

namespace App\Services;

use App\Data\ContextRequest;
use App\Data\ContextSnapshot;
use App\Enums\FactStatus;
use App\Enums\WorldEntityStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ContextBuilder
{
    public function __construct(private readonly TokenBudget $tokenBudget) {}

    public function build(ContextRequest $request): ContextSnapshot
    {
        $novel = Novel::query()->findOrFail($request->novelId);
        $chapter = Chapter::query()->whereKey($request->chapterId)->where('novel_id', $novel->getKey())->firstOrFail();
        $plan = ChapterPlan::query()->whereKey($request->chapterPlanId)->where('chapter_id', $chapter->getKey())->firstOrFail();
        $bible = $novel->currentBible()->firstOrFail();
        $state = StoryStateVersion::query()
            ->where('novel_id', $novel->getKey())
            ->where('version', $request->stateVersion)
            ->firstOrFail();

        if ($request->sceneId !== null && ! $chapter->scenes()->whereKey($request->sceneId)->exists()) {
            throw (new ModelNotFoundException)->setModel('Scene', [$request->sceneId]);
        }

        $requiredFactIds = array_values(array_unique(array_map('intval', $plan->required_facts ?? [])));
        $facts = $novel->facts()
            ->where('status', FactStatus::Active)
            ->where(fn ($query) => $query->where('locked', true)->orWhereIn('id', $requiredFactIds))
            ->orderBy('id')
            ->get();
        $worldRules = $novel->worldEntities()
            ->where('status', WorldEntityStatus::Active)
            ->where(fn ($query) => $query->whereJsonLength('rules', '>', 0)->orWhereJsonLength('locked_fields', '>', 0))
            ->orderBy('id')
            ->get();

        $l0 = [
            'bible_hard_constraints' => $bible->hard_constraints ?? [],
            'ending_contract' => $bible->ending_contract ?? [],
            'locked_and_required_facts' => $facts->map(fn ($fact): array => $fact->only([
                'id', 'subject_type', 'subject_id', 'predicate', 'value', 'hardness', 'locked',
            ]))->all(),
            'world_rules' => $worldRules->map(fn ($entity): array => [
                'id' => $entity->getKey(),
                'name' => $entity->name,
                'rules' => $entity->rules ?? [],
                'locked_fields' => $entity->locked_fields ?? [],
            ])->all(),
            'plan_constraints' => [
                'must_reveal' => $plan->must_reveal ?? [],
                'must_not_reveal' => $plan->must_not_reveal ?? [],
                'required_fact_ids' => $requiredFactIds,
                'forbidden_conflicts' => $plan->forbidden_conflicts ?? [],
            ],
        ];
        $l1 = ['canonical_story_state' => $state->state];
        $allocation = $this->tokenBudget->allocate($request->tokenBudget, ['l0' => $l0, 'l1' => $l1]);

        $characterIds = collect([$plan->pov_character_id])
            ->merge(collect($plan->scene_plans ?? [])->pluck('pov_character_id'))
            ->merge($facts->where('subject_type', 'character')->pluck('subject_id'))
            ->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $worldEntityIds = $worldRules->pluck('id')
            ->merge($facts->where('subject_type', 'world_entity')->pluck('subject_id'))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        return new ContextSnapshot(
            novelId: $novel->getKey(),
            chapterId: $chapter->getKey(),
            sceneId: $request->sceneId,
            taskType: $request->taskType,
            bibleVersion: $bible->version,
            stateVersion: $state->version,
            chapterPlanId: $plan->getKey(),
            characterIds: $characterIds,
            worldEntityIds: $worldEntityIds,
            foreshadowingIds: array_values(array_unique(array_map('intval', $plan->due_foreshadowings ?? []))),
            factIds: $facts->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            memoryIds: [],
            recentChapterIds: [],
            previousArtifactId: $request->previousArtifactId,
            promptVersion: $request->promptVersion,
            model: $request->model,
            tokenAllocation: $allocation,
            l0: $l0,
            l1: $l1,
        );
    }
}
