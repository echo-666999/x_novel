<?php

namespace App\Services;

use App\Data\PlanFinding;
use App\Data\PlanValidationResult;
use App\Enums\CharacterStatus;
use App\Enums\FactStatus;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingPlanAction;
use App\Enums\ForeshadowingStatus;
use App\Enums\ForeshadowingTimingStatus;
use App\Enums\NovelStatus;
use App\Enums\PlanFindingSeverity;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\Novel;
use Illuminate\Support\Collection;

class PlanValidator
{
    public function __construct(private readonly ForeshadowingLifecycleResolver $foreshadowingLifecycleResolver) {}

    public function validate(ChapterPlan $plan): PlanValidationResult
    {
        $plan->loadMissing('chapter.novel');
        $chapter = $plan->chapter;
        $novel = $chapter->novel;
        $findings = [];

        $characters = $novel->characters()->get()->keyBy('id');
        $activeFacts = $novel->facts()->where('status', FactStatus::Active)->get()->keyBy('id');
        $lockedFacts = $activeFacts->where('locked', true);
        $foreshadowings = $novel->foreshadowings()->get()->keyBy('id');

        $this->validatePlanShape($plan, $findings);
        $this->validatePov($plan->pov_character_id, 'Chapter Plan', $characters, $findings);

        foreach (array_values($plan->scene_plans ?? []) as $index => $scenePlan) {
            $this->validatePov(
                $scenePlan['pov_character_id'] ?? $plan->pov_character_id,
                'Scene '.($index + 1),
                $characters,
                $findings,
            );
        }

        $requiredFacts = $this->validateFactReferences($plan, $activeFacts, $findings);
        $this->validateLockedFactsAndKnowledge($requiredFacts, $lockedFacts, $characters, $findings);
        $this->validateForeshadowings($plan, $foreshadowings, $findings);

        if ($novel->status === NovelStatus::Completing) {
            $this->validateCompletingRestrictions($plan, $foreshadowings, $findings);
        }

        return new PlanValidationResult($findings);
    }

    /** @param array<int, PlanFinding> $findings */
    private function validatePlanShape(ChapterPlan $plan, array &$findings): void
    {
        if ($plan->target_words < 1 || empty($plan->scene_plans)) {
            $findings[] = $this->blocked('INVALID_PLAN_SCHEMA', '目标字数必须大于 0，且至少包含一个 Scene Plan。');
        }

        foreach (array_values($plan->scene_plans ?? []) as $index => $scenePlan) {
            foreach (['goal', 'conflict', 'turn', 'outcome'] as $field) {
                if (! isset($scenePlan[$field]) || trim((string) $scenePlan[$field]) === '') {
                    $findings[] = $this->blocked(
                        'INVALID_SCENE_PLAN',
                        'Scene '.($index + 1).' 缺少 '.$field.'。',
                    );
                }
            }
        }

        $hasPreviousCanonicalChapter = $plan->chapter->novel->chapters()
            ->where('status', 'canonical')
            ->where('sequence', '<', $plan->chapter->sequence)
            ->exists();
        if ($hasPreviousCanonicalChapter && blank(data_get($plan->scene_plans, '0.transition_from_previous'))) {
            $findings[] = $this->blocked(
                'MISSING_CHAPTER_TRANSITION',
                '第一场景必须说明如何承接上一章正式结尾；如有时间、地点或行动跳跃，需要写明正文中的过渡过程。',
            );
        }
    }

    /**
     * @param  Collection<int, Character>  $characters
     * @param  array<int, PlanFinding>  $findings
     */
    private function validatePov(?int $characterId, string $scope, Collection $characters, array &$findings): void
    {
        if ($characterId === null || ! $characters->has($characterId)) {
            $findings[] = $this->blocked('INVALID_POV_REFERENCE', "{$scope} 的 POV 不存在于当前小说。");

            return;
        }

        $character = $characters->get($characterId);

        if ($character->status === CharacterStatus::Deceased) {
            $findings[] = $this->blocked('DECEASED_POV', "{$scope} 使用已死亡角色「{$character->name}」作为 POV。");
        } elseif ($character->status === CharacterStatus::Inactive) {
            $findings[] = $this->warning('INACTIVE_POV', "{$scope} 使用暂离角色「{$character->name}」作为 POV，请确认回归安排。");
        }
    }

    /**
     * @param  Collection<int, Fact>  $activeFacts
     * @param  array<int, PlanFinding>  $findings
     * @return Collection<int, Fact>
     */
    private function validateFactReferences(ChapterPlan $plan, Collection $activeFacts, array &$findings): Collection
    {
        $requiredFacts = collect();

        foreach (array_unique(array_map('intval', $plan->required_facts ?? [])) as $factId) {
            if (! $activeFacts->has($factId)) {
                $findings[] = $this->blocked('INVALID_FACT_REFERENCE', "必需事实 #{$factId} 不存在、已失效或属于其他小说。");

                continue;
            }

            $requiredFacts->put($factId, $activeFacts->get($factId));
        }

        return $requiredFacts;
    }

    /**
     * @param  Collection<int, Fact>  $requiredFacts
     * @param  Collection<int, Fact>  $lockedFacts
     * @param  Collection<int, Character>  $characters
     * @param  array<int, PlanFinding>  $findings
     */
    private function validateLockedFactsAndKnowledge(
        Collection $requiredFacts,
        Collection $lockedFacts,
        Collection $characters,
        array &$findings,
    ): void {
        foreach ($requiredFacts as $requiredFact) {
            $conflict = $lockedFacts->first(fn (Fact $lockedFact): bool => $lockedFact->getKey() !== $requiredFact->getKey()
                && $lockedFact->subject_type === $requiredFact->subject_type
                && $lockedFact->subject_id === $requiredFact->subject_id
                && $lockedFact->predicate === $requiredFact->predicate
                && $lockedFact->value !== $requiredFact->value
            );

            if ($conflict !== null) {
                $code = str_starts_with($requiredFact->predicate, 'knows_')
                    ? 'KNOWLEDGE_CONFLICT'
                    : 'LOCKED_FACT_CONFLICT';
                $findings[] = $this->blocked(
                    $code,
                    "必需事实 #{$requiredFact->getKey()} 与锁定事实 #{$conflict->getKey()} 冲突。",
                );

                continue;
            }

            if ($requiredFact->subject_type !== 'character' || ! $characters->has($requiredFact->subject_id)) {
                continue;
            }

            $knownValue = data_get($characters->get($requiredFact->subject_id)->knowledge, $requiredFact->predicate);
            $requiredValue = $requiredFact->value['value'] ?? $requiredFact->value;

            if ($knownValue !== null && $knownValue !== $requiredValue) {
                $findings[] = $this->blocked(
                    'KNOWLEDGE_CONFLICT',
                    "角色当前 knowledge 与必需事实 #{$requiredFact->getKey()} 不一致。",
                );
            }
        }
    }

    /**
     * @param  Collection<int, Foreshadowing>  $foreshadowings
     * @param  array<int, PlanFinding>  $findings
     */
    private function validateForeshadowings(ChapterPlan $plan, Collection $foreshadowings, array &$findings): void
    {
        $novel = $plan->chapter->novel;
        $actionsByForeshadowing = collect();
        $simulatedStatuses = [];
        $seenActions = [];
        $lastTargetSceneByForeshadowing = [];

        if ($plan->hasLegacyForeshadowingReferences()) {
            $findings[] = $this->warning(
                'LEGACY_FORESHADOWING_REFERENCES',
                '当前 Plan 仍使用旧 due_foreshadowings 整数数组；这些 ID 只用于历史解释，不视为已满足新的伏笔动作契约。',
            );
        }

        foreach ($plan->foreshadowingActionContracts() as $index => $contract) {
            $position = $index + 1;
            $foreshadowingId = filter_var($contract['foreshadowing_id'] ?? null, FILTER_VALIDATE_INT);
            $action = is_string($contract['action'] ?? null)
                ? ForeshadowingPlanAction::tryFrom($contract['action'])
                : null;
            $targetScene = filter_var($contract['target_scene_sequence'] ?? null, FILTER_VALIDATE_INT);
            $criteria = trim((string) ($contract['acceptance_criteria'] ?? ''));

            if ($foreshadowingId === false || $foreshadowingId < 1 || $action === null
                || $targetScene === false || $targetScene < 1 || $criteria === '') {
                $findings[] = $this->blocked(
                    'INVALID_FORESHADOWING_ACTION_SCHEMA',
                    "伏笔动作 {$position} 缺少合法的伏笔 ID、动作、目标 Scene 或验收条件。",
                );

                continue;
            }

            if ($targetScene > count($plan->scene_plans ?? [])) {
                $findings[] = $this->blocked(
                    'INVALID_FORESHADOWING_TARGET_SCENE',
                    "伏笔动作 {$position} 指向不存在的 Scene {$targetScene}。",
                );
            }

            if (isset($lastTargetSceneByForeshadowing[$foreshadowingId])
                && $targetScene < $lastTargetSceneByForeshadowing[$foreshadowingId]) {
                $findings[] = $this->blocked(
                    'FORESHADOWING_ACTION_ORDER_INVALID',
                    "伏笔 #{$foreshadowingId} 的动作顺序与目标 Scene 顺序不一致。",
                );
            }
            $lastTargetSceneByForeshadowing[$foreshadowingId] = $targetScene;

            $foreshadowing = $foreshadowings->get($foreshadowingId);
            if ($foreshadowing === null) {
                $findings[] = $this->blocked(
                    'INVALID_FORESHADOWING_REFERENCE',
                    "伏笔 #{$foreshadowingId} 不存在或属于其他小说。",
                );

                continue;
            }

            $status = $simulatedStatuses[$foreshadowingId]
                ?? $this->foreshadowingLifecycleResolver->status($foreshadowing, $novel);
            if ($status->isTerminal()) {
                $findings[] = $this->blocked(
                    'INVALID_FORESHADOWING_REFERENCE',
                    "伏笔 #{$foreshadowingId} 已终止，不能再规划动作。",
                );

                continue;
            }

            $fingerprint = implode(':', [$foreshadowingId, $action->value, $targetScene, $criteria]);
            if (isset($seenActions[$fingerprint])) {
                $findings[] = $this->blocked(
                    'DUPLICATE_FORESHADOWING_ACTION',
                    "伏笔动作 {$position} 与同一 Plan 中的前序动作重复。",
                );

                continue;
            }
            $seenActions[$fingerprint] = true;

            if ($action->requiresUserAuthorization()) {
                $this->validateManualForeshadowingAuthorization($contract, $action, $foreshadowing, $novel, $findings);
            }

            $nextStatus = $this->nextForeshadowingStatus($status, $action);
            if ($nextStatus === null) {
                $findings[] = $this->blocked(
                    'INVALID_FORESHADOWING_LIFECYCLE',
                    "伏笔「{$foreshadowing->title}」不能从 {$status->value} 执行 {$action->value}。",
                );
            } else {
                $simulatedStatuses[$foreshadowingId] = $nextStatus;
            }

            $actionsByForeshadowing->push([
                'foreshadowing_id' => $foreshadowingId,
                'action' => $action,
            ]);
        }

        foreach ($foreshadowings as $foreshadowing) {
            $status = $this->foreshadowingLifecycleResolver->status($foreshadowing, $novel);
            $timing = ForeshadowingTimingStatus::forTargetChapter(
                $status,
                $foreshadowing->due_from_chapter,
                $foreshadowing->due_to_chapter,
                $plan->chapter->sequence,
            );

            if ($timing === null || $timing === ForeshadowingTimingStatus::Upcoming) {
                continue;
            }

            $actions = $actionsByForeshadowing
                ->where('foreshadowing_id', $foreshadowing->getKey())
                ->pluck('action');

            if ($actions->isEmpty() && $foreshadowing->importance === ForeshadowingImportance::Critical) {
                $findings[] = $this->blocked(
                    'CRITICAL_DUE_FORESHADOWING_MISSING',
                    "关键伏笔「{$foreshadowing->title}」已到处理窗口，但未进入 Plan。",
                );
            } elseif ($actions->isEmpty()) {
                $findings[] = $this->warning(
                    'DUE_FORESHADOWING_MISSING',
                    $timing === ForeshadowingTimingStatus::Overdue
                        ? "伏笔「{$foreshadowing->title}」已逾期，但未进入 Plan。"
                        : "伏笔「{$foreshadowing->title}」已到处理窗口，但未进入 Plan。",
                );
            } elseif ($foreshadowing->importance === ForeshadowingImportance::Critical
                && $plan->chapter->sequence >= $foreshadowing->due_to_chapter
                && ! $actions->contains(fn (ForeshadowingPlanAction $action): bool => in_array(
                    $action,
                    [ForeshadowingPlanAction::PayOff, ForeshadowingPlanAction::Defer, ForeshadowingPlanAction::Abandon],
                    true,
                ))) {
                $findings[] = $this->blocked(
                    $timing === ForeshadowingTimingStatus::Overdue
                        ? 'CRITICAL_OVERDUE_REPAIR_REQUIRED'
                        : 'CRITICAL_FORESHADOWING_DEADLINE_REQUIRES_RESOLUTION',
                    $timing === ForeshadowingTimingStatus::Overdue
                        ? "关键伏笔「{$foreshadowing->title}」已逾期；当前章必须是明确兑现或已获人工授权延期/放弃的修复计划。"
                        : "关键伏笔「{$foreshadowing->title}」已到最晚兑现章，不能只做强化。",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<int, PlanFinding>  $findings
     */
    private function validateManualForeshadowingAuthorization(
        array $contract,
        ForeshadowingPlanAction $action,
        Foreshadowing $foreshadowing,
        Novel $novel,
        array &$findings,
    ): void {
        $reason = trim((string) ($contract['reason'] ?? ''));
        $actorId = filter_var($contract['authorized_by_user_id'] ?? null, FILTER_VALIDATE_INT);
        $authorizedAt = $contract['authorized_at'] ?? null;
        $canonicalChapter = filter_var($contract['authorized_at_canonical_chapter'] ?? null, FILTER_VALIDATE_INT);
        $stateVersion = filter_var($contract['authorized_at_state_version'] ?? null, FILTER_VALIDATE_INT);
        $expectedCanonicalChapter = $novel->current_chapter_sequence ?? 0;
        $expectedStateVersion = $novel->canonicalStateVersion?->version;

        if ($reason === '' || $actorId === false || $actorId < 1 || ! is_string($authorizedAt) || blank($authorizedAt)
            || $canonicalChapter === false || $canonicalChapter !== $expectedCanonicalChapter
            || $stateVersion === false || $expectedStateVersion === null || $stateVersion !== $expectedStateVersion) {
            $findings[] = $this->blocked(
                'FORESHADOWING_ACTION_REQUIRES_USER_AUTHORIZATION',
                "伏笔「{$foreshadowing->title}」的 {$action->value} 必须包含当前用户、时间、原因、Canonical 章节和 State Version 的人工授权记录。",
            );
        }

        if ($action !== ForeshadowingPlanAction::Defer) {
            return;
        }

        $newFrom = filter_var($contract['new_due_from_chapter'] ?? null, FILTER_VALIDATE_INT);
        $newTo = filter_var($contract['new_due_to_chapter'] ?? null, FILTER_VALIDATE_INT);
        if ($newFrom === false || $newTo === false || $newFrom <= $foreshadowing->due_to_chapter || $newTo < $newFrom) {
            $findings[] = $this->blocked(
                'INVALID_FORESHADOWING_DEFERRAL_WINDOW',
                "伏笔「{$foreshadowing->title}」延期后的兑现窗口必须晚于当前窗口且首尾有效。",
            );
        }
    }

    private function nextForeshadowingStatus(
        ForeshadowingStatus $status,
        ForeshadowingPlanAction $action,
    ): ?ForeshadowingStatus {
        return match ($action) {
            ForeshadowingPlanAction::Plant => $status === ForeshadowingStatus::Idea
                ? ForeshadowingStatus::Planted
                : null,
            ForeshadowingPlanAction::Reinforce => in_array(
                $status,
                [ForeshadowingStatus::Planted, ForeshadowingStatus::Reinforced],
                true,
            ) ? ForeshadowingStatus::Reinforced : null,
            ForeshadowingPlanAction::PayOff => in_array(
                $status,
                [ForeshadowingStatus::Planted, ForeshadowingStatus::Reinforced],
                true,
            ) ? ForeshadowingStatus::PaidOff : null,
            ForeshadowingPlanAction::Defer => $status,
            ForeshadowingPlanAction::Abandon => ForeshadowingStatus::Abandoned,
        };
    }

    /**
     * @param  Collection<int, Foreshadowing>  $foreshadowings
     * @param  array<int, PlanFinding>  $findings
     */
    private function validateCompletingRestrictions(ChapterPlan $plan, Collection $foreshadowings, array &$findings): void
    {
        $findings[] = $this->warning(
            'COMPLETING_RESTRICTIONS_ACTIVE',
            '小说处于收束阶段；Plan 不得新增核心人物、主线、硬世界规则或高重要度伏笔。',
        );

        foreach ($plan->referencedForeshadowingIds() as $foreshadowingId) {
            $foreshadowing = $foreshadowings->get($foreshadowingId);

            if ($foreshadowing !== null
                && $this->foreshadowingLifecycleResolver->status($foreshadowing, $plan->chapter->novel) === ForeshadowingStatus::Idea
                && in_array($foreshadowing->importance, [ForeshadowingImportance::High, ForeshadowingImportance::Critical], true)) {
                $findings[] = $this->blocked(
                    'COMPLETING_NEW_FORESHADOWING',
                    "收束阶段不得在 Plan 中引入高重要度新伏笔「{$foreshadowing->title}」。",
                );
            }
        }
    }

    private function blocked(string $code, string $message): PlanFinding
    {
        return new PlanFinding(PlanFindingSeverity::Blocked, $code, $message);
    }

    private function warning(string $code, string $message): PlanFinding
    {
        return new PlanFinding(PlanFindingSeverity::Warning, $code, $message);
    }
}
