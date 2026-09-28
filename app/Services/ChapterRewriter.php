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
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChapterRewriter
{
    public function __construct(private readonly AiProvider $provider, private readonly AiSettingsResolver $settingsResolver, private readonly PromptVersionResolver $promptVersionResolver, private readonly DraftLengthPolicy $lengthPolicy, private readonly PreviousChapterEnding $previousChapterEnding, private readonly ContextBuilder $contextBuilder, private readonly GenerationRunLease $runLease, private readonly AutomaticRewriteCounter $rewriteCounter, private readonly RewriteScopeResolver $rewriteScopeResolver, private readonly PlanCoverageEvidenceRepairer $coverageEvidenceRepairer, private readonly GenerationFailurePolicy $failurePolicy) {}

    public function rewrite(int $chapterId, ?int $sceneId = null): ?GenerationArtifact
    {
        $chapter = Chapter::query()->with(['novel.canonicalStateVersion', 'latestPlan', 'scenes.currentArtifact'])->findOrFail($chapterId);
        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Rewrite。', false);
        }

        $review = $this->latestReview($chapter);
        $resolvedScope = $this->rewriteScopeResolver->resolveReview($chapter, $review);
        if (! $resolvedScope->isResolved()) {
            throw new AiProviderException('rewrite_scope_unresolved', '当前问题需要重建 Plan/Scene，不能执行整章自动 Rewrite。', false);
        }
        if ($sceneId === null) {
            $sceneId = $resolvedScope->sceneId;
        }
        if ($sceneId !== $resolvedScope->sceneId) {
            throw new AiProviderException('rewrite_scope_unresolved', '指定 Scene 不是当前 Review 的最早受影响 Scene。', false);
        }
        $completed = GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)
            ->where('data->source_review_id', $review->getKey())
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->first();
        if ($completed !== null) {
            return $completed;
        }
        $findings = $this->rewriteScopeResolver->findingsForScene($chapter, $review->findings, $sceneId);
        if ($findings === []) {
            throw new AiProviderException('rewrite_scope_unresolved', '当前 Finding 无法安全定位到指定 Scene，已停止自动重写。', false);
        }
        $source = $this->sceneSource($chapter, $sceneId);
        $paragraphPatch = collect($findings)->every(fn (array $finding): bool => ($finding['scope'] ?? null) === 'paragraph');
        $chapterTargetWords = (int) $chapter->latestPlan->target_words;
        $sceneAllocation = $this->sceneAllocation($chapter, $sceneId, $chapterTargetWords);
        $targetWords = $sceneAllocation['scene_target_words'] ?? $chapterTargetWords;
        $minimumWords = $sceneAllocation['required_scene_words'] ?? $this->lengthPolicy->chapterMinimum($targetWords);
        $maximumWords = $sceneAllocation['maximum_scene_words'] ?? $this->lengthPolicy->chapterMaximum($targetWords);
        $preferredMinimumWords = max($minimumWords, (int) ceil($targetWords * .95));
        $preferredMaximumWords = min($maximumWords, (int) floor($targetWords * 1.05));
        if ($preferredMinimumWords > $preferredMaximumWords) {
            $preferredMinimumWords = $minimumWords;
            $preferredMaximumWords = $maximumWords;
        }
        $attempt = $this->attemptCount($chapter) + 1;
        if ($attempt > (int) config('generation.max_rewrite_attempts', 2)) {
            $this->markExhausted($chapter, $review);
            throw new AiProviderException('rewrite_exhausted', 'Rewrite 已达到最大 2 次，已转为需要人工处理。', false);
        }

        $findingHash = hash('sha256', json_encode($findings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $settings = $this->settingsResolver->resolve(AiStage::Rewrite, $chapter->novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Rewrite);
        $styleContract = $this->contextBuilder->styleContractForChapter($chapter);
        $foreshadowingContract = $this->contextBuilder->foreshadowingContractForChapter($chapter);
        $brief = [
            'scope' => $paragraphPatch ? 'paragraph' : 'scene',
            'source_artifact_id' => $source->getKey(),
            'bible_version' => $styleContract['bible_version'],
            'style_contract_checksum' => $styleContract['checksum'],
            'l4' => $styleContract,
            'foreshadowing_contract_checksum' => $foreshadowingContract['checksum'],
            'foreshadowing_contract' => $foreshadowingContract,
            'findings' => $findings,
            'batch_repair' => [
                'required_finding_count' => count($findings),
                'required_finding_indexes' => array_keys($findings),
                'required_finding_codes' => collect($findings)->pluck('code')->values()->all(),
                'require_all_in_one_response' => true,
                'full_quality_check' => ['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'],
            ],
            'plan_acceptance' => $this->planAcceptance($chapter, $sceneId),
            'must_preserve' => $chapter->latestPlan->only(['chapter_function', 'arc_contribution', 'reader_promise', 'must_reveal']),
            'expected_fixes' => collect($findings)->pluck('message')->filter()->values()->all(),
            'must_not_change' => $chapter->latestPlan->only(['must_not_reveal', 'forbidden_conflicts']),
            'state_version' => $chapter->novel->canonicalStateVersion->version,
            'prompt_version' => $promptVersion,
            'current_state' => $chapter->novel->canonicalStateVersion->state,
            'previous_chapter_ending' => $this->previousChapterEnding->for($chapter),
            'locked_facts' => $chapter->novel->facts()->where('locked', true)->where('status', 'active')
                ->get()->map->only(['id', 'subject_type', 'subject_id', 'predicate', 'value'])->all(),
            'length_requirement' => [
                'target_words' => $targetWords,
                'minimum_words' => $minimumWords,
                'maximum_words' => $maximumWords,
                'preferred_minimum_words' => $preferredMinimumWords,
                'preferred_maximum_words' => $preferredMaximumWords,
                'current_words' => $this->lengthPolicy->count($source->content),
                'chapter_target_words' => $chapterTargetWords,
                'allocated_scene_words' => $sceneAllocation['allocated_scene_words'],
            ],
            'content' => $source->content,
        ];
        $brief['generation_preferences']['rewrite_token_budget'] = [
            'initial_max_completion_tokens' => (int) config('generation.rewrite_max_output_tokens', 16_000),
            'retry_max_completion_tokens' => (int) config('generation.rewrite_retry_max_output_tokens', 20_000),
            'final_retry_max_completion_tokens' => (int) config('generation.rewrite_final_retry_max_output_tokens', 24_000),
        ];
        $inputHash = hash('sha256', json_encode([$brief, $settings->provider, $settings->model, $settings->reasoningEffort, $promptVersion], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $baseKey = "rewrite:{$source->getKey()}:{$findingHash}:{$attempt}:{$promptVersion}";
        [$run, $reused] = $this->startRun($chapter, $sceneId, $source, $findingHash, $attempt, $inputHash, $brief, $settings->provider, $settings->model, $promptVersion);
        if ($reused) {
            return $run->artifacts()->where('type', ArtifactType::RewriteDraft)->first();
        }

        try {
            $maxTokens = $this->resolveRequestBudget($run, $baseKey);
            $metadata = ['generation_run_id' => $run->getKey(), 'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scene_id' => $sceneId, 'stage' => AiStage::Rewrite->value];
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                reasoningEffort: $settings->reasoningEffort,
                systemPrompt: $this->systemPrompt($paragraphPatch ? 'paragraph' : 'scene'),
                prompt: '请根据以下修订要求重写正文：'.json_encode($brief, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .3,
                maxTokens: $maxTokens,
                responseSchema: $paragraphPatch ? ParagraphPatchPayload::schema() : SceneRewritePayload::schema(),
                promptVersion: $promptVersion,
                metadata: $metadata,
            ));
            $structured = StructuredOutput::require($response, 'rewrite', $paragraphPatch ? 'Paragraph Patch' : 'Scene Rewrite');
            if ($paragraphPatch) {
                try {
                    $payload = ParagraphPatchPayload::apply($structured, $source->content);
                    $matchesFinding = collect($findings)->contains(function (array $finding) use ($payload): bool {
                        $evidence = $finding['evidence'] ?? null;

                        return is_string($evidence) && $evidence !== ''
                            && (str_contains($payload['patch']['search'], $evidence) || str_contains($evidence, $payload['patch']['search']));
                    });
                    if (! $matchesFinding) {
                        throw ValidationException::withMessages(['paragraph_patch.search' => 'Paragraph Patch 必须命中当前 Paragraph Finding 的逐字证据。']);
                    }
                } catch (ValidationException $exception) {
                    throw new AiProviderException('rewrite_schema_invalid', $exception->getMessage(), false, null, $exception);
                }
            } else {
                $payload = $this->responsePayload(
                    content: $response->content,
                    structuredData: $structured,
                    sceneRewrite: true,
                    provider: $settings->provider,
                    model: $settings->model,
                    metadata: $metadata,
                    task: $brief['plan_acceptance'],
                    chapter: $chapter,
                    foreshadowingContract: $foreshadowingContract,
                    reasoningEffort: $settings->reasoningEffort,
                );
                $payload = $this->repairLengthIfNeeded(
                    payload: $payload,
                    brief: $brief,
                    model: $settings->model,
                    promptVersion: $promptVersion,
                    metadata: $metadata,
                    sceneRewrite: true,
                    reasoningEffort: $settings->reasoningEffort,
                );
            }
            $this->validateLength($payload['content'], $brief['length_requirement']);

            return $this->complete($run, $chapter, $sceneId, $source, $review, $payload, $findingHash, $attempt, $brief['state_version']);
        } catch (Throwable $exception) {
            $this->failurePolicy->record($run, $exception, 'rewrite_failed');
            throw $exception;
        }
    }

    public function markTerminalFailure(int $chapterId): void
    {
        Chapter::query()->whereKey($chapterId)->update(['status' => ChapterStatus::Blocked]);
    }

    private function latestReview(Chapter $chapter): Review
    {
        $review = Review::query()->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))->latest('id')->first();
        if ($review === null || ! in_array($review->decision, [ReviewDecision::Rewrite, ReviewDecision::NeedsAttention], true)) {
            throw new AiProviderException('rewrite_not_required', '当前 Chapter 没有可执行的 Rewrite Review。', false);
        }
        if ($chapter->latestPlan === null || $chapter->novel->canonicalStateVersion === null) {
            throw new AiProviderException('rewrite_context_incomplete', 'Rewrite 缺少 Chapter Plan 或 Story State。', false);
        }

        return $review;
    }

    private function sceneSource(Chapter $chapter, int $sceneId): GenerationArtifact
    {
        $scene = Scene::query()->where('chapter_id', $chapter->getKey())->findOrFail($sceneId);
        if ($scene->currentArtifact === null || ! in_array($scene->currentArtifact->type, [ArtifactType::SceneDraft, ArtifactType::RewriteDraft], true)) {
            throw new AiProviderException('rewrite_input_incomplete', 'Rewrite 缺少 Scene Draft。', false);
        }

        return $scene->currentArtifact;
    }

    /** @return array<string, int> */
    private function sceneAllocation(Chapter $chapter, int $sceneId, int $chapterTarget): array
    {
        $otherScenes = $chapter->scenes->where('id', '!=', $sceneId);
        $allocatedWords = $otherScenes->sum(fn (Scene $scene): int => $this->lengthPolicy->count($scene->currentArtifact?->content));

        return $this->lengthPolicy->sceneAllocation($chapterTarget, $allocatedWords, 1);
    }

    private function attemptCount(Chapter $chapter): int
    {
        return $this->rewriteCounter->countFor($chapter);
    }

    private function systemPrompt(string $scope): string
    {
        $base = '你是 XNovel 批量定向重写器。findings 是本轮必须一次性解决的完整问题批次；必须逐项修复 batch_repair.required_finding_indexes 指定的全部问题，不得只处理第一项、最严重项或最容易处理的项，也不得把剩余问题留给下一轮。严格保留 plan_acceptance 要求的剧情结果和既定事实。foreshadowing_contract 是本章冻结的唯一伏笔动作契约；必须保留并修复 actions 中的既定动作，不得主动处理未列入 actions 的未来伏笔，不得把 promised_payoff 当作允许直接揭晓的正文信息，并继续遵守 must_not_change.must_not_reveal。完成全部指定修复后，必须重新通读最终正文，对 continuity、plan、character、progress、repetition、pacing、style 七个维度进行一次全量自检，并立即修复重写过程中产生或原稿中仍然明显存在的同类问题；尤其检查时间地点、人物身体状态、物品位置、动作因果、重复表达和文风参数，避免修好旧问题又保留或引入低级矛盾。l4 是唯一的 Style Contract；重写必须保持其中的 POV、时态和主文风，只按指定方式使用辅助文风，不得在修复过程中改换叙述声音。处理连续性问题时必须对照 previous_chapter_ending，让正文交代必要的时间、地点和行动过渡。正文必须达到 length_requirement.minimum_words 且不得超过 length_requirement.maximum_words，并优先进入 preferred_minimum_words～preferred_maximum_words 的窄目标区间；字数统计排除空白和换行。原稿已处于硬范围时，应保持原有段落结构和整体篇幅；修复重复或节奏问题必须净缩减。当前稿超限时，修复其他问题的同时必须通过删除重复解释、重复感受、重复争论和不推动情节的细节实现净缩减。字数不足时，通过展开原有动作、对话、环境、感官、心理和过渡补足，不得用无意义重复凑字，不得编造重大事实、能力、世界规则或角色知识。';

        $scope = $scope === 'paragraph'
            ? '当前 scope=paragraph，只返回一个 search/replacement 补丁。search 必须逐字复制目标 Scene 中唯一出现的一段连续原文；replacement 只修复该处，不得返回整章或整 Scene。self_check 必须基于应用补丁后的 Scene。'
            : '当前 scope=scene，只返回该 Scene 的完整替换稿，不得改写其他 Scene。按 Schema 同时返回 goal、conflict、turn、outcome 的 self_check；fulfilled 和 contradicted 的 evidence 必须逐字引用最终 content，missing 的 evidence 必须为 null。';

        return $base.$scope.NarrativeProsePolicy::writing();
    }

    /**
     * @param  array<string, mixed>|null  $structuredData
     * @return array<string, mixed>
     */
    private function responsePayload(string $content, ?array $structuredData, bool $sceneRewrite, ?string $provider = null, ?string $model = null, array $metadata = [], mixed $task = null, ?Chapter $chapter = null, array $foreshadowingContract = [], ?string $reasoningEffort = null): array
    {
        if ($structuredData === null) {
            throw new AiProviderException('rewrite_schema_invalid', 'Scene Rewrite 未返回合法的结构化结果。', false);
        }

        try {
            return SceneRewritePayload::validate($structuredData);
        } catch (ValidationException $exception) {
            if ($model === null || ! $this->containsOnlyCoverageEvidenceErrors($exception)) {
                throw new AiProviderException('rewrite_schema_invalid', $exception->getMessage(), false, null, $exception);
            }
        }

        $structuredData['self_check'] = $this->coverageEvidenceRepairer->repair(
            coverage: is_array($structuredData['self_check'] ?? null) ? $structuredData['self_check'] : [],
            content: is_string($structuredData['content'] ?? null) ? $structuredData['content'] : '',
            provider: (string) $provider,
            model: $model,
            metadata: $metadata,
            task: $task,
            path: 'self_check',
            reasoningEffort: $reasoningEffort,
        );

        try {
            return SceneRewritePayload::validate($structuredData);
        } catch (ValidationException $exception) {
            throw new AiProviderException('rewrite_schema_invalid', $exception->getMessage(), false, null, $exception);
        }
    }

    private function containsOnlyCoverageEvidenceErrors(ValidationException $exception): bool
    {
        $fields = array_keys($exception->errors());

        return $fields !== [] && collect($fields)->every(
            fn (string $field): bool => preg_match('/^self_check\.(goal|conflict|turn|outcome)\.evidence$/', $field) === 1,
        );
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $contract @param array<string, mixed> $metadata */
    /** @return array<string, mixed> */
    private function planAcceptance(Chapter $chapter, int $sceneId): array
    {
        $scene = $chapter->scenes->firstWhere('id', $sceneId);
        if ($scene === null) {
            throw new AiProviderException('rewrite_scope_unresolved', '指定 Scene 不属于当前 Chapter。', false);
        }

        $scenePlan = data_get($chapter->latestPlan->scene_plans, $scene->sequence - 1, []);

        return [
            'scene_id' => $scene->getKey(),
            'sequence' => $scene->sequence,
            'continuity_requirements' => is_array($scenePlan) ? ($scenePlan['continuity_requirements'] ?? []) : [],
            'coverage_expectations' => PlanCoverage::expectations(
                $scene->only(PlanCoverage::ELEMENTS),
                is_array($scenePlan) ? $scenePlan : [],
            ),
        ];
    }

    /** @param array<string, mixed> $brief
     * @param  array<string, mixed>  $metadata
     */
    private function repairLengthIfNeeded(array $payload, array $brief, string $model, string $promptVersion, array $metadata, bool $sceneRewrite, ?string $reasoningEffort = null): array
    {
        $requirement = $brief['length_requirement'];

        for ($attempt = 1; $attempt <= (int) config('generation.max_rewrite_length_repair_attempts', 2); $attempt++) {
            $actual = $this->lengthPolicy->count($payload['content']);
            $minimum = (int) $requirement['minimum_words'];
            $maximum = (int) $requirement['maximum_words'];
            $tooShort = $actual < $minimum;
            $tooLong = $actual > $maximum;

            if (! $tooShort && ! $tooLong) {
                break;
            }

            $response = $this->provider->generate(new AiRequest(
                model: $model,
                reasoningEffort: $reasoningEffort,
                systemPrompt: ($tooLong
                    ? '你是 XNovel 场景重写稿压缩器。当前稿仍然超限。l4 是唯一的 Style Contract；压缩后必须保持其中的 POV、时态、主文风和辅助文风层级。保留必须修复的问题、plan_acceptance、连续性和既定事实，删除重复解释、重复感受、重复争论与不推动情节的细节。最终正文应接近 target_words，且不得超过 maximum_words；字数统计排除空白和换行。不得截断句子，不得输出摘要或解释，不得新增重大事实。必须同时返回覆盖最终正文的固定 self_check。'
                    : '你是 XNovel 场景重写稿扩写器。当前稿仍然过短。l4 是唯一的 Style Contract；扩写后必须保持其中的 POV、时态、主文风和辅助文风层级。保留已经完成的修复、plan_acceptance 和既定事实，通过原有场景内的动作、对话、环境、感官、心理和自然过渡补足。最终正文至少达到 minimum_words，并尽量接近 target_words，且不得超过 maximum_words；字数统计排除空白和换行。不得无意义重复，不得新增重大事实。必须同时返回覆盖最终正文的固定 self_check。').NarrativeProsePolicy::writing(),
                prompt: ($tooLong ? '请压缩以下重写稿：' : '请扩写以下重写稿：').json_encode([
                    'scope' => $brief['scope'],
                    'findings' => $brief['findings'],
                    'plan_acceptance' => $brief['plan_acceptance'],
                    'must_preserve' => $brief['must_preserve'],
                    'must_not_change' => $brief['must_not_change'],
                    'l4' => $brief['l4'],
                    'length_requirement' => $requirement,
                    'current_words' => $actual,
                    'required_reduction_words' => $tooLong ? $actual - $maximum : 0,
                    'repair_attempt' => $attempt,
                    'draft' => $payload,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: (int) config('generation.rewrite_max_output_tokens', 12_000),
                responseSchema: SceneRewritePayload::schema(),
                promptVersion: $promptVersion,
                metadata: [...$metadata, 'length_repair_attempt' => $attempt, 'length_repair_mode' => $tooLong ? 'compress' : 'expand'],
            ));
            $payload = $this->responsePayload(
                content: $response->content,
                structuredData: StructuredOutput::require($response, 'rewrite', 'Scene Rewrite'),
                sceneRewrite: $sceneRewrite,
                model: $model,
                metadata: [...$metadata, 'length_repair_attempt' => $attempt],
                task: $brief['plan_acceptance'],
                reasoningEffort: $reasoningEffort,
            );
        }

        return $payload;
    }

    /** @param array<string, mixed> $requirement */
    private function validateLength(string $content, array $requirement): void
    {
        $actual = $this->lengthPolicy->count($content);
        $minimum = (int) $requirement['minimum_words'];
        $maximum = (int) $requirement['maximum_words'];

        if ($actual < $minimum || $actual > $maximum) {
            throw new AiProviderException(
                'rewrite_length_out_of_range',
                "重写稿 {$actual} 字，必须控制在 {$minimum}～{$maximum} 字；本次结果不会成为当前版本。",
                false,
            );
        }
    }

    private function startRun(Chapter $chapter, int $sceneId, GenerationArtifact $source, string $findingHash, int $attempt, string $inputHash, array $brief, string $provider, string $model, string $promptVersion): array
    {
        return DB::transaction(function () use ($chapter, $sceneId, $source, $findingHash, $attempt, $inputHash, $brief, $provider, $model, $promptVersion) {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $key = "rewrite:{$source->getKey()}:{$findingHash}:{$attempt}:{$promptVersion}";
            $existing = GenerationRun::query()->where('idempotency_key', $key)->first();
            if ($existing !== null && $existing->status === RunStatus::Succeeded) {
                return [$existing, true];
            }
            if ($existing !== null && $existing->status === RunStatus::Failed) {
                $retry = GenerationRun::query()->where('idempotency_key', 'like', $key.'%')->count() + 1;
                $key .= ':retry:'.$retry;
            }
            $active = $chapter->generationRuns()->where('stage', GenerationStage::Rewrite)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
            if ($this->runLease->isFresh($active)) {
                return [$active, true];
            }
            if ($active) {
                $active->update(['status' => RunStatus::Failed, 'error_code' => 'worker_interrupted', 'error_message' => 'Rewrite Run 超时未完成，已由后续投递恢复。', 'error_retryable' => false, 'error_metadata' => ['category' => 'worker_lost'], 'finished_at' => now()]);
            }

            return [GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scene_id' => $sceneId,
                'scope_type' => 'scene', 'scope_id' => $sceneId,
                'stage' => GenerationStage::Rewrite, 'status' => RunStatus::Running, 'attempt' => $attempt,
                'idempotency_key' => $key, 'input_hash' => $inputHash,
                'state_version' => $chapter->novel->canonicalStateVersion()->value('version'),
                'bible_version' => $brief['bible_version'],
                'prompt_version' => $promptVersion, 'provider' => $provider, 'model_policy' => $model,
                'context_snapshot' => [...collect($brief)->except('content')->all(), 'finding_hash' => $findingHash], 'started_at' => now(),
            ]), false];
        });
    }

    private function resolveRequestBudget(GenerationRun $run, string $baseKey): int
    {
        $priorTruncatedRuns = GenerationRun::query()
            ->where('chapter_id', $run->chapter_id)
            ->where('stage', GenerationStage::Rewrite)
            ->where('provider', $run->provider)
            ->where('model_policy', $run->model_policy)
            ->where('id', '<', $run->getKey())
            ->where('error_code', 'rewrite_output_truncated')
            ->get(['idempotency_key', 'context_snapshot'])
            ->filter(fn (GenerationRun $prior): bool => $prior->idempotency_key === $baseKey
                || str_starts_with($prior->idempotency_key, $baseKey.':retry:'))
            ->values();
        $retryOrdinal = $priorTruncatedRuns->count() + 1;
        $budget = (array) data_get($run->context_snapshot, 'generation_preferences.rewrite_token_budget', []);
        $maxTokens = match ($retryOrdinal) {
            1 => (int) ($budget['initial_max_completion_tokens'] ?? 16_000),
            2 => (int) ($budget['retry_max_completion_tokens'] ?? 20_000),
            default => (int) ($budget['final_retry_max_completion_tokens'] ?? 24_000),
        };
        $priorMaximum = $priorTruncatedRuns
            ->map(fn (GenerationRun $prior): int => (int) data_get($prior->context_snapshot, 'generation_preferences.max_completion_tokens', 0))
            ->max();
        $snapshot = $run->context_snapshot ?? [];
        data_set($snapshot, 'generation_preferences.rewrite_retry_ordinal', $retryOrdinal);
        data_set($snapshot, 'generation_preferences.max_completion_tokens', $maxTokens);
        $run->update(['context_snapshot' => $snapshot]);

        if (is_int($priorMaximum) && $priorMaximum >= $maxTokens) {
            throw new AiProviderException(
                'rewrite_output_budget_exhausted',
                "Rewrite 已在冻结的最高输出预算 {$maxTokens} Token 下被截断；请提高预算或调整 Rewrite 模型后再重试。",
                false,
            );
        }

        return $maxTokens;
    }

    private function complete(GenerationRun $run, Chapter $chapter, int $sceneId, GenerationArtifact $source, Review $review, array $payload, string $findingHash, int $attempt, int $expectedStateVersion): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $chapter, $sceneId, $source, $review, $payload, $findingHash, $attempt, $expectedStateVersion) {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());
            if ($chapter->novel->canonicalStateVersion?->version !== $expectedStateVersion) {
                throw new AiProviderException('state_version_conflict', 'Rewrite 期间 Canonical Story State 已变化。', false);
            }
            $version = (int) GenerationArtifact::query()
                ->where('type', ArtifactType::RewriteDraft)
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->max('version') + 1;
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::RewriteDraft, 'version' => $version, 'content' => $payload['content'],
                'data' => [
                    'scope' => (string) data_get($run->context_snapshot, 'scope', 'scene'),
                    'source_artifact_id' => $source->getKey(),
                    'source_review_id' => $review->getKey(),
                    'finding_hash' => $findingHash,
                    'attempt' => $attempt,
                    'repair_findings' => data_get($run->context_snapshot, 'findings', []),
                    'plan_acceptance' => data_get($run->context_snapshot, 'plan_acceptance'),
                    ...(isset($payload['patch']) ? ['paragraph_patch' => $payload['patch']] : []),
                    'self_check' => $payload['self_check'],
                    // 确定性 Assembly 只读取当前 Scene Artifact；局部 Rewrite 必须继续携带
                    // 冻结的伏笔 Coverage 身份，不能让章节拼装回退到模型重建该数组。
                    'foreshadowing_coverage' => data_get($source->data, 'foreshadowing_coverage', []),
                    'plan_findings' => PlanCoverage::findings(
                        $sceneId,
                        $payload['self_check'],
                        data_get($run->context_snapshot, 'plan_acceptance.coverage_expectations', []),
                        'scene_rewrite_self_check',
                    ),
                    'word_count' => $this->lengthPolicy->count($payload['content']),
                    'target_words' => (int) data_get($run->context_snapshot, 'length_requirement.target_words'),
                    'minimum_words' => (int) data_get($run->context_snapshot, 'length_requirement.minimum_words'),
                    'maximum_words' => (int) data_get($run->context_snapshot, 'length_requirement.maximum_words'),
                ],
                'checksum' => hash('sha256', $payload['content']),
            ]);
            Scene::query()->whereKey($sceneId)->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);
            $chapter->update(['status' => ChapterStatus::Rewrite]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    private function markExhausted(Chapter $chapter, Review $source): Review
    {
        return DB::transaction(function () use ($chapter, $source): Review {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $key = 'review:rewrite-exhausted:'.$chapter->getKey().':'.$source->getKey();
            $existing = GenerationRun::query()->where('idempotency_key', $key)->with('review')->first();
            if ($existing?->review !== null) {
                return $existing->review;
            }
            $run = GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded,
                'attempt' => $source->generationRun->attempt + 1, 'idempotency_key' => $key,
                'input_hash' => hash('sha256', $key.'|'.data_get($source->generationRun->context_snapshot, 'style_contract_checksum')), 'state_version' => $source->generationRun->state_version,
                'bible_version' => $source->generationRun->bible_version,
                'prompt_version' => $source->generationRun->prompt_version, 'model_policy' => 'deterministic',
                'context_snapshot' => [
                    'reason' => 'rewrite_exhausted',
                    'source_review_id' => $source->getKey(),
                    'bible_version' => $source->generationRun->bible_version,
                    'style_contract_checksum' => data_get($source->generationRun->context_snapshot, 'style_contract_checksum'),
                    'l4' => data_get($source->generationRun->context_snapshot, 'l4'),
                ],
                'started_at' => now(), 'finished_at' => now(),
            ]);
            $findings = [...$source->findings, [
                'code' => 'REWRITE_EXHAUSTED',
                'dimension' => 'workflow',
                'severity' => 'ambiguous',
                'scene_id' => null,
                'scope' => 'chapter',
                'auto_fixable' => false,
                'requires_human_decision' => true,
                'message' => '自动 Rewrite 已达到最大 2 次，需要人工处理。',
                'evidence' => '已完成 2 次自动 Rewrite。',
                'source' => 'rewrite_loop',
            ]];
            $data = [
                'decision' => ReviewDecision::NeedsAttention->value,
                'decision_basis' => [
                    'rule' => 'human_decision_required',
                    'finding_codes' => ['REWRITE_EXHAUSTED'],
                    'score' => (float) $source->score,
                    'pass_score' => (float) config('generation.review_pass_score', 80),
                ],
                'score' => (float) $source->score,
                'findings' => $findings,
                'source_review_id' => $source->getKey(),
            ];
            $version = GenerationArtifact::query()->where('type', ArtifactType::ReviewResult)
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))->max('version');
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::ReviewResult, 'version' => (int) $version + 1,
                'content' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'data' => $data, 'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            ]);
            $review = $run->review()->create([
                'artifact_id' => $artifact->getKey(), 'decision' => ReviewDecision::NeedsAttention,
                'score' => $source->score, 'continuity_score' => $source->continuity_score,
                'plan_score' => $source->plan_score, 'character_score' => $source->character_score,
                'progress_score' => $source->progress_score, 'repetition_score' => $source->repetition_score,
                'pacing_score' => $source->pacing_score, 'style_score' => $source->style_score, 'findings' => $findings,
            ]);
            $chapter->update(['status' => ChapterStatus::Review]);

            return $review;
        });
    }
}
