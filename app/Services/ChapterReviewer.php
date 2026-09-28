<?php

namespace App\Services;

use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\NarrativeProsePolicy;
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

    public function __construct(private readonly AiProvider $provider, private readonly AiSettingsResolver $settingsResolver, private readonly PromptVersionResolver $promptVersionResolver, private readonly StateValidator $stateValidator, private readonly AutoStopService $autoStop, private readonly DraftLengthPolicy $lengthPolicy, private readonly PreviousChapterEnding $previousChapterEnding, private readonly ContextBuilder $contextBuilder, private readonly GenerationRunLease $runLease, private readonly AutomaticRewriteCounter $rewriteCounter, private readonly RewriteScopeResolver $rewriteScopeResolver, private readonly ForeshadowingReviewAudit $foreshadowingReviewAudit, private readonly PlanningReviewAudit $planningReviewAudit, private readonly OutlineCompletionService $outlineCompletion, private readonly GenerationFailurePolicy $failurePolicy) {}

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
            'chapter_plan' => $chapter->latestPlan?->only(['id', 'version', 'chapter_function', 'arc_contribution', 'arc_contributions', 'character_candidates', 'world_entity_candidates', 'reader_promise', 'target_words', 'must_reveal', 'may_hint', 'must_not_reveal', 'foreshadowing_actions', 'scene_plans']),
            'outline_completion_contract' => $this->outlineCompletion->contract($chapter),
            'arc_completion_contract' => $this->arcCompletionContract($chapter),
            'existing_world_entities' => $chapter->novel->worldEntities()->where('status', 'active')->get()
                ->map->only(['id', 'type', 'name', 'description'])->values()->all(),
            'existing_characters' => $chapter->novel->characters()->get()
                ->map->only(['id', 'name', 'aliases', 'role', 'status'])->values()->all(),
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
        $this->assertDeterministicDraftContract($chapter, $draft, (int) $context['state_version']);

        $deterministicFindings = [
            ...array_map(fn (array $finding): array => $this->normalizeStateFinding($finding), $context['state_findings']),
            ...($lengthCheck['status'] === 'within_range' ? [] : [$this->lengthFinding($lengthCheck)]),
            ...$planFindings,
        ];
        $deterministicErrors = collect($deterministicFindings)->filter(fn (array $finding): bool => in_array($finding['severity'] ?? null, ['error', 'hard', 'ambiguous'], true))->values()->all();
        if ($deterministicErrors !== []) {
            $review = $this->completeDeterministicReview($chapter, $draft, $context, $deterministicErrors, $stateValidation->isBlocked());
            $this->autoStop->stopForReview($chapter, $review->decision, $stateValidation->isBlocked());

            return $review;
        }

        $settings = $this->settingsResolver->resolve(AiStage::Reviewer, $chapter->novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Reviewer);
        $context['prompt_version'] = $promptVersion;
        $context['generation_preferences']['review_token_budget'] = [
            'max_legal_output_tokens' => min(
                (int) config('generation.review_max_output_tokens', 12_000),
                (int) data_get($chapter->latestPlan?->admission_snapshot, 'capacity.review.max_output_tokens', config('generation.review_max_output_tokens', 12_000)),
            ),
            'context_token_budget' => (int) data_get($chapter->latestPlan?->admission_snapshot, 'capacity.review.context_token_budget', config('generation.review_context_token_budget', 32_000)),
        ];
        $reviewOperationId = $regenerate ? $operationId : null;
        $input = [
            'context' => $context,
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'prompt_version' => $promptVersion,
            'pass_score' => config('generation.review_pass_score', 80),
            ...($reviewOperationId === null ? [] : ['operation_id' => $reviewOperationId]),
        ];
        $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $baseKey = "review:{$draft->checksum}:{$context['state_version']}:{$promptVersion}";
        [$run, $reused] = $this->startRun($chapter, $baseKey, $inputHash, $context, $settings->provider, $settings->model, $promptVersion, $regenerate, $reviewOperationId);
        if ($reused) {
            return $run->review;
        }

        try {
            $maxTokens = $this->resolveRequestBudget($run, $baseKey);
            $this->assertReviewCapacity($context, $maxTokens);

            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                reasoningEffort: $settings->reasoningEffort,
                systemPrompt: '你是 XNovel 语义叙事审校器。Laravel 已完成长度、来源链、版本、Coverage、State 与 Locked Fact 等确定性校验；不得重复报告这些结论。只判断七维叙事质量、冻结 Outline/Plan 条件、候选人物与世界实体、伏笔动作的语义是否由正文支持。所有审计数组必须按输入冻结契约的原顺序逐项返回，但不得复述数据库 ID、键、条件文本、Scene ID 或汇总状态；Laravel 会按位置恢复身份并计算汇总。fulfilled、introduced 或 contradicted 必须引用当前正文中的连续逐字证据；missing、not_met 与 not_applicable 的 evidence 必须为 null。Findings 只报告有明确正文证据的语义问题，相同根因与修复动作必须合并；不得返回推荐 Decision。scope=paragraph 仅用于可由一处唯一原文替换的问题，scope=scene 仅用于单场景完整替换，章节功能、Milestone、Handoff、剧情结果、跨 Scene 结构与整体节奏问题必须使用 scope=chapter，由 Laravel 转入 Plan/Scene 重建。'.NarrativeProsePolicy::reviewing(),
                prompt: '请根据章节计划和确定性状态检查结果审校以下章节草稿，并确保所有面向用户的说明均使用简体中文：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .2, maxTokens: $maxTokens, responseSchema: $this->schema(), promptVersion: $promptVersion,
                metadata: ['generation_run_id' => $run->getKey(), 'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'stage' => AiStage::Reviewer->value],
            ));
            $payload = StructuredOutput::require($response, 'review', 'Narrative Review');
            if (! array_key_exists('recommended_decision', $payload)) {
                $payload = CompactReviewPayload::expand(
                    $payload,
                    $chapter,
                    $draft,
                    $context['outline_completion_contract'],
                    $foreshadowingContract,
                );
            }
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
            $payload['schema_repair_findings'] = [];

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

    private function assertDeterministicDraftContract(Chapter $chapter, GenerationArtifact $draft, int $stateVersion): void
    {
        if (data_get($draft->data, 'assembly_strategy') !== 'deterministic_v1') {
            return;
        }

        if ($draft->generationRun?->state_version !== null && $draft->generationRun->state_version !== $stateVersion) {
            throw new AiProviderException('review_version_mismatch', 'Chapter Draft 的 Story State Version 已过期。', false);
        }

        $scenes = $chapter->scenes->sortBy('sequence')->values();
        $expectedIds = $scenes->modelKeys();
        $expectedArtifactIds = $scenes->map(fn ($scene): ?int => $scene->current_artifact_id)->all();
        $expectedChecksums = $scenes->map(fn ($scene): ?string => $scene->currentArtifact?->checksum)->all();
        if (data_get($draft->data, 'ordered_scene_ids') !== $expectedIds
            || data_get($draft->data, 'source_artifact_ids') !== $expectedArtifactIds
            || data_get($draft->data, 'source_checksums') !== $expectedChecksums
            || hash('sha256', (string) $draft->content) !== $draft->checksum) {
            throw new AiProviderException('review_lineage_invalid', 'Chapter Draft 与当前 Scene Artifact 的确定性来源链不一致。', false);
        }

        $coverageIds = collect(data_get($draft->data, 'scene_coverage', []))->pluck('scene_id')->all();
        if ($coverageIds !== $expectedIds) {
            throw new AiProviderException('review_coverage_invalid', 'Chapter Draft 的 Scene Coverage 身份或顺序无效。', false);
        }
        foreach (data_get($draft->data, 'scene_coverage', []) as $index => $coverage) {
            $content = (string) $scenes[$index]->currentArtifact?->content;
            foreach (['goal', 'conflict', 'turn', 'outcome'] as $element) {
                $status = data_get($coverage, "{$element}.status");
                $evidence = data_get($coverage, "{$element}.evidence");
                if (in_array($status, ['fulfilled', 'contradicted'], true)
                    && (! is_string($evidence) || $evidence === '' || ! str_contains($content, $evidence))) {
                    throw new AiProviderException('review_coverage_invalid', "Scene Coverage {$element} 的证据不属于当前 Scene Artifact。", false);
                }
            }
        }
    }

    /** @param array<string, mixed> $context @param array<int, array<string, mixed>> $findings */
    private function completeDeterministicReview(Chapter $chapter, GenerationArtifact $draft, array $context, array $findings, bool $blocked): Review
    {
        return DB::transaction(function () use ($chapter, $draft, $context, $findings, $blocked): Review {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion', 'scenes.currentArtifact')->findOrFail($chapter->getKey());
            if ($chapter->novel->canonicalStateVersion?->version !== $context['state_version']) {
                throw new AiProviderException('state_version_conflict', 'Review 期间 Canonical Story State 已变化。', false);
            }
            $hash = hash('sha256', json_encode([$draft->checksum, $context['state_version'], $findings], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $key = 'review:deterministic:'.$hash;
            $existing = GenerationRun::query()->where('idempotency_key', $key)->with('review')->first();
            if ($existing?->review !== null) {
                return $existing->review;
            }
            [$decision, $basis] = $this->decide($findings, 100, $blocked);
            $scope = null;
            $repairAdvice = null;
            if ($decision === ReviewDecision::Rewrite) {
                $scopeDecision = $this->rewriteScopeResolver->resolve($chapter, $findings);
                if ($scopeDecision->isResolved()) {
                    $scope = $scopeDecision->toArray();
                } else {
                    $findings[] = $this->unresolvedRewriteScopeFinding($scopeDecision->reason);
                    [$decision, $basis] = $this->decide($findings, 100, $blocked);
                    $repairAdvice = $this->repairAdvice($scopeDecision->reason, $findings);
                }
            }
            $run = GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter', 'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded, 'attempt' => (int) $chapter->generationRuns()->where('stage', GenerationStage::Review)->max('attempt') + 1,
                'idempotency_key' => $key, 'input_hash' => $hash, 'state_version' => $context['state_version'], 'bible_version' => $context['bible_version'],
                'prompt_version' => 'deterministic-review-preflight-v1', 'provider' => 'deterministic', 'model_policy' => 'deterministic',
                'context_snapshot' => ['source_artifact_id' => $draft->getKey(), 'semantic_review_performed' => false], 'started_at' => now(), 'finished_at' => now(),
            ]);
            $scores = array_fill_keys(self::DIMENSIONS, 100);
            $data = [
                'decision' => $decision->value, 'decision_basis' => $basis, 'score' => 100,
                'scores' => $scores, 'scores_status' => 'not_evaluated', 'semantic_review_performed' => false,
                'findings' => $findings, 'rewrite_scope' => $scope, 'repair_advice' => $repairAdvice,
                'source_artifact_id' => $draft->getKey(),
            ];
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $version = (int) GenerationArtifact::query()->where('type', ArtifactType::ReviewResult)->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapter->getKey()))->max('version') + 1;
            $artifact = $run->artifacts()->create(['type' => ArtifactType::ReviewResult, 'version' => $version, 'content' => $encoded, 'data' => $data, 'checksum' => hash('sha256', $encoded)]);
            $review = $run->review()->create(['artifact_id' => $artifact->getKey(), 'decision' => $decision, 'score' => 100, ...collect($scores)->mapWithKeys(fn ($value, $key) => ["{$key}_score" => $value])->all(), 'findings' => $findings]);
            $chapter->update(['status' => match ($decision) {
                ReviewDecision::Rewrite => ChapterStatus::Rewrite, ReviewDecision::Block => ChapterStatus::Blocked, default => ChapterStatus::Review
            }]);

            return $review;
        });
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
        return CompactReviewPayload::schema();
    }

    private function validate(array $payload, Chapter $chapter, GenerationArtifact $draft, array $foreshadowingContract, ?array $eventCandidate, bool $hasDeterministicRoute): array
    {
        $required = ['scores', 'dimension_audits', 'foreshadowing_audits', 'chapter_plan_completion', 'milestone_completion', 'beat_exit', 'handoff_readiness', 'arc_beat_audits', 'arc_completion_audits', 'character_candidate_audits', 'world_entity_candidate_audits', 'unapproved_characters', 'unapproved_world_entities', 'findings'];
        if (array_diff($required, array_keys($payload)) !== []
            || ! is_array($payload['scores'])
            || ! $this->hasExactKeys($payload['scores'], self::DIMENSIONS)
            || ! is_array($payload['dimension_audits'])
            || ! $this->hasExactKeys($payload['dimension_audits'], self::DIMENSIONS)
            || ! is_array($payload['foreshadowing_audits'])
            || ! is_array($payload['findings'])) {
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

    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $provider, string $model, string $promptVersion, bool $regenerate, ?string $operationId): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $provider, $model, $promptVersion, $regenerate, $operationId) {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = $chapter->generationRuns()->where('stage', GenerationStage::Review);
            $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
            if ($this->runLease->isFresh($active)) {
                return [$active, true];
            }
            if ($active) {
                $active->update(['status' => RunStatus::Failed, 'error_code' => 'worker_interrupted', 'error_message' => 'Review Run 超时未完成，已由后续投递恢复。', 'error_retryable' => false, 'error_metadata' => ['category' => 'worker_lost'], 'finished_at' => now()]);
            }
            if ((! $regenerate || $operationId !== null)
                && ($done = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$done, true];
            }
            $attempt = (int) $runs->clone()->max('attempt') + 1;
            $run = GenerationRun::query()->create(['novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Running, 'attempt' => $attempt, 'idempotency_key' => $attempt === 1 ? $baseKey : "$baseKey:attempt:$attempt", 'input_hash' => $inputHash, 'state_version' => $context['state_version'], 'bible_version' => $context['bible_version'], 'prompt_version' => $promptVersion, 'provider' => $provider, 'model_policy' => $model, 'context_snapshot' => [...$context, 'draft' => collect($context['draft'])->except('content')->all(), ...($operationId === null ? [] : ['operation_id' => $operationId])], 'started_at' => now()]);

            return [$run, false];
        });
    }

    private function resolveRequestBudget(GenerationRun $run, string $baseKey): int
    {
        $priorTruncatedRuns = GenerationRun::query()
            ->where('chapter_id', $run->chapter_id)
            ->where('stage', GenerationStage::Review)
            ->where('provider', $run->provider)
            ->where('model_policy', $run->model_policy)
            ->where('id', '<', $run->getKey())
            ->where('error_code', 'review_output_truncated')
            ->get(['idempotency_key', 'context_snapshot'])
            ->filter(fn (GenerationRun $prior): bool => $prior->idempotency_key === $baseKey
                || str_starts_with($prior->idempotency_key, $baseKey.':attempt:'))
            ->values();
        $budget = (array) data_get($run->context_snapshot, 'generation_preferences.review_token_budget', []);
        $maxTokens = (int) ($budget['max_legal_output_tokens'] ?? config('generation.review_max_output_tokens', 12_000));
        $snapshot = $run->context_snapshot ?? [];
        data_set($snapshot, 'generation_preferences.review_retry_ordinal', $priorTruncatedRuns->count() + 1);
        data_set($snapshot, 'generation_preferences.max_completion_tokens', $maxTokens);
        $run->update(['context_snapshot' => $snapshot]);

        if ($priorTruncatedRuns->isNotEmpty()) {
            throw new AiProviderException(
                'review_capacity_mismatch',
                "Narrative Review 已在当前 Reviewer Route 的最大合法输出预算 {$maxTokens} Token 下截断；请调整模型、Context 或 Route 容量后重试。",
                false,
            );
        }

        if ($maxTokens < 1) {
            throw new AiProviderException('review_capacity_mismatch', 'Reviewer Route 没有合法的输出容量。', false);
        }

        return $maxTokens;
    }

    /** @param array<string, mixed> $context */
    private function assertReviewCapacity(array $context, int $maxTokens): void
    {
        $contextBudget = (int) data_get($context, 'generation_preferences.review_token_budget.context_token_budget', 0);
        $serialized = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        // 中文正文通常接近一字一 Token；这里再计入结构化 JSON 的保守余量。
        $estimatedInputTokens = (int) ceil(mb_strlen($serialized) * 1.15);
        if ($contextBudget < 1 || $estimatedInputTokens + $maxTokens > $contextBudget) {
            throw new AiProviderException(
                'review_capacity_mismatch',
                "Reviewer Route 容量不足：估算输入 {$estimatedInputTokens} Token，加最大输出 {$maxTokens} Token，超过 Context {$contextBudget} Token。",
                false,
            );
        }
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
            $findings = collect($findings)->unique(fn (array $finding): string => hash('sha256', json_encode([
                $finding['code'] ?? null,
                $finding['dimension'] ?? null,
                $finding['scope'] ?? null,
                $finding['scene_id'] ?? null,
                $finding['evidence'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))->values()->all();
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
            $repairAdvice = null;
            if ($decision === ReviewDecision::Rewrite) {
                $scopeDecision = $this->rewriteScopeResolver->resolve($chapter, $findings);

                if (! $scopeDecision->isResolved()) {
                    $findings[] = $this->unresolvedRewriteScopeFinding($scopeDecision->reason);
                    [$decision, $decisionBasis] = $this->decide($findings, $total, $blocked);
                    $repairAdvice = $this->repairAdvice($scopeDecision->reason, $findings);
                } else {
                    $rewriteScope = $scopeDecision->toArray();
                }
            }
            $data = [
                'decision' => $decision->value,
                'decision_basis' => $decisionBasis,
                'score' => $total,
                'scores' => $scores,
                'dimension_audits' => $payload['dimension_audits'],
                'dimension_audits_raw' => $payload['dimension_audits_raw'] ?? $payload['dimension_audits'],
                'schema_repairs' => $payload['schema_repairs'] ?? [],
                'repair_verification' => $this->repairVerificationOutcome($run, $findings),
                'foreshadowing_audits' => $payload['foreshadowing_audits'],
                'chapter_plan_completion' => $payload['chapter_plan_completion'],
                'milestone_completion' => $payload['milestone_completion'],
                'beat_exit' => $payload['beat_exit'],
                'handoff_readiness' => $payload['handoff_readiness'],
                'outline_completion_identity' => $payload['outline_completion_identity'],
                'outline_completion_contract_checksum' => $payload['outline_completion_contract_checksum'],
                'arc_beat_audits' => $payload['arc_beat_audits'],
                'arc_completion_audits' => $payload['arc_completion_audits'],
                'character_candidate_audits' => $payload['character_candidate_audits'],
                'world_entity_candidate_audits' => $payload['world_entity_candidate_audits'],
                'unapproved_characters' => $payload['unapproved_characters'],
                'unapproved_world_entities' => $payload['unapproved_world_entities'],
                'findings' => $findings,
                'rewrite_scope' => $rewriteScope,
                'repair_advice' => $repairAdvice,
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
            'plan_or_scene_rebuild_required' => '问题涉及章节功能、Milestone、Handoff、剧情结果或跨 Scene 结构，必须重建 Plan/Scene。',
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

    /** @param array<int, array<string, mixed>> $findings @return array<string, mixed> */
    private function repairAdvice(string $reason, array $findings): array
    {
        $sceneIds = collect($findings)->pluck('scene_id')->filter(fn (mixed $id): bool => is_int($id))->unique()->values();

        return [
            'action' => 'rebuild_plan_or_scenes',
            'reason' => $reason,
            'earliest_scene_id' => $sceneIds->isEmpty() ? null : $sceneIds->min(),
            'finding_codes' => collect($findings)->pluck('code')->filter()->unique()->values()->all(),
            'next_stage' => GenerationStage::ChapterPlanning->value,
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
        $this->failurePolicy->record(
            $run,
            $e,
            $e instanceof ValidationException ? 'review_validation_failed' : 'review_failed',
        );
    }
}
