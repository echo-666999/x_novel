<?php

namespace App\Services;

use App\Data\ContextRequest;
use App\Data\ContextSnapshot;
use App\Enums\ChapterStatus;
use App\Enums\FactStatus;
use App\Enums\WorldEntityStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContextBuilder
{
    public function __construct(private readonly TokenBudget $tokenBudget) {}

    public function buildForRun(GenerationRun $run, ContextRequest $request): ContextSnapshot
    {
        return DB::transaction(function () use ($run, $request): ContextSnapshot {
            $lockedRun = GenerationRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($lockedRun->novel_id !== $request->novelId
                || $lockedRun->chapter_id !== $request->chapterId
                || $lockedRun->scene_id !== $request->sceneId) {
                throw new InvalidArgumentException('ContextRequest 与 GenerationRun 的 Novel、Chapter 或 Scene 不一致。');
            }

            $snapshot = $this->build($request);
            $lockedRun->update([
                'state_version' => $snapshot->stateVersion,
                'bible_version' => $snapshot->bibleVersion,
                'prompt_version' => $snapshot->promptVersion,
                'model_policy' => $snapshot->model,
                'context_snapshot' => $snapshot->toArray(),
            ]);

            return $snapshot;
        });
    }

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
            'locked_and_required_facts' => $facts->map(fn ($fact): array => [
                'id' => $fact->getKey(),
                'subject_type' => $fact->subject_type,
                'subject_id' => $fact->subject_id,
                'predicate' => $fact->predicate,
                'value' => $fact->value,
                'hardness' => $fact->hardness->value,
                'locked' => $fact->locked,
            ])->all(),
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
        $mandatoryAllocation = $this->tokenBudget->allocate($request->tokenBudget, ['l0' => $l0, 'l1' => $l1]);
        [$l2, $recentChapterIds, $l2Truncated] = $this->recentStory(
            $novel,
            $chapter,
            $mandatoryAllocation->remaining,
        );
        $sections = ['l0' => $l0, 'l1' => $l1];

        if ($this->tokenBudget->estimate($l2) <= $mandatoryAllocation->remaining) {
            $sections['l2'] = $l2;
        } else {
            $l2Truncated = true;
        }

        $allocation = $this->tokenBudget->allocate(
            $request->tokenBudget,
            $sections,
            $l2Truncated ? ['l2.recent_story'] : [],
        );

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
            recentChapterIds: $recentChapterIds,
            previousArtifactId: $request->previousArtifactId,
            promptVersion: $request->promptVersion,
            model: $request->model,
            tokenAllocation: $allocation,
            l0: $l0,
            l1: $l1,
            l2: $l2,
        );
    }

    /** @return array{0: array<string, mixed>, 1: array<int, int>, 2: bool} */
    private function recentStory(Novel $novel, Chapter $chapter, int $availableTokens): array
    {
        $window = max(5, min(20, (int) config('context.recent_chapter_window', 10)));
        $chapters = $novel->chapters()
            ->where('status', ChapterStatus::Canonical)
            ->where('sequence', '<', $chapter->sequence)
            ->reorder('sequence', 'desc')
            ->limit($window)
            ->with('canonicalArtifact:id,content')
            ->get();
        $previous = $chapters->first();
        $ending = $this->previousChapterEnding($previous);
        $selected = [];
        $truncated = false;

        if ($this->tokenBudget->estimate($this->recentStoryPayload([], $ending)) > $availableTokens) {
            $ending = null;
            $truncated = $previous !== null;
        }

        foreach ($chapters as $recentChapter) {
            if (blank($recentChapter->summary)) {
                continue;
            }

            $item = [
                'chapter_id' => $recentChapter->getKey(),
                'sequence' => $recentChapter->sequence,
                'title' => $recentChapter->title,
                'summary' => $recentChapter->summary,
            ];
            if ($this->tokenBudget->estimate($this->recentStoryPayload([...$selected, $item], $ending)) > $availableTokens) {
                $truncated = true;

                continue;
            }

            $selected[] = $item;
        }

        $selected = array_reverse($selected);

        return [$this->recentStoryPayload($selected, $ending), array_column($selected, 'chapter_id'), $truncated];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chapters
     * @param  array<string, mixed>|null  $ending
     * @return array<string, mixed>
     */
    private function recentStoryPayload(array $chapters, ?array $ending): array
    {
        return [
            'recent_chapters' => $chapters,
            'previous_chapter_ending' => $ending,
            'story_events' => [],
            'story_events_status' => 'pending_task_070',
        ];
    }

    /** @return array<string, mixed>|null */
    private function previousChapterEnding(?Chapter $chapter): ?array
    {
        if ($chapter === null) {
            return null;
        }

        $content = $chapter->canonicalArtifact?->content;
        $source = filled($content) ? 'canonical_artifact' : 'chapter_summary';
        $text = filled($content) ? $content : $chapter->summary;

        if (blank($text)) {
            return null;
        }

        $limit = max(100, (int) config('context.previous_chapter_ending_characters', 1_000));

        return [
            'chapter_id' => $chapter->getKey(),
            'sequence' => $chapter->sequence,
            'source' => $source,
            'text' => mb_substr((string) $text, -$limit),
        ];
    }
}
