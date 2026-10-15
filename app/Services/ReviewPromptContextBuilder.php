<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ChapterPlan;

/** 统一构造 Plan Admission 投影和运行时 Reviewer 使用的审校上下文。 */
final class ReviewPromptContextBuilder
{
    public function __construct(
        private readonly ContextBuilder $contextBuilder,
        private readonly OutlineCompletionService $outlineCompletion,
        private readonly PreviousChapterEnding $previousChapterEnding,
        private readonly ReviewPromptCapacityEstimator $capacityEstimator,
    ) {}

    /**
     * @param  array<string, mixed>|null  $eventCandidate
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $lengthCheck
     * @param  array<int, array<string, mixed>>  $stateFindings
     * @param  array<int, array<string, mixed>>  $planFindings
     * @param  array<string, mixed>|null  $repairVerification
     * @param  array<string, mixed>  $generationPreferences
     * @return array<string, mixed>
     */
    public function build(
        Chapter $chapter,
        ChapterPlan $plan,
        ?array $eventCandidate,
        array $draft,
        array $lengthCheck,
        int $stateVersion,
        array $stateFindings,
        array $planFindings,
        ?array $repairVerification,
        string $promptVersion,
        array $generationPreferences,
        bool $projected = false,
    ): array {
        $styleContract = $this->contextBuilder->styleContractForChapter($chapter);
        $foreshadowingContract = $this->contextBuilder->foreshadowingContractForPlan($chapter, $plan);
        $outlineCompletionContract = $this->outlineCompletion->contractForPlan($chapter, $plan);
        $contractCounts = CompactReviewPayload::contractCounts(
            $plan,
            $outlineCompletionContract,
            $foreshadowingContract,
        );

        $context = [
            'chapter_id' => $chapter->getKey(),
            'bible_version' => $styleContract['bible_version'],
            'style_contract_checksum' => $styleContract['checksum'],
            'l4' => $styleContract,
            'foreshadowing_contract_checksum' => $foreshadowingContract['checksum'],
            'foreshadowing_contract' => $foreshadowingContract,
            'event_candidate' => $eventCandidate,
            'draft' => $draft,
            'chapter_plan' => $plan->only([
                'id', 'version', 'chapter_function', 'arc_contribution', 'arc_contributions',
                'character_candidates', 'world_entity_candidates', 'reader_promise', 'target_words',
                'must_reveal', 'may_hint', 'must_not_reveal', 'foreshadowing_actions', 'scene_plans',
            ]),
            'outline_completion_contract' => $outlineCompletionContract,
            'arc_completion_contract' => $this->arcCompletionContract($chapter, $plan),
            'existing_world_entities' => $chapter->novel->worldEntities()->where('status', 'active')->get()
                ->map->only(['id', 'type', 'name', 'description'])->values()->all(),
            'existing_characters' => $chapter->novel->characters()->get()
                ->map->only(['id', 'name', 'aliases', 'role', 'status'])->values()->all(),
            'scenes' => $projected ? $this->projectedScenes($plan) : $this->persistedScenes($chapter),
            'previous_chapter_ending' => $this->previousChapterEnding->for($chapter),
            'length_check' => $lengthCheck,
            'state_version' => $stateVersion,
            'state_findings' => $stateFindings,
            'plan_findings' => $planFindings,
            'repair_verification' => $repairVerification,
            'prompt_version' => $promptVersion,
            'generation_preferences' => $generationPreferences,
        ];

        if (in_array($this->capacityEstimator->basePromptVersion($promptVersion), ['reviewer-v18', 'reviewer-v19', 'reviewer-v20'], true)) {
            $context['audit_contract'] = [
                'schema_version' => 1,
                'arrays' => [
                    'milestone_completion' => ['source' => 'outline_completion_contract.milestone_criteria', 'count' => $contractCounts['milestone_completion']],
                    'beat_exit' => ['source' => 'outline_completion_contract.beat_exit_criteria', 'count' => $contractCounts['beat_exit']],
                    'handoff_readiness' => ['source' => 'outline_completion_contract.handoff_checks', 'count' => $contractCounts['handoff_readiness']],
                    'foreshadowing_audits' => ['source' => 'foreshadowing_contract.actions', 'count' => $contractCounts['foreshadowing_audits']],
                    'arc_beat_audits' => ['source' => 'chapter_plan.arc_contributions', 'count' => $contractCounts['arc_beat_audits']],
                    'arc_completion_audits' => ['source' => 'chapter_plan.arc_contributions 的首次出现去重 Arc', 'count' => $contractCounts['arc_completion_audits']],
                    'character_candidate_audits' => ['source' => 'chapter_plan.character_candidates', 'count' => $contractCounts['character_candidate_audits']],
                    'world_entity_candidate_audits' => ['source' => 'chapter_plan.world_entity_candidates', 'count' => $contractCounts['world_entity_candidate_audits']],
                ],
            ];
        }

        return $context;
    }

    /** @return array<int, array<string, mixed>> */
    private function arcCompletionContract(Chapter $chapter, ChapterPlan $plan): array
    {
        $arcIds = collect($plan->arc_contributions ?? [])->pluck('arc_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $arcs = $chapter->novel->storyArcs()->whereKey($arcIds)->get()->keyBy('id');

        return $arcIds->map(function (int $arcId) use ($arcs): array {
            $arc = $arcs->get($arcId);

            return [
                'arc_id' => $arcId,
                'title' => $arc?->title,
                'completion_conditions' => $arc?->completion_conditions ?? [],
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function persistedScenes(Chapter $chapter): array
    {
        $chapter->loadMissing('scenes');

        return $chapter->scenes->sortBy('sequence')
            ->map->only(['id', 'sequence', 'goal', 'conflict', 'turn', 'outcome'])
            ->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function projectedScenes(ChapterPlan $plan): array
    {
        return collect($plan->scene_plans ?? [])->values()->map(
            fn (mixed $scene, int $index): array => [
                // 预留十位 ID 长度，避免 Admission 因尚未创建 Scene 而低估 JSON 结构。
                'id' => 9_999_999_999,
                'sequence' => $index + 1,
                'goal' => is_array($scene) ? ($scene['goal'] ?? null) : null,
                'conflict' => is_array($scene) ? ($scene['conflict'] ?? null) : null,
                'turn' => is_array($scene) ? ($scene['turn'] ?? null) : null,
                'outcome' => is_array($scene) ? ($scene['outcome'] ?? null) : null,
            ],
        )->all();
    }
}
