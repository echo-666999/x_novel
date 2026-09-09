<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Data\ContextRequest;
use App\Data\ContextSnapshot;
use App\Data\MemoryQuery;
use App\Enums\ChapterStatus;
use App\Enums\FactStatus;
use App\Enums\WorldEntityStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContextBuilder
{
    public function __construct(
        private readonly TokenBudget $tokenBudget,
        private readonly MemoryRetriever $memoryRetriever,
        private readonly PreviousChapterEnding $previousChapterEnding,
    ) {}

    public function buildForRun(GenerationRun $run, ContextRequest $request): ContextSnapshot
    {
        $this->assertRunMatches($run, $request);
        $snapshot = $this->build($request);

        return DB::transaction(function () use ($run, $request, $snapshot): ContextSnapshot {
            $lockedRun = GenerationRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $this->assertRunMatches($lockedRun, $request);
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
        $characterIds = collect([$plan->pov_character_id])
            ->merge(collect($plan->scene_plans ?? [])->pluck('pov_character_id'))
            ->merge($facts->where('subject_type', 'character')->pluck('subject_id'))
            ->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $worldEntityIds = $worldRules->pluck('id')
            ->merge($facts->where('subject_type', 'world_entity')->pluck('subject_id'))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
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

        $preL3Allocation = $this->tokenBudget->allocate(
            $request->tokenBudget,
            $sections,
            $l2Truncated ? ['l2.recent_story'] : [],
        );
        [$l3, $memoryIds, $l3Truncated] = $this->longTermMemory(
            $request,
            $chapter,
            $plan,
            $characterIds,
            $worldEntityIds,
            $preL3Allocation->remaining,
        );

        if ($l3['memories'] !== []) {
            while ($l3['memories'] !== [] && $this->tokenBudget->estimate($l3) > $preL3Allocation->remaining) {
                array_pop($l3['memories']);
                array_pop($memoryIds);
                $l3Truncated = true;
            }

            if ($l3['memories'] !== []) {
                $sections['l3'] = $l3;
            }
        }

        $allocation = $this->tokenBudget->allocate(
            $request->tokenBudget,
            $sections,
            array_values(array_filter([
                $l2Truncated ? 'l2.recent_story' : null,
                $l3Truncated ? 'l3.long_term_memory' : null,
            ])),
        );

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
            memoryIds: $memoryIds,
            recentChapterIds: $recentChapterIds,
            previousArtifactId: $request->previousArtifactId,
            promptVersion: $request->promptVersion,
            model: $request->model,
            tokenAllocation: $allocation,
            l0: $l0,
            l1: $l1,
            l2: $l2,
            l3: $l3,
        );
    }

    /**
     * @param  array<int, int>  $characterIds
     * @param  array<int, int>  $worldEntityIds
     * @return array{0: array<string, mixed>, 1: array<int, int>, 2: bool}
     */
    private function longTermMemory(
        ContextRequest $request,
        Chapter $chapter,
        ChapterPlan $plan,
        array $characterIds,
        array $worldEntityIds,
        int $availableTokens,
    ): array {
        $queryText = json_encode([
            'chapter_function' => $plan->chapter_function,
            'arc_contribution' => $plan->arc_contribution,
            'reader_promise' => $plan->reader_promise,
            'must_reveal' => $plan->must_reveal ?? [],
            'scene_plans' => $plan->scene_plans ?? [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query = new MemoryQuery(
            novelId: $request->novelId,
            queryText: $queryText,
            entityIds: [
                'characters' => array_map('strval', $characterIds),
                'world_entities' => array_map('strval', $worldEntityIds),
            ],
            chapterFrom: $chapter->sequence,
            chapterTo: $chapter->sequence,
            tokenBudget: min($availableTokens, (int) config('context.long_term_memory_token_budget', 1_500)),
        );

        if ($availableTokens < 1) {
            return [['query_text' => $queryText, 'memories' => [], 'status' => 'budget_exhausted'], [], false];
        }

        try {
            $ranked = $this->memoryRetriever->retrieve($query);
        } catch (AiProviderException|QueryException) {
            return [['query_text' => $queryText, 'memories' => [], 'status' => 'unavailable'], [], true];
        }

        $selected = $ranked->where('selected', true);

        return [[
            'query_text' => $queryText,
            'memories' => $selected->map(fn ($result): array => [
                'id' => $result->memory->getKey(),
                'type' => $result->memory->type->value,
                'summary' => $result->memory->summary,
                'source' => $result->memory->sourceLabel(),
                'final_score' => $result->finalScore,
            ])->values()->all(),
            'status' => 'ready',
        ], $selected->pluck('memory.id')->map(fn ($id): int => (int) $id)->all(), $ranked->contains(
            fn ($result): bool => ! $result->selected && str_contains($result->reason, 'Token Budget'),
        )];
    }

    private function assertRunMatches(GenerationRun $run, ContextRequest $request): void
    {
        if ($run->novel_id !== $request->novelId
            || $run->chapter_id !== $request->chapterId
            || $run->scene_id !== $request->sceneId) {
            throw new InvalidArgumentException('ContextRequest 与 GenerationRun 的 Novel、Chapter 或 Scene 不一致。');
        }
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
        $ending = $this->previousChapterEnding->from($previous);
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
}
