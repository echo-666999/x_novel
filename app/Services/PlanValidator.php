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
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Enums\WorldEntityStatus;
use App\Enums\WorldEntityType;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\Novel;
use Illuminate\Support\Collection;

class PlanValidator
{
    public function __construct(
        private readonly ForeshadowingLifecycleResolver $foreshadowingLifecycleResolver,
        private readonly StoryArcBeatContract $storyArcBeatContract,
        private readonly OutlineProgressResolver $outlineProgressResolver,
        private readonly NovelOutlineChecksum $outlineChecksum,
    ) {}

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
        $this->validateCrossSceneRequirements($plan, $findings);
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
        $this->validateOutlineContract($plan, $findings);
        $this->validateArcContributions($plan, $findings);
        $this->validateWorldEntityCandidates($plan, $findings);

        if ($novel->status === NovelStatus::Completing) {
            $this->validateCompletingRestrictions($plan, $foreshadowings, $findings);
        }

        return new PlanValidationResult($findings);
    }

    /** @param array<int, PlanFinding> $findings */
    private function validateOutlineContract(ChapterPlan $plan, array &$findings): void
    {
        $novel = $plan->chapter->novel;
        $currentOutline = $novel->currentOutline()->first();

        if ($currentOutline === null) {
            if ($plan->novel_outline_id !== null) {
                $findings[] = $this->blocked('OUTLINE_VERSION_MISMATCH', 'Chapter Plan 引用了不存在的 Current Novel Outline。');
            }

            return;
        }

        if ($plan->novel_outline_id !== $currentOutline->getKey()) {
            $findings[] = $this->blocked('OUTLINE_VERSION_MISMATCH', 'Chapter Plan 必须冻结当前采用的 Novel Outline Version。');

            return;
        }

        $target = $this->outlineProgressResolver->resolve($novel);
        if ($target === null) {
            $findings[] = $this->blocked('MISSING_PRIMARY_OUTLINE_BEAT', 'Current Novel Outline 没有可规划的 Main Beat。');

            return;
        }

        $contributions = collect($plan->arc_contributions ?? [])->filter(fn (mixed $item): bool => is_array($item));
        $primary = $contributions->where('role', 'primary')->values();
        if ($primary->count() !== 1) {
            $findings[] = $this->blocked('MISSING_PRIMARY_OUTLINE_BEAT', '新 Chapter Plan 必须恰有一个 Main Outline Beat 作为 Primary Contribution。');

            return;
        }

        $primaryContribution = $primary->first();
        $primaryBeatKey = (string) ($primaryContribution['beat_key'] ?? '');
        $completed = [...$target->canonicalCompletedBeatKeys, ...$target->baselineCompletedBeatKeys];
        if (in_array($primaryBeatKey, $completed, true)) {
            $findings[] = $this->blocked('OUTLINE_BEAT_ALREADY_COMPLETED', '已完成的 Outline Beat 不能再次作为 Primary。');
        } elseif ((int) ($primaryContribution['arc_id'] ?? 0) !== $target->arcId
            || $primaryBeatKey !== ($target->beat['key'] ?? null)
            || (int) ($primaryContribution['beat_index'] ?? 0) !== (int) ($target->beat['sequence'] ?? 0)) {
            $findings[] = $this->blocked('OUTLINE_BEAT_OUT_OF_ORDER', 'Primary Contribution 必须引用顺序最早的未完成 Main Beat。');
        }

        $criteria = (string) ($primaryContribution['acceptance_criteria'] ?? '');
        if (! in_array($criteria, $target->beat['acceptance_criteria'] ?? [], true)) {
            $findings[] = $this->blocked('OUTLINE_REQUIRED_CONTENT_MISSING', 'Primary Contribution 必须选择当前 Beat 的明确验收条件。');
        }

        $maximum = data_get($target->beat, 'chapter_budget.max');
        if (is_int($maximum) && $target->chaptersUsedForCurrentBeat >= $maximum) {
            $findings[] = $this->blocked('OUTLINE_BEAT_BUDGET_EXHAUSTED', '当前 Outline Beat 已达到章节预算上限，不能继续自动规划。');
        }

        $arcs = $novel->storyArcs()->get()->keyBy('id');
        foreach ($contributions->where('role', 'secondary') as $secondary) {
            $arc = $arcs->get((int) ($secondary['arc_id'] ?? 0));
            if ($arc?->type !== StoryArcType::Subplot) {
                $findings[] = $this->blocked('OUTLINE_BEAT_OUT_OF_ORDER', 'Secondary Contribution 只能推进 Active Subplot，不能替代或并行跳转 Main Beat。');
            }
        }

        foreach ($target->beat['must_include'] ?? [] as $required) {
            if (! in_array($required, $plan->must_reveal ?? [], true)) {
                $findings[] = $this->blocked('OUTLINE_REQUIRED_CONTENT_MISSING', "当前 Beat 的必须内容「{$required}」未合并到 must_reveal。");
            }
        }
        $forbiddenConstraints = [...($plan->must_not_reveal ?? []), ...($plan->forbidden_conflicts ?? [])];
        foreach ($target->beat['must_not_include'] ?? [] as $forbidden) {
            if (! in_array($forbidden, $forbiddenConstraints, true)) {
                $findings[] = $this->blocked('OUTLINE_FORBIDDEN_CONTENT_PLANNED', "当前 Beat 的禁止内容「{$forbidden}」未冻结到禁止约束。");
            }
        }

        $this->validateOutlineCandidates(
            $plan->world_entity_candidates ?? [],
            $target->beat['world_entity_candidates'] ?? [],
            'INVALID_WORLD_ENTITY_CANDIDATE',
            '世界实体',
            $findings,
        );
    }

    /** @param array<int, mixed> $selected @param array<int, mixed> $allowed @param array<int, PlanFinding> $findings */
    private function validateOutlineCandidates(array $selected, array $allowed, string $code, string $label, array &$findings): void
    {
        $contracts = collect($allowed)->filter(fn (mixed $item): bool => is_array($item))->keyBy('candidate_key');
        foreach ($selected as $candidate) {
            if (! is_array($candidate)) {
                $findings[] = $this->blocked($code, "{$label} Candidate 结构无效。");

                continue;
            }

            $key = (string) ($candidate['candidate_key'] ?? '');
            $contract = $contracts->get($key);
            if ($contract === null || $this->outlineChecksum->for($candidate) !== $this->outlineChecksum->for($contract)) {
                $findings[] = $this->blocked($code, "{$label} Candidate {$key} 不属于当前 Outline Beat 或内容与冻结契约不一致。");
            }
        }
    }

    /** @param array<int, PlanFinding> $findings */
    private function validateArcContributions(ChapterPlan $plan, array &$findings): void
    {
        $arcs = $plan->chapter->novel->storyArcs()->get()->keyBy('id');
        $seen = [];

        foreach ($plan->arc_contributions ?? [] as $index => $contribution) {
            $position = $index + 1;
            if (! is_array($contribution)) {
                $findings[] = $this->blocked('INVALID_ARC_CONTRIBUTION', "Story Arc 推进项 {$position} 结构无效。");

                continue;
            }

            $arcId = filter_var($contribution['arc_id'] ?? null, FILTER_VALIDATE_INT);
            $beatIndex = filter_var($contribution['beat_index'] ?? null, FILTER_VALIDATE_INT);
            $sceneSequence = filter_var($contribution['target_scene_sequence'] ?? null, FILTER_VALIDATE_INT);
            $beatKey = trim((string) ($contribution['beat_key'] ?? ''));
            $criteria = trim((string) ($contribution['acceptance_criteria'] ?? ''));
            $arc = $arcId === false ? null : $arcs->get($arcId);

            if ($arc === null || $arc->status !== StoryArcStatus::Active) {
                $findings[] = $this->blocked('INVALID_ARC_REFERENCE', "Story Arc 推进项 {$position} 引用了其他小说或非推进中的 Arc。");

                continue;
            }

            if ($arc->volume_id !== null && $arc->volume_id !== $plan->chapter->volume_id) {
                $findings[] = $this->blocked('ARC_VOLUME_MISMATCH', "Story Arc「{$arc->title}」不属于当前 Chapter 的 Volume。");
            }

            $beat = collect($this->storyArcBeatContract->forArc($arc))->first(
                fn (array $candidate): bool => $candidate['beat_key'] === $beatKey,
            );
            if ($beat === null || $beatIndex === false || $beat['beat_index'] !== $beatIndex) {
                $findings[] = $this->blocked('INVALID_ARC_BEAT_REFERENCE', "Story Arc「{$arc->title}」的 Beat 标识或索引无效。");
            }

            if ($sceneSequence === false || $sceneSequence < 1 || $sceneSequence > count($plan->scene_plans ?? []) || $criteria === '') {
                $findings[] = $this->blocked('INVALID_ARC_BEAT_ACCEPTANCE', "Story Arc 推进项 {$position} 缺少有效目标 Scene 或验收条件。");
            }

            $fingerprint = $arcId.':'.$beatKey;
            if (isset($seen[$fingerprint])) {
                $findings[] = $this->blocked('DUPLICATE_ARC_BEAT_REFERENCE', '同一 Story Arc Beat 在 Plan 中只能声明一次。');
            }
            $seen[$fingerprint] = true;
        }
    }

    /** @param array<int, PlanFinding> $findings */
    private function validateWorldEntityCandidates(ChapterPlan $plan, array &$findings): void
    {
        $entities = $plan->chapter->novel->worldEntities()->get();
        $entityIds = $entities->keyBy('id');
        $seenKeys = [];

        foreach ($plan->world_entity_candidates ?? [] as $index => $candidate) {
            $position = $index + 1;
            if (! is_array($candidate)) {
                $findings[] = $this->blocked('INVALID_WORLD_ENTITY_CANDIDATE', "世界实体候选 {$position} 结构无效。");

                continue;
            }

            $key = trim((string) ($candidate['candidate_key'] ?? ''));
            $type = WorldEntityType::tryFrom((string) ($candidate['type'] ?? ''));
            $name = trim((string) ($candidate['name'] ?? ''));
            $scene = filter_var($candidate['target_scene_sequence'] ?? null, FILTER_VALIDATE_INT);
            $possibleDuplicates = collect($candidate['possible_duplicate_entity_ids'] ?? [])->map(fn ($id): int => (int) $id);

            if (! preg_match('/^wec-[a-z0-9-]+$/', $key) || $type === null || $name === ''
                || blank($candidate['description'] ?? null) || blank($candidate['deduplication_basis'] ?? null)
                || blank($candidate['introduction_reason'] ?? null)) {
                $findings[] = $this->blocked('INVALID_WORLD_ENTITY_CANDIDATE', "世界实体候选 {$position} 缺少稳定键、类型、名称、描述、去重依据或引入理由。");
            }

            if (isset($seenKeys[$key])) {
                $findings[] = $this->blocked('DUPLICATE_WORLD_ENTITY_CANDIDATE_KEY', "世界实体候选键 {$key} 重复。");
            }
            $seenKeys[$key] = true;

            if ($scene === false || $scene < 1 || $scene > count($plan->scene_plans ?? [])) {
                $findings[] = $this->blocked('INVALID_WORLD_ENTITY_TARGET_SCENE', "世界实体候选「{$name}」指向不存在的 Scene。");
            }

            foreach ($possibleDuplicates as $entityId) {
                if (! $entityIds->has($entityId)) {
                    $findings[] = $this->blocked('INVALID_WORLD_ENTITY_DUPLICATE_REFERENCE', "世界实体候选「{$name}」引用了其他小说的去重实体 #{$entityId}。");
                }
            }

            $sameName = $entities->first(fn ($entity): bool => $entity->status === WorldEntityStatus::Active
                && mb_strtolower(trim($entity->name)) === mb_strtolower($name));
            if ($sameName !== null) {
                $findings[] = $this->blocked(
                    $sameName->type === $type ? 'DUPLICATE_WORLD_ENTITY' : 'WORLD_ENTITY_TYPE_CONFLICT',
                    "世界实体候选「{$name}」与现有 {$sameName->type->getLabel()} #{$sameName->getKey()} 重复或类型冲突，应直接引用现有实体。",
                );
            }
        }
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

    /** @param array<int, PlanFinding> $findings */
    private function validateCrossSceneRequirements(ChapterPlan $plan, array &$findings): void
    {
        $scenePlans = array_values($plan->scene_plans ?? []);
        $seenCoreRequirements = [];
        $seenContracts = [];
        $lastScene = count($scenePlans);

        foreach ($scenePlans as $index => $scenePlan) {
            $sceneNumber = $index + 1;

            foreach (['goal', 'conflict', 'turn', 'outcome', 'outcome_allowed', 'outcome_forbidden'] as $field) {
                $values = is_array($scenePlan[$field] ?? null) ? $scenePlan[$field] : [$scenePlan[$field] ?? null];

                foreach ($values as $value) {
                    $normalized = $this->normalizedRequirement($value);
                    if ($normalized === '') {
                        continue;
                    }

                    if (isset($seenCoreRequirements[$normalized]) && $seenCoreRequirements[$normalized] !== $sceneNumber) {
                        $findings[] = $this->blocked(
                            'DUPLICATE_CROSS_SCENE_REQUIREMENT',
                            "Scene {$sceneNumber} 的 {$field} 与 Scene {$seenCoreRequirements[$normalized]} 重复；持续状态应改用 continuity_requirements，并只在首次、变化或章末回扣时写入正文要求。",
                        );
                    }
                    $seenCoreRequirements[$normalized] ??= $sceneNumber;
                }
            }

            foreach (($scenePlan['continuity_requirements'] ?? []) as $contract) {
                if (! is_array($contract)) {
                    $findings[] = $this->blocked('INVALID_CONTINUITY_REQUIREMENT', "Scene {$sceneNumber} 的 continuity_requirements 结构无效。");

                    continue;
                }

                $key = trim((string) ($contract['key'] ?? ''));
                $mode = (string) ($contract['mode'] ?? '');
                $description = trim((string) ($contract['description'] ?? ''));
                if ($key === '' || $description === '' || ! in_array($mode, ['establish', 'persist', 'change', 'callback'], true)) {
                    $findings[] = $this->blocked('INVALID_CONTINUITY_REQUIREMENT', "Scene {$sceneNumber} 的 continuity requirement 缺少合法 key、mode 或 description。");

                    continue;
                }

                $previous = $seenContracts[$key] ?? [];
                if ($mode === 'establish' && $previous !== []) {
                    $findings[] = $this->blocked('DUPLICATE_CONTINUITY_ESTABLISHMENT', "持续状态 {$key} 只能首次建立一次。");
                } elseif ($mode !== 'establish' && $previous === []) {
                    $findings[] = $this->blocked('CONTINUITY_REQUIREMENT_NOT_ESTABLISHED', "持续状态 {$key} 在 {$mode} 前必须先由较早 Scene establish。");
                }

                if ($mode === 'callback' && $sceneNumber !== $lastScene) {
                    $findings[] = $this->blocked('CONTINUITY_CALLBACK_NOT_AT_CHAPTER_END', "持续状态 {$key} 的 callback 只能放在章末 Scene。");
                }

                $seenContracts[$key][] = ['scene' => $sceneNumber, 'mode' => $mode];
            }
        }
    }

    private function normalizedRequirement(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_strtolower((string) preg_replace('/[\p{P}\p{S}\s]+/u', '', trim($value)));
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
