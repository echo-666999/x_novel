<?php

namespace App\Services;

use App\Data\PlanFinding;
use App\Data\PlanValidationResult;
use App\Enums\AiStage;
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
use Illuminate\Validation\ValidationException;

class PlanValidator
{
    private string $validatingRecord = 'chapter_plan:candidate';

    public function __construct(
        private readonly ForeshadowingLifecycleResolver $foreshadowingLifecycleResolver,
        private readonly StoryArcBeatContract $storyArcBeatContract,
        private readonly OutlineProgressResolver $outlineProgressResolver,
        private readonly NovelOutlineChecksum $outlineChecksum,
        private readonly ContextBuilder $contextBuilder,
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly GenerationRequestBudget $requestBudget,
    ) {}

    /** @param array<string, mixed>|null $admissionSnapshot */
    public function validate(
        ChapterPlan $plan,
        ?array $admissionSnapshot = null,
        ?int $expectedBibleVersion = null,
    ): PlanValidationResult {
        $this->validatingRecord = 'chapter_plan:'.($plan->getKey() ?? 'candidate');
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
        $this->validateCharacterCandidates($plan, $findings);
        $this->validateWorldEntityCandidates($plan, $findings);

        if ($admissionSnapshot !== null) {
            $this->validateAdmissionSnapshot($plan, $admissionSnapshot, $findings, $expectedBibleVersion);
        }

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
        if (in_array($primaryBeatKey, $target->canonicalCompletedBeatKeys, true)) {
            $findings[] = $this->blocked('OUTLINE_BEAT_ALREADY_COMPLETED', '已完成的 Outline Beat 不能再次作为 Primary。');
        } elseif ($plan->primary_outline_arc_id !== $target->outlineArcId
            || $plan->primary_outline_beat_id !== $target->outlineBeatId
            || $plan->primary_outline_milestone_id !== $target->outlineMilestoneId
            || (int) ($primaryContribution['arc_id'] ?? 0) !== $target->arcId
            || $primaryBeatKey !== ($target->beat['key'] ?? null)
            || (int) ($primaryContribution['beat_index'] ?? 0) !== (int) ($target->beat['sequence'] ?? 0)
            || ($primaryContribution['milestone_key'] ?? null) !== ($target->milestone['key'] ?? null)
            || (int) ($primaryContribution['milestone_sequence'] ?? 0) !== (int) ($target->milestone['sequence'] ?? 0)) {
            $findings[] = $this->blocked('OUTLINE_BEAT_OUT_OF_ORDER', 'Primary Plan 外键和 Contribution 必须引用顺序最早的未完成 Main Beat/Milestone。');
        }

        $criteria = (string) ($primaryContribution['acceptance_criteria'] ?? '');
        if (! in_array($criteria, $target->milestone['acceptance_criteria'] ?? [], true)) {
            $findings[] = $this->blocked('OUTLINE_REQUIRED_CONTENT_MISSING', 'Primary Contribution 必须选择当前 Milestone 的明确验收条件。');
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

        foreach (array_unique($target->milestone['must_include'] ?? []) as $required) {
            if (! in_array($required, $plan->must_reveal ?? [], true)) {
                $findings[] = $this->blocked('OUTLINE_REQUIRED_CONTENT_MISSING', "当前 Milestone 的必须内容「{$required}」未合并到 must_reveal。");
            }
        }
        $forbiddenConstraints = [...($plan->must_not_reveal ?? []), ...($plan->forbidden_conflicts ?? [])];
        foreach (array_unique([...($target->beat['must_not_include'] ?? []), ...($target->milestone['must_not_include'] ?? [])]) as $forbidden) {
            if (! in_array($forbidden, $forbiddenConstraints, true)) {
                $findings[] = $this->blocked('OUTLINE_FORBIDDEN_CONTENT_PLANNED', "当前 Beat 的禁止内容「{$forbidden}」未冻结到禁止约束。");
            }
        }

        $this->validateOutlineCandidates(
            $plan->character_candidates ?? [],
            $target->beat['character_candidates'] ?? [],
            'INVALID_CHARACTER_CANDIDATE',
            '人物',
            $findings,
        );
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
    private function validateCharacterCandidates(ChapterPlan $plan, array &$findings): void
    {
        $characters = $plan->chapter->novel->characters()->get();
        $characterIds = $characters->keyBy('id');
        $seen = [];

        foreach ($plan->character_candidates ?? [] as $index => $candidate) {
            $position = $index + 1;
            if (! is_array($candidate)) {
                $findings[] = $this->blocked('INVALID_CHARACTER_CANDIDATE', "人物候选 {$position} 结构无效。");

                continue;
            }

            $key = trim((string) ($candidate['candidate_key'] ?? ''));
            $name = trim((string) ($candidate['name'] ?? ''));
            $scene = filter_var($candidate['target_scene_sequence'] ?? null, FILTER_VALIDATE_INT);
            $possibleDuplicates = collect($candidate['possible_duplicate_character_ids'] ?? [])->map(fn ($id): int => (int) $id);

            if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $key) || $name === ''
                || blank($candidate['role'] ?? null) || blank($candidate['motivation'] ?? null)
                || blank($candidate['deduplication_basis'] ?? null) || blank($candidate['introduction_reason'] ?? null)
                || collect(['profile', 'personality', 'abilities', 'knowledge', 'possible_duplicate_character_ids'])
                    ->contains(fn (string $field): bool => ! is_array($candidate[$field] ?? null))) {
                $findings[] = $this->blocked('INVALID_CHARACTER_CANDIDATE', "人物候选 {$position} 缺少完整冻结字段。");
            }

            if (isset($seen[$key])) {
                $findings[] = $this->blocked('INVALID_CHARACTER_CANDIDATE', "人物候选键 {$key} 重复。");
            }
            $seen[$key] = true;

            if ($scene === false || $scene < 1 || $scene > count($plan->scene_plans ?? [])) {
                $findings[] = $this->blocked('INVALID_CHARACTER_CANDIDATE', "人物候选「{$name}」指向不存在的 Scene。");
            }

            foreach ($possibleDuplicates as $characterId) {
                if (! $characterIds->has($characterId)) {
                    $findings[] = $this->blocked('INVALID_CHARACTER_CANDIDATE', "人物候选「{$name}」引用了其他小说的去重人物 #{$characterId}。");
                }
            }

            if ($characters->contains(fn ($character): bool => mb_strtolower(trim($character->name)) === mb_strtolower($name))) {
                $findings[] = $this->blocked('INVALID_CHARACTER_CANDIDATE', "人物候选「{$name}」与现有正式人物重名，应直接引用现有人物。");
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
            $milestoneKey = $contribution['milestone_key'] ?? null;
            $milestoneSequence = $contribution['milestone_sequence'] ?? null;
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

            if (($contribution['role'] ?? null) === 'secondary'
                && ($milestoneKey !== null || $milestoneSequence !== null)) {
                $findings[] = $this->blocked('INVALID_ARC_CONTRIBUTION', "Story Arc 推进项 {$position} 的 Secondary Contribution 不能声明 Main Milestone。");
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

        if (! array_is_list($plan->scene_plans ?? [])) {
            $findings[] = $this->blocked(
                'INVALID_SCENE_SEQUENCE',
                'Scene Plan 必须使用从 1 开始且无缺口的数组顺序。',
                'scene_plans',
                "chapter_plan:{$plan->getKey()}",
                '重新保存 Chapter Plan，使 Scene 顺序连续。',
            );
        }

        foreach (array_values($plan->scene_plans ?? []) as $index => $scenePlan) {
            if (! is_array($scenePlan)) {
                $findings[] = $this->blocked('INVALID_SCENE_PLAN', 'Scene '.($index + 1).' 结构无效。');

                continue;
            }

            foreach (['goal', 'conflict', 'turn', 'outcome'] as $field) {
                if (! isset($scenePlan[$field]) || trim((string) $scenePlan[$field]) === '') {
                    $findings[] = $this->blocked(
                        'INVALID_SCENE_PLAN',
                        'Scene '.($index + 1).' 缺少 '.$field.'。',
                    );
                }
            }

            foreach (['outcome_allowed', 'outcome_forbidden', 'continuity_requirements'] as $field) {
                if (array_key_exists($field, $scenePlan) && ! is_array($scenePlan[$field])) {
                    $findings[] = $this->blocked(
                        'INVALID_SCENE_PLAN',
                        'Scene '.($index + 1)." 的 {$field} 必须是数组。",
                        "scene_plans.{$index}.{$field}",
                        "chapter_plan:{$plan->getKey()}",
                        '修正该 Scene 的结构化边界后重新执行 Plan Admission。',
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

        try {
            $target = $this->outlineProgressResolver->resolve($plan->chapter->novel);
        } catch (ValidationException) {
            // Outline 来源链错误由 validateOutlineContract 形成可操作 Finding；这里不提前中断聚合。
            $target = null;
        }
        $inbound = $target?->inboundHandoff;
        if (is_array($inbound) && data_get($inbound, 'entry_pending') === true) {
            $transition = trim((string) data_get($plan->scene_plans, '0.transition_from_previous', ''));
            foreach ((array) data_get($inbound, 'contract.required_transition', []) as $requirement) {
                if (! is_string($requirement) || $requirement === '') {
                    continue;
                }
                if (! str_contains($transition, $requirement)) {
                    $findings[] = $this->blocked(
                        'MISSING_HANDOFF_TRANSITION',
                        "第一场景的跨章衔接缺少已提交 Handoff 要求：{$requirement}",
                        'scene_plans.0.transition_from_previous',
                        "chapter_plan:{$plan->getKey()}",
                        '重新规划第一场景，并明确写出时间、地点、行动或人物状态的必要过渡。',
                    );
                }
            }

            $continuity = collect(data_get($plan->scene_plans, '0.continuity_requirements', []));
            foreach (['carried_states', 'open_threads'] as $field) {
                foreach ((array) data_get($inbound, "contract.{$field}", []) as $description) {
                    if (! $continuity->contains(fn (mixed $item): bool => is_array($item)
                        && ($item['mode'] ?? null) === 'establish'
                        && ($item['description'] ?? null) === $description)) {
                        $findings[] = $this->blocked(
                            'MISSING_HANDOFF_STATE',
                            "第一场景没有建立已提交 Handoff 状态：{$description}",
                            'scene_plans.0.continuity_requirements',
                            "chapter_plan:{$plan->getKey()}",
                            '恢复 Handoff 的 Carried State/Open Thread 后重新执行 Plan Admission。',
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, PlanFinding>  $findings
     */
    private function validateAdmissionSnapshot(
        ChapterPlan $plan,
        array $snapshot,
        array &$findings,
        ?int $expectedBibleVersion = null,
    ): void {
        $novel = $plan->chapter->novel;
        $target = $this->outlineProgressResolver->resolve($novel);
        $outline = $novel->currentOutline()->first();
        $expectedBibleVersion ??= $this->contextBuilder->bibleVersionForChapter($plan->chapter);
        $expectedStateVersion = $novel->canonicalStateVersion?->version;
        $record = "chapter_plan:{$plan->getKey()}";

        $required = [
            'schema_version', 'plan_checksum', 'input_hash', 'bible_version', 'state_version',
            'novel_outline_id', 'outline_version', 'outline_checksum',
            'primary_outline_arc_id', 'primary_outline_beat_id', 'primary_outline_milestone_id',
            'handoff_checksum', 'routes', 'capacity',
        ];
        foreach ($required as $field) {
            if (! array_key_exists($field, $snapshot) || $snapshot[$field] === null || $snapshot[$field] === '') {
                $findings[] = $this->blocked(
                    'ADMISSION_SOURCE_NOT_FROZEN',
                    "Plan Admission 缺少冻结字段 {$field}。",
                    "admission_snapshot.{$field}",
                    $record,
                    '重新执行 Chapter Planning 或 Plan Admission 生成完整冻结快照。',
                );
            }
        }
        if ((int) ($snapshot['schema_version'] ?? 0) !== 2) {
            // v1 没有冻结真实模型容量和分档预算，继续执行会把运行时配置误当成历史合同。
            $findings[] = $this->blocked(
                'ADMISSION_CONTRACT_VERSION_UNSUPPORTED',
                '当前 Chapter Plan 使用旧版 Admission 合同，不能静默补写为容量合同 v2。',
                'admission_snapshot.schema_version',
                $record,
                '保留旧 Plan，并基于已验证内容创建新的 Plan Version 后重新 Admission。',
            );
        }
        if (! array_key_exists('handoff_next_beat_id', $snapshot)) {
            $findings[] = $this->blocked(
                'ADMISSION_SOURCE_NOT_FROZEN',
                'Plan Admission 缺少冻结字段 handoff_next_beat_id。',
                'admission_snapshot.handoff_next_beat_id',
                $record,
                '重新执行 Chapter Planning 或 Plan Admission 生成完整冻结快照。',
            );
        }
        foreach (['inbound_handoff', 'inbound_handoff_checksum', 'previous_chapter_ending'] as $field) {
            if (! array_key_exists($field, $snapshot)) {
                $findings[] = $this->blocked(
                    'ADMISSION_SOURCE_NOT_FROZEN',
                    "Plan Admission 缺少冻结字段 {$field}。",
                    "admission_snapshot.{$field}",
                    $record,
                    '重新执行 Chapter Planning 或 Plan Admission 生成完整冻结快照。',
                );
            }
        }

        if (is_array($plan->admission_snapshot)
            && ($plan->checksum === null || $plan->checksum !== ($snapshot['plan_checksum'] ?? null))) {
            $findings[] = $this->blocked(
                'PLAN_CHECKSUM_NOT_FROZEN',
                'Chapter Plan 顶层 checksum 与 Admission Snapshot 不一致。',
                'checksum',
                $record,
                '恢复原 Plan 或创建新的 Plan Version 并重新执行 Admission。',
            );
        }
        if (is_array($plan->admission_snapshot)
            && ($plan->input_hash === null || $plan->input_hash !== ($snapshot['input_hash'] ?? null))) {
            $findings[] = $this->blocked(
                'PLAN_INPUT_HASH_NOT_FROZEN',
                'Chapter Plan 顶层 input_hash 与 Admission Snapshot 不一致。',
                'input_hash',
                $record,
                '创建新的 Plan Version 并重新执行 Admission。',
            );
        }

        if (($snapshot['plan_checksum'] ?? null) !== $plan->semanticChecksum()) {
            $findings[] = $this->blocked(
                'PLAN_CHECKSUM_MISMATCH',
                'Chapter Plan 内容已在 Admission 后变化。',
                'checksum',
                $record,
                '创建新的 Plan Version，并从 Scene 1 重新执行。',
            );
        }

        if ($outline === null
            || (int) ($snapshot['novel_outline_id'] ?? 0) !== $outline->getKey()
            || (int) ($snapshot['outline_version'] ?? 0) !== $outline->version
            || ($snapshot['outline_checksum'] ?? null) !== $outline->checksum
            || $target === null
            || (int) ($snapshot['primary_outline_arc_id'] ?? 0) !== $target->outlineArcId
            || (int) ($snapshot['primary_outline_beat_id'] ?? 0) !== $target->outlineBeatId
            || (int) ($snapshot['primary_outline_milestone_id'] ?? 0) !== $target->outlineMilestoneId) {
            $findings[] = $this->blocked(
                'ADMISSION_OUTLINE_MISMATCH',
                'Admission 冻结的 Outline Version 或 Primary Arc/Beat/Milestone 已不是 Current Target。',
                'admission_snapshot.novel_outline_id',
                $record,
                '基于当前 Outline Target 创建新的 Plan Version。',
            );
        }

        if ((int) ($snapshot['bible_version'] ?? 0) !== $expectedBibleVersion) {
            $findings[] = $this->blocked(
                'ADMISSION_BIBLE_VERSION_MISMATCH',
                'Admission 冻结的 Bible Version 与本章 Pipeline Bible 不一致。',
                'admission_snapshot.bible_version',
                $record,
                '按当前 Bible 重新规划本章。',
            );
        }
        if ($expectedStateVersion === null || (int) ($snapshot['state_version'] ?? -1) !== $expectedStateVersion) {
            $findings[] = $this->blocked(
                'ADMISSION_STATE_VERSION_MISMATCH',
                'Admission 冻结的 State Version 已过期。',
                'admission_snapshot.state_version',
                $record,
                '基于最新 Canonical State 创建新的 Plan Version。',
            );
        }

        if ($target !== null) {
            $handoff = $target->beat['handoff'];
            $handoffChecksum = hash('sha256', json_encode($handoff, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if (($snapshot['handoff_next_beat_id'] ?? null) !== $handoff['next_beat_id']
                || ($snapshot['handoff_checksum'] ?? null) !== $handoffChecksum) {
                $findings[] = $this->blocked(
                    'ADMISSION_HANDOFF_MISMATCH',
                    'Admission 冻结的 Handoff 与当前 Beat 契约不一致。',
                    'admission_snapshot.handoff_next_beat_id',
                    "outline_beat:{$target->outlineBeatId}",
                    '重新执行 Chapter Planning，冻结当前 Beat 的 Handoff。',
                );
            }

            $inboundChecksum = $target->inboundHandoff === null
                ? null
                : hash('sha256', json_encode($target->inboundHandoff, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if (($snapshot['inbound_handoff'] ?? null) !== $target->inboundHandoff
                || ($snapshot['inbound_handoff_checksum'] ?? null) !== $inboundChecksum) {
                $findings[] = $this->blocked(
                    'ADMISSION_HANDOFF_MISMATCH',
                    'Admission 冻结的上一 Beat Handoff 与正式 Completion Event 不一致。',
                    'admission_snapshot.inbound_handoff',
                    "outline_beat:{$target->outlineBeatId}",
                    '基于已正式提交的 Handoff 重新规划 Entry Milestone。',
                );
            }
        }

        $expectedRepairSubstages = [
            'writer' => [],
            'extractor' => ['scene_structure', 'coverage_evidence', 'foreshadowing_coverage', 'event_evidence'],
            'reviewer' => ['coverage_judgment', 'review_schema', 'arc_completion'],
            'rewrite' => ['coverage_evidence', 'length_repair'],
            'summary' => [],
        ];
        foreach (['writer', 'extractor', 'reviewer', 'rewrite', 'summary'] as $stage) {
            $route = data_get($snapshot, "routes.{$stage}");
            $modelCapacity = is_array($route) ? ($route['model_capacity'] ?? null) : null;
            $requestBudgets = is_array($route) ? ($route['request_budgets'] ?? null) : null;
            $repairRequestBudgets = is_array($route) ? ($route['repair_request_budgets'] ?? null) : null;
            if (! is_array($route)
                || blank($route['provider'] ?? null)
                || blank($route['model'] ?? null)
                || ! array_key_exists('reasoning_effort', $route)
                || blank($route['prompt_version'] ?? null)
                || ! is_array($modelCapacity)
                || (int) ($modelCapacity['model_price_id'] ?? 0) < 1
                || ($modelCapacity['provider'] ?? null) !== ($route['provider'] ?? null)
                || ($modelCapacity['model'] ?? null) !== ($route['model'] ?? null)
                || (int) ($modelCapacity['context_window_tokens'] ?? 0) < 1
                || (int) ($modelCapacity['max_output_tokens'] ?? 0) < 1
                || ($modelCapacity['supports_structured_output'] ?? false) !== true
                || ! array_key_exists('supports_reasoning_effort', $modelCapacity)
                || (($route['reasoning_effort'] ?? null) !== null
                    && ($modelCapacity['supports_reasoning_effort'] ?? false) !== true)
                || ! is_array($requestBudgets)
                || $requestBudgets === []
                || ! is_array($repairRequestBudgets)) {
                $findings[] = $this->blocked(
                    'PROVIDER_ROUTE_NOT_FROZEN',
                    "{$stage} 的 Provider、Model、Prompt Version、模型容量、主请求预算或修复子阶段预算未完整冻结。",
                    "admission_snapshot.routes.{$stage}",
                    $record,
                    '在 AI 设置中修复该 Stage Route 后重新执行 Plan Admission。',
                );

                continue;
            }

            $invalidBudget = collect($requestBudgets)->contains(function (mixed $budget): bool {
                if (! is_array($budget)) {
                    return true;
                }

                $outputTokens = (int) ($budget['output_tokens'] ?? 0);
                $reasoningReserve = (int) ($budget['reasoning_reserve_tokens'] ?? -1);

                return $outputTokens < 1
                    || $reasoningReserve < 0
                    || (int) ($budget['max_completion_tokens'] ?? 0) !== $outputTokens + $reasoningReserve;
            });
            $maximumBudget = (int) collect($requestBudgets)->max(
                fn (mixed $budget): int => is_array($budget) ? (int) ($budget['max_completion_tokens'] ?? 0) : 0,
            );
            $staticMaximum = min(
                (int) $modelCapacity['context_window_tokens'],
                (int) $modelCapacity['max_output_tokens'],
            );
            if ($invalidBudget || $maximumBudget < 1 || $maximumBudget > $staticMaximum) {
                $findings[] = $this->blocked(
                    'REQUEST_BUDGET_NOT_FROZEN',
                    "{$stage} 的请求预算无效，或超过冻结模型容量。",
                    "admission_snapshot.routes.{$stage}.request_budgets",
                    $record,
                    '修复模型容量或阶段请求预算后创建新的 Plan Version。',
                );
            }

            // 修复子阶段也必须逐项验证分档结构和模型容量，不能只依赖父阶段预算兜底。
            $actualRepairKeys = array_keys($repairRequestBudgets);
            $requiredRepairKeys = $expectedRepairSubstages[$stage];
            sort($actualRepairKeys);
            sort($requiredRepairKeys);
            if ($actualRepairKeys !== $requiredRepairKeys) {
                $findings[] = $this->blocked(
                    'REPAIR_REQUEST_BUDGET_NOT_FROZEN',
                    "{$stage} 的修复子阶段预算键不完整。",
                    "admission_snapshot.routes.{$stage}.repair_request_budgets",
                    $record,
                    '补齐该路由的修复子阶段预算后创建新的 Plan Version。',
                );
            }
            foreach ($repairRequestBudgets as $substage => $repairBudgets) {
                try {
                    $validatedRepairBudgets = $this->requestBudget->validateFrozen(
                        is_array($repairBudgets) ? $repairBudgets : [],
                        AiStage::from($stage),
                    );
                    $repairMaximum = $this->requestBudget->maximum($validatedRepairBudgets);
                    if ($repairMaximum < 1 || $repairMaximum > $staticMaximum) {
                        throw ValidationException::withMessages(['budget' => '修复预算超过模型容量。']);
                    }
                } catch (ValidationException) {
                    $findings[] = $this->blocked(
                        'REPAIR_REQUEST_BUDGET_NOT_FROZEN',
                        "{$stage}.{$substage} 的修复请求预算无效，或超过冻结模型容量。",
                        "admission_snapshot.routes.{$stage}.repair_request_budgets.{$substage}",
                        $record,
                        '修复模型容量或子阶段请求预算后创建新的 Plan Version。',
                    );
                }
            }
        }

        $sceneAllocations = data_get($snapshot, 'capacity.scene_allocations');
        $minimumWords = $this->lengthPolicy->chapterMinimum((int) $plan->target_words);
        if (! is_array($sceneAllocations)
            || count($sceneAllocations) !== count($plan->scene_plans ?? [])
            || collect($sceneAllocations)->sum('target_words') < $minimumWords) {
            $findings[] = $this->blocked(
                'SCENE_WORD_BUDGET_UNREACHABLE',
                'Scene 字数分配无法覆盖章节硬下限。',
                'admission_snapshot.capacity.scene_allocations',
                $record,
                '调整章节目标字数或 Scene 数量后重新规划。',
            );
        } elseif (collect($sceneAllocations)->contains(
            fn (mixed $allocation, int $index): bool => ! is_array($allocation)
                || (int) ($allocation['sequence'] ?? 0) !== $index + 1
                || (int) ($allocation['target_words'] ?? 0) < 1
                || (int) ($allocation['target_words'] ?? 0) > (int) ($allocation['writer_max_completion_tokens'] ?? 0),
        )) {
            $findings[] = $this->blocked(
                'SCENE_OUTPUT_CAPACITY_EXCEEDED',
                '至少一个 Scene 的目标输出超过冻结 Writer Route 的容量。',
                'admission_snapshot.capacity.scene_allocations',
                $record,
                '拆分 Scene、降低目标字数或选择更大输出容量的 Writer Route。',
            );
        }

        $reviewInput = (int) data_get($snapshot, 'capacity.review.estimated_input_words', 0);
        $reviewContext = (int) data_get($snapshot, 'capacity.review.context_token_budget', 0);
        $reviewOutput = (int) data_get($snapshot, 'capacity.review.max_completion_tokens', 0);
        if ($reviewInput < 1 || $reviewContext < $reviewInput || $reviewOutput < 1) {
            $findings[] = $this->blocked(
                'REVIEW_CAPACITY_EXCEEDED',
                '冻结的 Reviewer Context 或输出容量无法容纳本章审校。',
                'admission_snapshot.capacity.review',
                $record,
                '降低章节规模或提高 Reviewer Context/输出预算后重新执行 Plan Admission。',
            );
        }

        foreach (['event_extraction', 'rewrite'] as $stage) {
            $capacity = (int) data_get($snapshot, "capacity.{$stage}.max_completion_tokens", 0);
            $route = $stage === 'event_extraction' ? 'extractor' : 'rewrite';
            $routeMaximum = (int) collect((array) data_get($snapshot, "routes.{$route}.request_budgets", []))->max(
                fn (mixed $budget): int => is_array($budget) ? (int) ($budget['max_completion_tokens'] ?? 0) : 0,
            );
            if ($capacity < 1 || $capacity !== $routeMaximum) {
                $findings[] = $this->blocked(
                    'OUTPUT_CAPACITY_NOT_FROZEN',
                    "{$stage} 的输出容量未与冻结 Route 对齐。",
                    "admission_snapshot.capacity.{$stage}",
                    $record,
                    '重新执行 Plan Admission 冻结合法输出容量。',
                );
            }
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

    private function blocked(
        string $code,
        string $message,
        ?string $field = null,
        ?string $relatedRecord = null,
        ?string $repairAction = null,
    ): PlanFinding {
        $field ??= 'chapter_plan';
        $relatedRecord ??= $this->validatingRecord;
        $repairAction ??= '修正对应 Plan 字段后重新执行 Plan Admission。';

        return new PlanFinding(PlanFindingSeverity::Blocked, $code, $message, $field, $relatedRecord, $repairAction);
    }

    private function warning(string $code, string $message): PlanFinding
    {
        return new PlanFinding(PlanFindingSeverity::Warning, $code, $message);
    }
}
