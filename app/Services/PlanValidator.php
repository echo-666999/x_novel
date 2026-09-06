<?php

namespace App\Services;

use App\Data\PlanFinding;
use App\Data\PlanValidationResult;
use App\Enums\CharacterStatus;
use App\Enums\FactStatus;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\NovelStatus;
use App\Enums\PlanFindingSeverity;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use Illuminate\Support\Collection;

class PlanValidator
{
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
        $selectedIds = array_unique(array_map('intval', $plan->due_foreshadowings ?? []));

        foreach ($selectedIds as $foreshadowingId) {
            $foreshadowing = $foreshadowings->get($foreshadowingId);

            if ($foreshadowing === null || $foreshadowing->status->isTerminal()) {
                $findings[] = $this->blocked(
                    'INVALID_FORESHADOWING_REFERENCE',
                    "伏笔 #{$foreshadowingId} 不存在、已终止或属于其他小说。",
                );
            }
        }

        foreach ($foreshadowings as $foreshadowing) {
            if ($foreshadowing->status->isTerminal() || $plan->chapter->sequence < $foreshadowing->due_from_chapter) {
                continue;
            }

            if (in_array($foreshadowing->getKey(), $selectedIds, true)) {
                continue;
            }

            if ($foreshadowing->importance === ForeshadowingImportance::Critical) {
                $findings[] = $this->blocked(
                    'CRITICAL_DUE_FORESHADOWING_MISSING',
                    "关键伏笔「{$foreshadowing->title}」已到处理窗口，但未进入 Plan。",
                );
            } elseif ($plan->chapter->sequence <= $foreshadowing->due_to_chapter) {
                $findings[] = $this->warning(
                    'DUE_FORESHADOWING_MISSING',
                    "伏笔「{$foreshadowing->title}」已到处理窗口，但未进入 Plan。",
                );
            }
        }
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

        foreach (array_unique(array_map('intval', $plan->due_foreshadowings ?? [])) as $foreshadowingId) {
            $foreshadowing = $foreshadowings->get($foreshadowingId);

            if ($foreshadowing?->status === ForeshadowingStatus::Idea
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
