<?php

namespace App\Actions\Chapters;

use App\Enums\ArtifactType;
use App\Enums\ForeshadowingPlanAction;
use App\Enums\GenerationStage;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationRun;
use App\Services\OutlineProgressResolver;
use App\Services\PlanAdmissionService;
use App\Services\PlanValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveManualChapterPlanAction
{
    public function __construct(
        private readonly OutlineProgressResolver $outlineProgress,
        private readonly PlanValidator $planValidator,
        private readonly PlanAdmissionService $planAdmission,
        private readonly InvalidateChapterPlanDownstreamAction $invalidatePlanDownstream,
        private readonly SyncScenesFromChapterPlanAction $syncScenes,
        private readonly RegenerateSceneSequenceAction $regenerateScenes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: 'reused'|'saved', plan: ChapterPlan}
     */
    public function handle(Chapter $chapter, array $data, ?int $userId): array
    {
        $currentPlan = $chapter->latestPlan()->first();
        // 手工计划也必须冻结 Current Outline 的完整父链，不能创建空来源或由用户提交数据库 ID。
        $target = $this->outlineProgress->resolve($chapter->novel);
        if ($target === null) {
            throw ValidationException::withMessages([
                'plan' => 'Current Outline 没有可规划的 Main Beat/Milestone。',
            ]);
        }

        $data['novel_outline_id'] = $target->outlineId;
        $data['primary_outline_arc_id'] = $target->outlineArcId;
        $data['primary_outline_beat_id'] = $target->outlineBeatId;
        $data['primary_outline_milestone_id'] = $target->outlineMilestoneId;
        $data['arc_contributions'] = [[
            'role' => 'primary',
            'arc_id' => $target->arcId,
            'beat_key' => $target->beat['key'],
            'beat_index' => $target->beat['sequence'],
            'milestone_key' => $target->milestone['key'],
            'milestone_sequence' => $target->milestone['sequence'],
            'target_scene_sequence' => 1,
            'acceptance_criteria' => $target->milestone['acceptance_criteria'][0],
        ]];
        $data['must_reveal'] = array_values(array_unique([
            ...($data['must_reveal'] ?? []),
            ...($target->milestone['must_include'] ?? []),
        ]));
        $data['must_not_reveal'] = array_values(array_unique([
            ...($data['must_not_reveal'] ?? []),
            ...($target->beat['must_not_include'] ?? []),
            ...($target->milestone['must_not_include'] ?? []),
        ]));
        $data['scene_plans'] = collect($data['scene_plans'] ?? [])->map(
            fn (array $scene): array => [
                ...$scene,
                'outcome_allowed' => array_values(array_filter($scene['outcome_allowed'] ?? [], 'is_string')),
                'outcome_forbidden' => array_values(array_filter($scene['outcome_forbidden'] ?? [], 'is_string')),
                'continuity_requirements' => array_values(array_filter($scene['continuity_requirements'] ?? [], 'is_array')),
                'transition_from_previous' => $scene['transition_from_previous'] ?? null,
            ],
        )->values()->all();
        $data['required_facts'] = array_map('intval', $data['required_facts'] ?? []);
        $data['character_candidates'] = $currentPlan?->character_candidates ?? [];
        $data['world_entity_candidates'] = $currentPlan?->world_entity_candidates ?? [];
        // The prior Plan version preserves legacy IDs; every newly saved version uses contracts only.
        $data['due_foreshadowings'] = [];
        $data['foreshadowing_actions'] = $this->authorizedForeshadowingActions(
            $data['foreshadowing_actions'] ?? [],
            $chapter,
            $userId,
        );
        $version = ((int) $chapter->plans()->max('version')) + 1;
        $candidate = new ChapterPlan(['version' => $version, ...$data]);
        $candidate->setRelation('chapter', $chapter);
        $this->planValidator->validate($candidate)->assertCanGenerate();
        $admission = $this->planAdmission->prepare($candidate);
        $reusable = $this->planAdmission->reusableReadyPlan($chapter, $admission['input_hash']);

        if ($reusable !== null) {
            return ['status' => 'reused', 'plan' => $reusable];
        }

        $fromSceneSequence = $this->earliestAffectedSceneSequence($currentPlan, $candidate);
        $hadGeneratedDownstream = $currentPlan !== null && $chapter->scenes()
            ->where('sequence', '>=', $fromSceneSequence)
            ->where(fn ($query) => $query
                ->where('status', '!=', SceneStatus::Planned->value)
                ->orWhereNotNull('current_artifact_id'))
            ->exists();

        if ($hadGeneratedDownstream && $chapter->generationRuns()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->exists()) {
            throw ValidationException::withMessages([
                'plan' => '当前章节仍有排队中或运行中的任务，请等待结束后再修订 Plan。',
            ]);
        }

        $plan = DB::transaction(function () use ($chapter, $data, $version, $admission, $fromSceneSequence): ChapterPlan {
            $chapter->plans()->where('status', PlanStatus::Ready)->update(['status' => PlanStatus::Superseded]);
            $plan = $chapter->plans()->create([
                'version' => $version,
                ...$data,
                ...$admission,
                'status' => PlanStatus::Ready,
            ]);
            $attempt = (int) $chapter->generationRuns()->where('stage', GenerationStage::ChapterPlanning)->max('attempt') + 1;
            $run = GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::ChapterPlanning,
                'status' => RunStatus::Succeeded,
                'attempt' => $attempt,
                'idempotency_key' => 'plan:manual:'.$chapter->getKey().':v'.$version.':'.$admission['input_hash'],
                'input_hash' => $admission['input_hash'],
                'state_version' => data_get($admission, 'admission_snapshot.state_version'),
                'bible_version' => data_get($admission, 'admission_snapshot.bible_version'),
                'prompt_version' => 'manual-chapter-plan-v1',
                'model_policy' => 'manual',
                'context_snapshot' => [
                    'manual_plan' => true,
                    'chapter_plan_id' => $plan->getKey(),
                    'chapter_plan_version' => $plan->version,
                    'from_scene_sequence' => $fromSceneSequence,
                ],
                'started_at' => now(),
                'finished_at' => now(),
            ]);
            $run->artifacts()->create([
                'type' => ArtifactType::ChapterPlan,
                'version' => $plan->version,
                'data' => [
                    'manual_plan' => true,
                    'chapter_plan_id' => $plan->getKey(),
                    'semantic_payload' => $plan->semanticPayload(),
                ],
                'checksum' => $plan->checksum,
            ]);
            $this->invalidatePlanDownstream->execute($chapter, $fromSceneSequence);
            $this->syncScenes->execute($chapter, fromSceneSequence: $fromSceneSequence);

            return $plan;
        });

        if ($hadGeneratedDownstream) {
            $scene = $chapter->scenes()->where('sequence', $fromSceneSequence)->first();
            if ($scene !== null) {
                $this->regenerateScenes->handle($scene);
            }
        }

        return ['status' => 'saved', 'plan' => $plan];
    }

    private function earliestAffectedSceneSequence(?ChapterPlan $current, ChapterPlan $candidate): int
    {
        if ($current === null) {
            return 1;
        }

        $currentPayload = $current->semanticPayload();
        $candidatePayload = $candidate->semanticPayload();
        $currentScenes = array_values($currentPayload['scene_plans'] ?? []);
        $candidateScenes = array_values($candidatePayload['scene_plans'] ?? []);
        unset($currentPayload['scene_plans'], $candidatePayload['scene_plans']);

        if ($currentPayload !== $candidatePayload) {
            return 1;
        }

        $maximum = max(count($currentScenes), count($candidateScenes));
        for ($index = 0; $index < $maximum; $index++) {
            if (($currentScenes[$index] ?? null) !== ($candidateScenes[$index] ?? null)) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<int, array<string, mixed>>
     */
    private function authorizedForeshadowingActions(array $actions, Chapter $chapter, ?int $userId): array
    {
        $chapter->loadMissing('novel.canonicalStateVersion');

        return collect($actions)
            ->map(function (array $contract) use ($chapter, $userId): array {
                $action = ForeshadowingPlanAction::tryFrom((string) ($contract['action'] ?? ''));

                if (! $action?->requiresUserAuthorization()) {
                    unset(
                        $contract['authorized_by_user_id'],
                        $contract['authorized_at'],
                        $contract['authorized_at_canonical_chapter'],
                        $contract['authorized_at_state_version'],
                    );

                    return $contract;
                }

                return [
                    ...$contract,
                    'authorized_by_user_id' => $userId,
                    'authorized_at' => now()->toISOString(),
                    'authorized_at_canonical_chapter' => $chapter->novel->current_chapter_sequence ?? 0,
                    'authorized_at_state_version' => $chapter->novel->canonicalStateVersion?->version,
                ];
            })
            ->values()
            ->all();
    }
}
