<?php

namespace App\Services;

use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\PromptVersionResolver;
use App\AI\StructuredOutput;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChapterReviewer
{
    private const DIMENSIONS = ['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'];

    private const WEIGHTS = ['continuity' => .25, 'plan' => .15, 'character' => .15, 'progress' => .15, 'repetition' => .10, 'pacing' => .10, 'style' => .10];

    private const NARRATIVE_FINDING_CODES = [
        'CONTINUITY_BREAK' => 'continuity',
        'PLAN_DEVIATION' => 'plan',
        'CHARACTER_INCONSISTENCY' => 'character',
        'INSUFFICIENT_PROGRESS' => 'progress',
        'EXCESSIVE_REPETITION' => 'repetition',
        'PACING_ISSUE' => 'pacing',
        'STYLE_MISMATCH' => 'style',
    ];

    private const FINDING_SCOPES = ['paragraph', 'scene', 'chapter'];

    public function __construct(private readonly AiProvider $provider, private readonly AiSettingsResolver $settingsResolver, private readonly PromptVersionResolver $promptVersionResolver, private readonly StateValidator $stateValidator, private readonly AutoStopService $autoStop, private readonly DraftLengthPolicy $lengthPolicy, private readonly PreviousChapterEnding $previousChapterEnding, private readonly ContextBuilder $contextBuilder, private readonly GenerationRunLease $runLease, private readonly AutomaticRewriteCounter $rewriteCounter, private readonly RewriteScopeResolver $rewriteScopeResolver, private readonly ForeshadowingReviewAudit $foreshadowingReviewAudit, private readonly ReviewDimensionAuditRepairer $dimensionAuditRepairer, private readonly PlanCoverageJudgmentRepairer $coverageJudgmentRepairer, private readonly PlanningReviewAudit $planningReviewAudit, private readonly ArcCompletionAuditRepairer $arcCompletionAuditRepairer) {}

    public function review(int $chapterId, bool $regenerate = false, ?string $operationId = null): ?Review
    {
        $chapter = Chapter::query()->with(['novel.canonicalStateVersion', 'latestPlan', 'scenes'])->findOrFail($chapterId);
        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Narrative Review。', false);
        }

        $draft = $this->latestDraft($chapter);
        $stateValidation = $this->stateValidator->validate($chapterId);
        $missingPrerequisite = collect($stateValidation->findings)->first(
            fn ($finding): bool => in_array($finding->code, ['INVALID_STATE_PATCH', 'INVALID_EVENT_REFERENCE'], true),
        );
        if ($missingPrerequisite !== null) {
            throw new AiProviderException(
                'review_prerequisite_missing',
                '当前章节缺少与最新事件候选对应的有效 State Patch，请先补建状态补丁后重新审校。',
                false,
            );
        }
        $lengthCheck = $this->lengthCheck($draft->content, (int) $chapter->latestPlan?->target_words);
        $styleContract = $this->contextBuilder->styleContractForChapter($chapter);
        $foreshadowingContract = $this->contextBuilder->foreshadowingContractForChapter($chapter);
        $eventCandidate = $this->eventCandidateForDraft($chapter, $draft);
        $planFindings = collect(data_get($draft->data, 'plan_findings', []))
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->values()
            ->all();
        $repairVerification = $this->repairVerification($chapter, $draft);
        $context = [
            'chapter_id' => $chapter->getKey(),
            'bible_version' => $styleContract['bible_version'],
            'style_contract_checksum' => $styleContract['checksum'],
            'l4' => $styleContract,
            'foreshadowing_contract_checksum' => $foreshadowingContract['checksum'],
            'foreshadowing_contract' => $foreshadowingContract,
            'event_candidate' => $eventCandidate,
            'draft' => ['artifact_id' => $draft->getKey(), 'checksum' => $draft->checksum, 'content' => $draft->content],
            'chapter_plan' => $chapter->latestPlan?->only(['id', 'version', 'chapter_function', 'arc_contribution', 'arc_contributions', 'world_entity_candidates', 'reader_promise', 'target_words', 'must_reveal', 'may_hint', 'must_not_reveal', 'foreshadowing_actions', 'scene_plans']),
            'arc_completion_contract' => $this->arcCompletionContract($chapter),
            'existing_world_entities' => $chapter->novel->worldEntities()->where('status', 'active')->get()
                ->map->only(['id', 'type', 'name', 'description'])->values()->all(),
            'scenes' => $chapter->scenes->sortBy('sequence')->map->only(['id', 'sequence', 'goal', 'conflict', 'turn', 'outcome'])->values()->all(),
            'previous_chapter_ending' => $this->previousChapterEnding->for($chapter),
            'length_check' => $lengthCheck,
            'state_version' => $chapter->novel->canonicalStateVersion?->version,
            'state_findings' => array_map(fn ($finding) => $finding->toArray(), $stateValidation->findings),
            'plan_findings' => $planFindings,
            'repair_verification' => $repairVerification,
        ];
        if ($context['chapter_plan'] === null || $context['state_version'] === null) {
            throw new AiProviderException('review_context_incomplete', 'Narrative Review 缺少 Chapter Plan 或 Story State。', false);
        }

        $settings = $this->settingsResolver->resolve(AiStage::Reviewer, $chapter->novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Reviewer);
        $reviewOperationId = $regenerate ? $operationId : null;
        $input = [
            'context' => $context,
            'model' => $settings->model,
            'prompt_version' => $promptVersion,
            'pass_score' => config('generation.review_pass_score', 80),
            ...($reviewOperationId === null ? [] : ['operation_id' => $reviewOperationId]),
        ];
        $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        [$run, $reused] = $this->startRun($chapter, "review:{$draft->checksum}:{$context['state_version']}:{$promptVersion}", $inputHash, $context, $settings->model, $promptVersion, $regenerate, $reviewOperationId);
        if ($reused) {
            return $run->review;
        }

        try {
            [$planFindings, $coverageRepairs] = $this->repairCoverageJudgments(
                $run,
                $chapter,
                $draft,
                $planFindings,
                $settings->model,
            );
            $context['plan_findings'] = $planFindings;
            $context['coverage_repairs'] = $coverageRepairs;
            $run->update(['context_snapshot' => [
                ...($run->context_snapshot ?? []),
                'plan_findings' => $planFindings,
                'coverage_repairs' => $coverageRepairs,
            ]]);

            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                systemPrompt: '你是 XNovel 叙事审校器。必须先完整阅读全部正文、Chapter Plan、上一章结尾、Style Contract 和 foreshadowing_contract。必须按 Chapter Plan 顺序完整返回 arc_beat_audits、arc_completion_audits 和 world_entity_candidate_audits：只有正文逐字证据满足 acceptance_criteria 才能把 Arc Beat 标记 fulfilled；只有正文真实引入批准候选才标记 introduced。arc_completion_audits 必须严格逐项复制 arc_completion_contract 中的 arc_id，数量和顺序完全一致；即使本章没有完成整个 Arc 也不能省略，而应返回 not_met 且 evidence=null。只有正文已满足对应 completion_conditions 时才标记 fulfilled。所有完成、引入或冲突结果必须引用正文逐字证据，missing/not_met 的 evidence 必须为 null。unapproved_world_entities 只报告正文新引入、会持续影响后续故事且未获计划批准的重大世界实体。existing_world_entities、previous_chapter_ending 已存在的内容，以及 Chapter Plan 的场景地点、目标、冲突、转折、结果和允许行为均视为已知或已批准；普通窗口、走廊、查阅区、训练位、报告、凭据、记录、清单和练习道具不是重大世界实体，不得报告。foreshadowing_contract 是本章冻结的唯一伏笔动作契约；必须在 foreshadowing_audits 中按契约顺序逐项审校全部 actions，同时对照 promised_payoff、plan_action、Scene/Assembly Coverage、event_candidate 和正文逐字证据。必须按动作语义区分 plant、reinforce、pay_off：仅提及关键词不能证明完成强化或兑现，pay_off 必须真正满足 promised_payoff 及 acceptance_criteria。正文可在不改变契约时修复，返回 rewrite_required；若只能通过延期、放弃、改变兑现窗口或 promised_payoff 才能解决，返回 needs_attention；不得建议 Rewrite 自行改变这些 Canonical 约束。fulfilled 必须有 Coverage 和匹配 Event Candidate 支持。检查正文是否主动处理未列入 actions 的未来伏笔。promised_payoff 是作者侧验收信息，不表示非 pay_off 动作可以完整揭晓。随后对 continuity、plan、character、progress、repetition、pacing、style 七个维度逐项完成全量检查；不得发现一个问题后提前停止，也不得把同一根因拆成多轮零散报告。dimension_audits 必须逐项声明 pass 或 issues_found，并用简短中文说明检查结论；issues_found 必须一次列出该维度当前所有有明确证据的新 Narrative Finding，pass 表示该维度没有需要新增的 Narrative Finding。dimension_audits 只对应本次模型输出的 findings；伏笔和规划契约问题由 Laravel 生成 Finding，不得在 findings 重复报告；state_findings、plan_findings 和 length_check 已由 Laravel 独立处理，不得重复计入。严格按照七个维度对草稿进行 0 到 100 分评分，并返回符合 Schema 的 JSON。连续性审校必须对照 previous_chapter_ending 检查本章开头，并检查正文内部的时间、地点、人物状态、物品位置和动作因果；发生跳跃、前后矛盾或空间关系无法成立时，必须给出 continuity finding。计划审校必须逐项核对 chapter_function、reader_promise、must_reveal、must_not_reveal 和每个 Scene 的 goal、conflict、turn、outcome，尤其检查正文动作是否真实满足计划边界，而不是只出现相近措辞。repair_verification 非空时，必须逐项确认上一轮全部可修复问题是否已经消除；仍存在的问题必须再次列入对应审计，已解决的问题不得重复报告，同时仍须完成七维及全部伏笔动作的全量检查。每条 finding 必须使用 Schema 中固定的 code，并确保 code 对应正确 dimension。scope=scene 时 scene_id 必须引用 scenes 中属于本章的 ID；scope=chapter 时 scene_id 必须为 null；scope=paragraph 的 evidence 必须逐字引用草稿短句，能确定所属 Scene 时应同时填写 scene_id。仅当问题可在一个 Scene 内独立修复时使用 scope=scene；涉及两个以上 Scene、上一章结尾与本章开头的连续性、章节整体节奏或全章结构时必须使用 scope=chapter 且 scene_id=null。auto_fixable 只表示正文可在不需要用户选择的情况下修复；requires_human_decision 只用于 Canonical 数据无法确定答案、必须由用户选择的重大歧义，两者不得同时为 true。文风审校必须逐项对照 l4 的主文风、辅助文风和全部可执行参数，并在通读全文后一次列出所有实质性偏差；style finding 的 evidence 必须引用草稿中的具体短句，message 必须说明该证据违反了哪项目标文风。所有 dimension_audits.summary、foreshadowing_audits.summary 以及 finding 的 message 与 evidence 必须使用简体中文；规划验收中的 evidence 必须逐字来自正文。只报告有明确文本证据且实际影响连续性、计划遵循、人物一致性、剧情推进、重复度、节奏或文风的问题；需要修复的问题必须通过 auto_fixable 或 requires_human_decision 明确分流。相同根因和相同修复动作应合并为一个 Finding，并在 evidence 中列出代表性原文，不得把同一问题拆成多个近义 Finding。length_check 由 Laravel 确定性计算，不要重复报告其中的字数问题；state_findings 为空表示确定性检查未发现问题，不得因此产生警告；plan_findings 是上游结构化覆盖证据，不要重复生成相同 Finding；may_hint 是可选提示，未采用不得视为问题；不得用“可以更丰富、可以更深入”等泛化建议凑数。recommended_decision 只是审校证据，最终流程决策由 Laravel 根据结构化 finding、确定性规则和分数作出。',
                prompt: '请根据章节计划和确定性状态检查结果审校以下章节草稿，并确保所有面向用户的说明均使用简体中文：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .2, maxTokens: (int) config('generation.review_max_output_tokens', 4000), responseSchema: $this->schema(), promptVersion: $promptVersion,
                metadata: ['generation_run_id' => $run->getKey(), 'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'stage' => AiStage::Reviewer->value],
            ));
            $payload = StructuredOutput::require($response, 'review', 'Narrative Review');
            [$payload, $arcCompletionRepair, $arcCompletionRepairFinding] = $this->repairArcCompletionAudits(
                $payload,
                $run,
                $context,
                $settings->model,
            );
            $payload = $this->validate(
                $payload,
                $chapter,
                $draft,
                $foreshadowingContract,
                $eventCandidate,
                $lengthCheck['status'] !== 'within_range'
                    || $stateValidation->isBlocked()
                    || collect($stateValidation->findings)->contains(fn ($finding): bool => $finding->severity->value === 'ambiguous')
                    || $planFindings !== [],
            );
            $payload['arc_completion_repair'] = $arcCompletionRepair;
            $payload['schema_repair_findings'] = $arcCompletionRepairFinding === null ? [] : [$arcCompletionRepairFinding];
            $payload = $this->normalizeDimensionAudits($payload, $run, $context, $chapter, $settings->model);

            $foreshadowingFindings = $this->foreshadowingReviewAudit->findings($payload['foreshadowing_audits'], $chapter, $foreshadowingContract);
            $review = $this->complete($run, $chapter, $draft, $payload, $context['state_findings'], $planFindings, $foreshadowingFindings, $lengthCheck, $stateValidation->isBlocked(), $context['state_version']);
            $this->autoStop->stopForReview($chapter, $review->decision, $stateValidation->isBlocked());

            return $review;
        } catch (Throwable $e) {
            $this->failRun($run, $e);
            throw $e;
        }
    }

    public function markTerminalFailure(int $chapterId): void
    {
        Chapter::query()->whereKey($chapterId)->update(['status' => ChapterStatus::Blocked]);
    }

    private function latestDraft(Chapter $chapter): GenerationArtifact
    {
        $artifact = GenerationArtifact::query()->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapter->getKey())->whereNull('scene_id'))->latest('id')->first();
        if ($artifact === null) {
            throw new AiProviderException('review_input_incomplete', 'Narrative Review 缺少 Chapter Draft。', false);
        }

        return $artifact;
    }

    /** @return array<int, array{arc_id: int, title: ?string, completion_conditions: array<int, mixed>}> */
    private function arcCompletionContract(Chapter $chapter): array
    {
        $arcIds = collect($chapter->latestPlan?->arc_contributions ?? [])
            ->pluck('arc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
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

    /** @return array<string, mixed>|null */
    private function eventCandidateForDraft(Chapter $chapter, GenerationArtifact $draft): ?array
    {
        $artifact = GenerationArtifact::query()
            ->where('type', ArtifactType::EventCandidate)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('state_version', $chapter->novel->canonicalStateVersion?->version)
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->get()
            ->first(fn (GenerationArtifact $candidate): bool => (int) data_get($candidate->data, 'source_artifact_id') === $draft->getKey());

        return $artifact === null ? null : [
            'artifact_id' => $artifact->getKey(),
            'source_artifact_id' => (int) data_get($artifact->data, 'source_artifact_id'),
            'foreshadowing_contract_checksum' => data_get($artifact->data, 'foreshadowing_contract_checksum'),
            'events' => data_get($artifact->data, 'events', []),
        ];
    }

    private function schema(): array
    {
        $scores = [];
        $dimensionAudits = [];
        foreach (self::DIMENSIONS as $dimension) {
            $scores[$dimension] = ['type' => 'number', 'minimum' => 0, 'maximum' => 100];
            $dimensionAudits[$dimension] = [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['status', 'summary'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['pass', 'issues_found']],
                    'summary' => ['type' => 'string', 'minLength' => 1],
                ],
            ];
        }

        $planningAuditSchema = PlanningReviewAudit::schema();

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['recommended_decision', 'scores', 'dimension_audits', 'foreshadowing_audits', ...array_keys($planningAuditSchema), 'findings'], 'properties' => [
            'recommended_decision' => ['type' => 'string', 'enum' => array_column(ReviewDecision::cases(), 'value')],
            'scores' => ['type' => 'object', 'additionalProperties' => false, 'required' => self::DIMENSIONS, 'properties' => $scores],
            'dimension_audits' => ['type' => 'object', 'additionalProperties' => false, 'required' => self::DIMENSIONS, 'properties' => $dimensionAudits],
            'foreshadowing_audits' => ForeshadowingReviewAudit::schema(),
            ...$planningAuditSchema,
            'findings' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'dimension', 'severity', 'scene_id', 'scope', 'auto_fixable', 'requires_human_decision', 'message', 'evidence'], 'properties' => [
                'code' => ['type' => 'string', 'enum' => array_keys(self::NARRATIVE_FINDING_CODES)],
                'dimension' => ['type' => 'string', 'enum' => self::DIMENSIONS],
                'severity' => ['type' => 'string', 'enum' => ['warning', 'error']],
                'scene_id' => ['type' => ['integer', 'null']],
                'scope' => ['type' => 'string', 'enum' => self::FINDING_SCOPES],
                'auto_fixable' => ['type' => 'boolean'],
                'requires_human_decision' => ['type' => 'boolean'],
                'message' => ['type' => 'string', 'minLength' => 1],
                'evidence' => ['type' => 'string', 'minLength' => 1],
            ]]],
        ]];
    }

    private function validate(array $payload, Chapter $chapter, GenerationArtifact $draft, array $foreshadowingContract, ?array $eventCandidate, bool $hasDeterministicRoute): array
    {
        if (! $this->hasExactKeys($payload, ['recommended_decision', 'scores', 'dimension_audits', 'foreshadowing_audits', 'arc_beat_audits', 'arc_completion_audits', 'world_entity_candidate_audits', 'unapproved_world_entities', 'findings'])
            || ! is_array($payload['scores'])
            || ! $this->hasExactKeys($payload['scores'], self::DIMENSIONS)
            || ! is_array($payload['dimension_audits'])
            || ! $this->hasExactKeys($payload['dimension_audits'], self::DIMENSIONS)
            || ! is_array($payload['foreshadowing_audits'])
            || ! is_array($payload['findings'])
            || ! ReviewDecision::tryFrom((string) $payload['recommended_decision'])) {
            throw ValidationException::withMessages(['review' => 'Narrative Review 返回结构无效。']);
        }
        $payload['foreshadowing_audits'] = $this->foreshadowingReviewAudit->validate(
            $payload['foreshadowing_audits'],
            $chapter,
            $draft,
            $foreshadowingContract,
            $eventCandidate,
        );
        $payload = $this->planningReviewAudit->validate($payload, $chapter, $draft);
        foreach ($payload['scores'] as $score) {
            if (! is_numeric($score) || $score < 0 || $score > 100) {
                throw ValidationException::withMessages(['scores' => '七维评分必须在 0 到 100 之间。']);
            }
        }
        $sceneIds = $chapter->scenes->modelKeys();
        foreach ($payload['findings'] as $finding) {
            if (! is_array($finding) || ! $this->validNarrativeFinding($finding, $chapter, $sceneIds)) {
                throw ValidationException::withMessages(['findings' => 'Narrative Review Findings 结构无效。']);
            }
        }
        $this->validateDimensionAudits($payload['dimension_audits']);

        if (! $hasDeterministicRoute
            && $this->weightedScore($payload['scores']) < (float) config('generation.review_pass_score', 80)
            && ! collect($payload['foreshadowing_audits'])->contains(fn (array $audit): bool => $audit['status'] !== 'fulfilled')
            && ! collect($payload['findings'])->contains(fn (array $finding): bool => $finding['auto_fixable'] || $finding['requires_human_decision'])) {
            throw ValidationException::withMessages(['findings' => 'Narrative Review 评分未达标，但没有提供可执行或需要人工决策的 Finding。']);
        }

        return $payload;
    }

    /** @param array<int, int>|null $sceneIds */
    private function validNarrativeFinding(array $finding, Chapter $chapter, ?array $sceneIds = null): bool
    {
        $sceneIds ??= $chapter->scenes->modelKeys();

        return $this->hasExactKeys($finding, ['code', 'dimension', 'severity', 'scene_id', 'scope', 'auto_fixable', 'requires_human_decision', 'message', 'evidence'])
            && isset(self::NARRATIVE_FINDING_CODES[$finding['code']])
            && self::NARRATIVE_FINDING_CODES[$finding['code']] === $finding['dimension']
            && in_array($finding['severity'], ['warning', 'error'], true)
            && in_array($finding['scope'], self::FINDING_SCOPES, true)
            && is_bool($finding['auto_fixable'])
            && is_bool($finding['requires_human_decision'])
            && ! ($finding['auto_fixable'] && $finding['requires_human_decision'])
            && ! ($finding['severity'] === 'error' && ! $finding['auto_fixable'] && ! $finding['requires_human_decision'])
            && ($finding['scene_id'] === null || (is_int($finding['scene_id']) && in_array($finding['scene_id'], $sceneIds, true)))
            && ! ($finding['scope'] === 'scene' && $finding['scene_id'] === null)
            && ! ($finding['scope'] === 'chapter' && $finding['scene_id'] !== null)
            && is_string($finding['message']) && filled($finding['message'])
            && is_string($finding['evidence']) && filled($finding['evidence']);
    }

    /** @param array<string, mixed> $value @param array<int, string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $model, string $promptVersion, bool $regenerate, ?string $operationId): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $model, $promptVersion, $regenerate, $operationId) {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = $chapter->generationRuns()->where('stage', GenerationStage::Review);
            $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
            if ($this->runLease->isFresh($active)) {
                return [$active, true];
            }
            if ($active) {
                $active->update(['status' => RunStatus::Failed, 'error_code' => 'worker_interrupted', 'error_message' => 'Review Run 超时未完成，已由后续投递恢复。', 'finished_at' => now()]);
            }
            if ((! $regenerate || $operationId !== null)
                && ($done = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$done, true];
            }
            $attempt = (int) $runs->clone()->max('attempt') + 1;
            $run = GenerationRun::query()->create(['novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Running, 'attempt' => $attempt, 'idempotency_key' => $attempt === 1 ? $baseKey : "$baseKey:attempt:$attempt", 'input_hash' => $inputHash, 'state_version' => $context['state_version'], 'bible_version' => $context['bible_version'], 'prompt_version' => $promptVersion, 'model_policy' => $model, 'context_snapshot' => [...$context, 'draft' => collect($context['draft'])->except('content')->all(), ...($operationId === null ? [] : ['operation_id' => $operationId])], 'started_at' => now()]);

            return [$run, false];
        });
    }

    private function complete(GenerationRun $run, Chapter $chapter, GenerationArtifact $draft, array $payload, array $stateFindings, array $planFindings, array $foreshadowingFindings, array $lengthCheck, bool $blocked, int $expectedVersion): Review
    {
        return DB::transaction(function () use ($run, $chapter, $draft, $payload, $stateFindings, $planFindings, $foreshadowingFindings, $lengthCheck, $blocked, $expectedVersion) {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());
            if ($chapter->novel->canonicalStateVersion?->version !== $expectedVersion) {
                throw new AiProviderException('state_version_conflict', 'Review 期间 Canonical Story State 已变化。', false);
            }
            $scores = $payload['scores'];
            $total = $this->weightedScore($scores);
            $recommended = ReviewDecision::from($payload['recommended_decision']);
            $lengthFinding = $this->lengthFinding($lengthCheck);
            $planFindings = $this->withoutDuplicateForeshadowingFindings($planFindings, $foreshadowingFindings);
            $findings = [
                ...array_map(fn (array $finding): array => $this->normalizeStateFinding($finding), $stateFindings),
                ...($lengthFinding === null ? [] : [$lengthFinding]),
                ...$planFindings,
                ...$foreshadowingFindings,
                ...$this->planningReviewAudit->findings($payload),
                ...array_map(fn (array $finding): array => [...$finding, 'source' => 'narrative_review'], $payload['findings']),
                ...($payload['schema_repair_findings'] ?? []),
            ];
            [$decision, $decisionBasis] = $this->decide($findings, $total, $blocked);
            $rewriteAttempts = $this->rewriteCounter->countFor($chapter);
            $rewriteExhausted = $decision === ReviewDecision::Rewrite
                && $rewriteAttempts >= (int) config('generation.max_rewrite_attempts', 2);
            if ($rewriteExhausted) {
                $findings[] = [
                    'code' => 'REWRITE_EXHAUSTED',
                    'dimension' => 'workflow',
                    'severity' => 'ambiguous',
                    'scene_id' => null,
                    'scope' => 'chapter',
                    'auto_fixable' => false,
                    'requires_human_decision' => true,
                    'message' => '自动 Rewrite 已达到最大 '.config('generation.max_rewrite_attempts', 2).' 次，需要人工处理。',
                    'evidence' => '已完成 '.$rewriteAttempts.' 次自动 Rewrite。',
                    'source' => 'rewrite_loop',
                ];
                [$decision, $decisionBasis] = $this->decide($findings, $total, $blocked);
            }
            $rewriteScope = null;
            if ($decision === ReviewDecision::Rewrite) {
                $scopeDecision = $this->rewriteScopeResolver->resolve($chapter, $findings);

                if (! $scopeDecision->isResolved()) {
                    $findings[] = $this->unresolvedRewriteScopeFinding($scopeDecision->reason);
                    [$decision, $decisionBasis] = $this->decide($findings, $total, $blocked);
                } else {
                    $rewriteScope = $scopeDecision->toArray();
                }
            }
            $data = [
                'decision' => $decision->value,
                'decision_basis' => $decisionBasis,
                'recommended_decision' => $recommended->value,
                'score' => $total,
                'scores' => $scores,
                'dimension_audits' => $payload['dimension_audits'],
                'dimension_audits_raw' => $payload['dimension_audits_raw'] ?? $payload['dimension_audits'],
                'schema_repairs' => $payload['schema_repairs'] ?? [],
                'coverage_repairs' => data_get($run->context_snapshot, 'coverage_repairs', []),
                'repair_verification' => $this->repairVerificationOutcome($run, $findings),
                'foreshadowing_audits' => $payload['foreshadowing_audits'],
                'arc_beat_audits' => $payload['arc_beat_audits'],
                'arc_completion_audits' => $payload['arc_completion_audits'],
                'world_entity_candidate_audits' => $payload['world_entity_candidate_audits'],
                'unapproved_world_entities' => $payload['unapproved_world_entities'],
                'findings' => $findings,
                'rewrite_scope' => $rewriteScope,
                'source_artifact_id' => $draft->getKey(),
            ];
            $version = GenerationArtifact::query()->where('type', ArtifactType::ReviewResult)->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapter->getKey()))->max('version');
            $artifact = $run->artifacts()->create(['type' => ArtifactType::ReviewResult, 'version' => (int) $version + 1, 'content' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'data' => $data, 'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]);
            $review = $run->review()->create(['artifact_id' => $artifact->getKey(), 'decision' => $decision, 'score' => $total, ...collect($scores)->mapWithKeys(fn ($v, $k) => ["{$k}_score" => $v])->all(), 'findings' => $findings]);
            $chapter->update(['status' => match ($decision) {
                ReviewDecision::Rewrite => ChapterStatus::Rewrite, ReviewDecision::Block => ChapterStatus::Blocked, default => ChapterStatus::Review
            }]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $review;
        });
    }

    /** @return array{target_words: int, actual_words: int, minimum_words: int, maximum_words: int, completion_percentage: float, status: string} */
    private function lengthCheck(string $content, int $targetWords): array
    {
        $actualWords = $this->lengthPolicy->count($content);
        $minimumWords = $this->lengthPolicy->chapterMinimum($targetWords);
        $maximumWords = $this->lengthPolicy->chapterMaximum($targetWords);

        return [
            'target_words' => $targetWords,
            'actual_words' => $actualWords,
            'minimum_words' => $minimumWords,
            'maximum_words' => $maximumWords,
            'completion_percentage' => $this->lengthPolicy->completionPercentage($actualWords, $targetWords),
            'status' => $actualWords < $minimumWords ? 'too_short' : ($actualWords > $maximumWords ? 'too_long' : 'within_range'),
        ];
    }

    /** @param array<string, mixed> $lengthCheck
     * @return array<string, mixed>|null
     */
    private function lengthFinding(array $lengthCheck): ?array
    {
        if ($lengthCheck['status'] === 'within_range') {
            return null;
        }

        $tooShort = $lengthCheck['status'] === 'too_short';
        $limit = $tooShort ? $lengthCheck['minimum_words'] : $lengthCheck['maximum_words'];

        return [
            'code' => $tooShort ? 'CHAPTER_LENGTH_TOO_SHORT' : 'CHAPTER_LENGTH_TOO_LONG',
            'dimension' => 'pacing',
            'severity' => 'error',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'message' => $tooShort
                ? "当前草稿 {$lengthCheck['actual_words']} 字，目标 {$lengthCheck['target_words']} 字，至少需要达到 {$limit} 字。"
                : "当前草稿 {$lengthCheck['actual_words']} 字，目标 {$lengthCheck['target_words']} 字，最多建议控制在 {$limit} 字。",
            'evidence' => "当前稿完成度为 {$lengthCheck['completion_percentage']}%。",
            'source' => 'length_check',
        ];
    }

    /** @param array<string, int|float> $scores */
    private function weightedScore(array $scores): float
    {
        return round(collect(self::WEIGHTS)->sum(fn (float $weight, string $dimension): float => (float) $scores[$dimension] * $weight), 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $planFindings
     * @param  array<int, array<string, mixed>>  $reviewFindings
     * @return array<int, array<string, mixed>>
     */
    private function withoutDuplicateForeshadowingFindings(array $planFindings, array $reviewFindings): array
    {
        $roots = collect($reviewFindings)->map(fn (array $finding): string => ($finding['foreshadowing_id'] ?? '').'|'.($finding['foreshadowing_action'] ?? '').'|'.($finding['target_scene_id'] ?? $finding['scene_id'] ?? ''))
            ->filter()
            ->all();

        return collect($planFindings)->reject(fn (array $finding): bool => str_starts_with((string) ($finding['code'] ?? ''), 'FORESHADOWING_')
            && in_array(($finding['foreshadowing_id'] ?? '').'|'.($finding['foreshadowing_action'] ?? '').'|'.($finding['scene_id'] ?? ''), $roots, true))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $audits */
    private function validateDimensionAudits(array $audits): void
    {
        foreach (self::DIMENSIONS as $dimension) {
            $audit = $audits[$dimension] ?? null;
            if (! is_array($audit)
                || ! $this->hasExactKeys($audit, ['status', 'summary'])
                || ! in_array($audit['status'], ['pass', 'issues_found'], true)
                || ! is_string($audit['summary'])
                || blank($audit['summary'])) {
                throw ValidationException::withMessages(['dimension_audits' => 'Narrative Review 七维审计结构无效。']);
            }

        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>|null} */
    private function repairArcCompletionAudits(array $payload, GenerationRun $run, array $context, string $model): array
    {
        $contract = is_array($context['arc_completion_contract'] ?? null) ? $context['arc_completion_contract'] : [];
        $audits = is_array($payload['arc_completion_audits'] ?? null) ? $payload['arc_completion_audits'] : [];
        $draft = (string) data_get($context, 'draft.content', '');

        if ($this->arcCompletionAuditRepairer->valid($audits, $contract, $draft)) {
            return [$payload, null, null];
        }

        $repair = $this->arcCompletionAuditRepairer->repair($run, $contract, $audits, $draft, $model);
        if (($repair['status'] ?? null) === 'succeeded'
            && is_array($repair['audits'] ?? null)
            && $this->arcCompletionAuditRepairer->valid($repair['audits'], $contract, $draft)) {
            $payload['arc_completion_audits'] = $repair['audits'];

            return [$payload, $repair, null];
        }

        $payload['arc_completion_audits'] = collect($contract)->map(fn (array $item): array => [
            'arc_id' => (int) $item['arc_id'],
            'status' => 'not_met',
            'evidence' => null,
        ])->all();
        $finding = [
            'code' => 'ARC_COMPLETION_REPAIR_FAILED',
            'dimension' => 'workflow',
            'severity' => 'ambiguous',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => false,
            'requires_human_decision' => true,
            'message' => 'Arc Completion 审计结构修复失败，已保守按未完成处理，需要人工检查。',
            'evidence' => 'ai_request_log_id='.(string) ($repair['ai_request_log_id'] ?? 'unknown')
                .'; '.(string) ($repair['error_message'] ?? '未知错误'),
            'source' => 'arc_completion_repair',
            'ai_request_log_id' => $repair['ai_request_log_id'] ?? null,
        ];

        return [$payload, $repair, $finding];
    }

    /** @return array<string, mixed> */
    private function normalizeDimensionAudits(array $payload, GenerationRun $run, array $context, Chapter $chapter, string $model): array
    {
        $raw = $payload['dimension_audits'];
        $repairs = $payload['arc_completion_repair'] === null
            ? []
            : ['arc_completion' => $payload['arc_completion_repair']];
        $repairFindings = $payload['schema_repair_findings'] ?? [];

        foreach (self::DIMENSIONS as $dimension) {
            $hasFinding = collect($payload['findings'])->contains(
                fn (array $finding): bool => ($finding['dimension'] ?? null) === $dimension,
            );

            if (($raw[$dimension]['status'] ?? null) === 'issues_found' && ! $hasFinding) {
                $repair = $this->dimensionAuditRepairer->repair(
                    $run,
                    $dimension,
                    $raw[$dimension],
                    $context,
                    self::NARRATIVE_FINDING_CODES,
                    $model,
                );

                if (($repair['status'] ?? null) === 'succeeded') {
                    $candidateFindings = is_array($repair['findings'] ?? null) ? $repair['findings'] : [];
                    $invalid = collect($candidateFindings)->contains(
                        fn (mixed $finding): bool => ! is_array($finding) || ! $this->validNarrativeFinding($finding, $chapter),
                    );

                    if ($invalid) {
                        $repair = [
                            ...$repair,
                            'status' => 'failed',
                            'error_code' => 'review_schema_repair_invalid',
                            'error_message' => 'Review Schema Repair 返回了无效 Finding。',
                            'findings' => [],
                        ];
                    } else {
                        $payload['findings'] = [...$payload['findings'], ...$candidateFindings];
                        $raw[$dimension]['summary'] = $repair['summary'];
                    }
                }

                if (($repair['status'] ?? null) === 'failed') {
                    $repairFindings[] = [
                        'code' => 'REVIEW_SCHEMA_REPAIR_FAILED',
                        'dimension' => 'workflow',
                        'severity' => 'ambiguous',
                        'scene_id' => null,
                        'scope' => 'chapter',
                        'auto_fixable' => false,
                        'requires_human_decision' => true,
                        'message' => "{$dimension} 维度的审校结构修复失败，需要人工检查。",
                        'evidence' => 'ai_request_log_id='.(string) ($repair['ai_request_log_id'] ?? 'unknown')
                            .'; '.(string) ($repair['error_message'] ?? '未知错误'),
                        'source' => 'review_schema_repair',
                        'ai_request_log_id' => $repair['ai_request_log_id'] ?? null,
                    ];
                }

                $repairs[$dimension] = $repair;
            }
        }

        $findingDimensions = collect($payload['findings'])->pluck('dimension')->countBy();
        foreach (self::DIMENSIONS as $dimension) {
            $raw[$dimension]['status'] = (int) $findingDimensions->get($dimension, 0) > 0
                ? 'issues_found'
                : 'pass';
        }

        return [
            ...$payload,
            'dimension_audits_raw' => $payload['dimension_audits'],
            'dimension_audits' => $raw,
            'schema_repairs' => $repairs,
            'schema_repair_findings' => $repairFindings,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function repairCoverageJudgments(GenerationRun $run, Chapter $chapter, GenerationArtifact $draft, array $findings, string $model): array
    {
        $repairs = [];
        $scenePlans = array_values($chapter->latestPlan?->scene_plans ?? []);

        foreach (data_get($draft->data, 'scene_coverage', []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $sceneId = filter_var($row['scene_id'] ?? null, FILTER_VALIDATE_INT);
            $coverage = collect($row)->only(PlanCoverage::ELEMENTS)->all();
            $hasMissingPlanFinding = collect($findings)->contains(
                fn (array $finding): bool => ($finding['code'] ?? null) === 'SCENE_PLAN_COVERAGE_MISSING'
                    && (int) ($finding['scene_id'] ?? 0) === $sceneId,
            );

            if ($sceneId === false || ! $hasMissingPlanFinding || ! is_array($scenePlans[$index] ?? null)) {
                continue;
            }

            $repair = $this->coverageJudgmentRepairer->repair(
                $run,
                $sceneId,
                $coverage,
                $draft->content,
                $scenePlans[$index],
                $model,
            );
            $repairs[] = ['scene_id' => $sceneId, ...$repair];

            if (($repair['status'] ?? null) !== 'succeeded' || ! is_array($repair['coverage'] ?? null)) {
                $findings = collect($findings)->map(function (array $finding) use ($sceneId, $repair): array {
                    if (($finding['code'] ?? null) !== 'SCENE_PLAN_COVERAGE_MISSING'
                        || (int) ($finding['scene_id'] ?? 0) !== $sceneId) {
                        return $finding;
                    }

                    return [...$finding, 'coverage_adjudication' => [
                        'status' => 'failed',
                        'ai_request_log_id' => $repair['ai_request_log_id'] ?? null,
                        'error_code' => $repair['error_code'] ?? 'coverage_judgment_repair_failed',
                    ]];
                })->all();

                continue;
            }

            $repairedCoverage = $repair['coverage'];
            $findings = collect($findings)->flatMap(function (array $finding) use ($sceneId, $repairedCoverage, $repair): array {
                if (($finding['code'] ?? null) !== 'SCENE_PLAN_COVERAGE_MISSING'
                    || (int) ($finding['scene_id'] ?? 0) !== $sceneId) {
                    return [$finding];
                }

                $element = (string) ($finding['plan_element'] ?? '');
                $item = $repairedCoverage[$element] ?? null;

                if (! is_array($item)) {
                    return [$finding];
                }

                if (($item['status'] ?? null) === 'fulfilled') {
                    return [];
                }

                return [[
                    ...$finding,
                    'code' => ($item['status'] ?? null) === 'contradicted'
                        ? 'SCENE_PLAN_COVERAGE_CONTRADICTED'
                        : 'SCENE_PLAN_COVERAGE_MISSING',
                    'coverage_status' => $item['status'] ?? 'missing',
                    'evidence' => $item['evidence'] ?? null,
                    'coverage_adjudication' => [
                        'status' => 'succeeded',
                        'ai_request_log_id' => $repair['ai_request_log_id'] ?? null,
                        'prompt_version' => PlanCoverageJudgmentRepairer::PROMPT_VERSION,
                    ],
                ]];
            })->values()->all();
        }

        return [$findings, $repairs];
    }

    /** @return array<int, array<string, mixed>> */
    private function repairVerificationOutcome(GenerationRun $run, array $currentFindings): array
    {
        $required = data_get($run->context_snapshot, 'repair_verification.required_findings');

        if (! is_array($required)) {
            return [];
        }

        return collect($required)->filter(fn (mixed $finding): bool => is_array($finding))->values()
            ->map(function (array $finding, int $index) use ($currentFindings): array {
                $same = collect($currentFindings)->first(fn (array $current): bool => ($current['code'] ?? null) === ($finding['code'] ?? null)
                    && ($current['scene_id'] ?? null) === ($finding['scene_id'] ?? null)
                    && ($current['scope'] ?? null) === ($finding['scope'] ?? null));
                $replacement = $same === null
                    ? collect($currentFindings)->first(fn (array $current): bool => ($current['dimension'] ?? null) === ($finding['dimension'] ?? null)
                        && ($current['scene_id'] ?? null) === ($finding['scene_id'] ?? null))
                    : null;

                return [
                    'finding_ref' => $this->findingRef($finding, $index),
                    'code' => $finding['code'] ?? null,
                    'result' => $same !== null ? 'still_present' : ($replacement !== null ? 'replaced' : 'resolved'),
                    'current_code' => $same['code'] ?? $replacement['code'] ?? null,
                ];
            })->all();
    }

    private function findingRef(array $finding, int $index): string
    {
        return 'finding-'.($index + 1).'-'.substr(hash('sha256', json_encode([
            $finding['code'] ?? null,
            $finding['dimension'] ?? null,
            $finding['scene_id'] ?? null,
            $finding['scope'] ?? null,
            $finding['message'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 12);
    }

    /** @return array<string, mixed>|null */
    private function repairVerification(Chapter $chapter, GenerationArtifact $draft): ?array
    {
        $planningRunId = (int) $chapter->generationRuns()
            ->where('stage', GenerationStage::ChapterPlanning)
            ->where('status', RunStatus::Succeeded)
            ->latest('id')
            ->value('id');
        $review = Review::query()
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('id', '>', $planningRunId))
            ->with('artifact')
            ->latest('id')
            ->first();

        if ((int) data_get($review?->artifact?->data, 'source_artifact_id') === $draft->getKey()) {
            return null;
        }

        $findings = collect($review?->findings ?? [])
            ->filter(fn (mixed $finding): bool => is_array($finding)
                && (bool) ($finding['auto_fixable'] ?? false)
                && in_array($finding['source'] ?? null, [null, 'narrative_review', 'foreshadowing_review', 'assembly_foreshadowing_coverage', 'scene_foreshadowing_coverage'], true))
            ->values()
            ->all();

        if ($review === null || $findings === []) {
            return null;
        }

        return [
            'source_review_id' => $review->getKey(),
            'required_findings' => $findings,
        ];
    }

    /** @param array<string, mixed> $finding @return array<string, mixed> */
    private function normalizeStateFinding(array $finding): array
    {
        $sceneId = collect($finding['evidence'] ?? [])->pluck('scene_id')->first(fn (mixed $id): bool => is_int($id));
        $severity = (string) ($finding['severity'] ?? 'hard');

        return [
            ...$finding,
            'dimension' => 'state',
            'scene_id' => $sceneId,
            'scope' => $sceneId === null ? 'chapter' : 'scene',
            'auto_fixable' => false,
            'requires_human_decision' => $severity === 'ambiguous',
            'source' => 'state_validation',
        ];
    }

    /** @return array<string, mixed> */
    private function unresolvedRewriteScopeFinding(string $reason): array
    {
        $reasonLabel = match ($reason) {
            'paragraph_evidence_not_unique' => '段落证据无法唯一定位到一个当前 Scene。',
            'invalid_scene_reference' => 'Finding 未引用当前章节中的有效 Scene。',
            'chapter_finding_has_scene_reference' => 'Chapter 级 Finding 同时携带了 Scene 引用。',
            'finding_requires_human_decision' => 'Finding 同时要求人工决策，不能自动修复。',
            'unsupported_finding_scope' => 'Finding 使用了当前不支持的修复范围。',
            'invalid_persisted_rewrite_scope' => '已保存的 Rewrite 范围无效。',
            'no_auto_fixable_findings' => 'Review 没有可自动修复的 Finding。',
            default => '无法从当前 Finding 确定安全的最小修复范围。',
        };

        return [
            'code' => 'REWRITE_SCOPE_UNRESOLVED',
            'dimension' => 'workflow',
            'severity' => 'ambiguous',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => false,
            'requires_human_decision' => true,
            'message' => '无法自动选择安全的 Rewrite 范围，需要人工处理。',
            'evidence' => $reasonLabel,
            'source' => 'rewrite_scope_resolver',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array{ReviewDecision, array<string, mixed>}
     */
    private function decide(array $findings, float $score, bool $stateBlocked): array
    {
        $hardCodes = collect($findings)->filter(fn (array $finding): bool => ($finding['severity'] ?? null) === 'hard')->pluck('code')->unique()->values()->all();
        if ($stateBlocked || $hardCodes !== []) {
            return [ReviewDecision::Block, $this->decisionBasis('hard_finding', $hardCodes, $score)];
        }

        $humanCodes = collect($findings)->filter(fn (array $finding): bool => (bool) ($finding['requires_human_decision'] ?? false))->pluck('code')->unique()->values()->all();
        if ($humanCodes !== []) {
            return [ReviewDecision::NeedsAttention, $this->decisionBasis('human_decision_required', $humanCodes, $score)];
        }

        $autoFixableErrors = collect($findings)->filter(fn (array $finding): bool => (bool) ($finding['auto_fixable'] ?? false)
            && ($finding['severity'] ?? null) === 'error')->values();
        if ($autoFixableErrors->isNotEmpty()) {
            return [ReviewDecision::Rewrite, $this->decisionBasis('auto_fixable_error', $autoFixableErrors->all(), $score)];
        }

        $actionableWarnings = collect($findings)->filter(fn (array $finding): bool => (bool) ($finding['auto_fixable'] ?? false)
            && in_array($finding['severity'] ?? null, ['warning', 'soft'], true))->values();
        if ($score < (float) config('generation.review_pass_score', 80) && $actionableWarnings->isNotEmpty()) {
            return [ReviewDecision::Rewrite, $this->decisionBasis('below_threshold_actionable_warning', $actionableWarnings->all(), $score)];
        }

        if ($score < (float) config('generation.review_pass_score', 80)) {
            return [ReviewDecision::NeedsAttention, $this->decisionBasis('below_threshold_without_rewrite_path', $findings, $score)];
        }

        return [ReviewDecision::Pass, $this->decisionBasis('score_and_advisory_findings', $findings, $score)];
    }

    /** @param array<int, array<string, mixed>|string> $triggerFindings @return array<string, mixed> */
    private function decisionBasis(string $rule, array $triggerFindings, float $score): array
    {
        $findings = collect($triggerFindings)->values();

        return [
            'rule' => $rule,
            'finding_codes' => $findings->map(fn (array|string $finding): ?string => is_array($finding)
                ? ($finding['code'] ?? null)
                : $finding)->filter()->unique()->values()->all(),
            'finding_refs' => $findings->map(fn (array|string $finding, int $index): ?string => is_array($finding)
                ? $this->findingRef($finding, $index)
                : null)->filter()->values()->all(),
            'score' => $score,
            'pass_score' => (float) config('generation.review_pass_score', 80),
        ];
    }

    private function failRun(GenerationRun $run, Throwable $e): void
    {
        $run->update(['status' => RunStatus::Failed, 'error_code' => $e instanceof AiProviderException ? $e->errorCode : ($e instanceof ValidationException ? 'review_validation_failed' : 'review_failed'), 'error_message' => $e->getMessage(), 'finished_at' => now()]);
    }
}
