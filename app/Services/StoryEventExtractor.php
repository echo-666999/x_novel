<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\ResolvedAiSettings;
use App\AI\Exceptions\AiProviderException;
use App\AI\StructuredOutput;
use App\Data\StoryEventCandidate;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Exceptions\GenerationStageDeferredException;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoryEventExtractor
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly GenerationRunCoordinator $runCoordinator,
        private readonly StoryEventEvidenceRepairer $evidenceRepairer,
        private readonly StoryEventEvidenceQuoteResolver $evidenceQuoteResolver,
        private readonly ContextBuilder $contextBuilder,
        private readonly ForeshadowingEventValidator $foreshadowingEventValidator,
        private readonly OutlineCompletionService $outlineCompletion,
        private readonly GenerationFailurePolicy $failurePolicy,
        private readonly PlanAdmissionService $planAdmission,
        private readonly GenerationOutputCapacityGuard $outputCapacity,
        private readonly GenerationRequestBudget $requestBudget,
    ) {}

    public function extract(int $chapterId, bool $regenerate = false, bool $singleProviderCall = false, ?int $recoveryRunId = null): ?GenerationArtifact
    {
        $providerCalls = 0;
        $chapter = Chapter::query()->with([
            'novel.canonicalStateVersion',
            'latestPlan',
            'scenes' => fn ($query) => $query
                ->select(['id', 'chapter_id', 'sequence', 'current_artifact_id'])
                ->orderBy('sequence'),
            'scenes.currentArtifact:id,content',
        ])->findOrFail($chapterId);

        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Story Event Extraction。', false);
        }

        try {
            $draft = $this->latestChapterDraft($chapter);
            $context = $this->context($chapter, $draft);
            if ($chapter->latestPlan === null) {
                throw new AiProviderException('event_capacity_contract_missing', 'Story Event Extraction 缺少 Chapter Plan。', false);
            }
            $recoveryRun = $recoveryRunId === null
                ? null
                : $this->legacyRecoveryRun($recoveryRunId, $chapter, $draft, $context);
            if ($recoveryRun !== null) {
                $extractorRoute = $this->outputCapacity->frozenRouteForRun($chapter, $recoveryRun, AiStage::Extractor);
                $frozenRoute = (array) data_get($recoveryRun->context_snapshot, 'generation_preferences.frozen_route', []);
                $settings = new ResolvedAiSettings(
                    stage: AiStage::Extractor,
                    provider: (string) $extractorRoute['provider'],
                    model: (string) $extractorRoute['model'],
                    reasoningEffort: $extractorRoute['reasoning_effort'] ?? null,
                    source: (string) ($frozenRoute['source'] ?? 'legacy_event_extraction_recovery'),
                );
                $promptVersion = (string) $extractorRoute['prompt_version'];
                // 恢复 Run 的来源与路由合同必须进入 Stage 指纹，避免与旧 Admission v1 的失败链混用。
                $context['recovery'] = data_get($recoveryRun->context_snapshot, 'recovery');
            } else {
                $settings = $this->planAdmission->historicalRouteFor($chapter->latestPlan, AiStage::Extractor);
                $promptVersion = $this->planAdmission->historicalPromptVersionFor($chapter->latestPlan, AiStage::Extractor);
                $extractorRoute = $this->outputCapacity->frozenRoute($chapter, AiStage::Extractor);
            }
            $context['generation_preferences']['model_capacity'] = $extractorRoute['model_capacity'];
            $context['generation_preferences']['request_budgets'] = $extractorRoute['request_budgets'];
            // Run 自身保存冻结路由，恢复和页面诊断都不再读取当前后台配置来猜测历史请求。
            $context['generation_preferences']['frozen_route'] = [
                'provider' => $settings->provider,
                'model' => $settings->model,
                'reasoning_effort' => $settings->reasoningEffort,
                'source' => $settings->source,
                'prompt_version' => $promptVersion,
            ];
            $context['generation_preferences']['route_contract_source'] = $recoveryRun === null
                ? 'plan_admission'
                : GenerationOutputCapacityGuard::LEGACY_EVENT_RECOVERY_CONTRACT;
            $context['generation_preferences']['repair_budgets'] = $recoveryRun === null
                ? ['event_evidence' => ['initial' => (int) config('generation.event_evidence_repair_max_output_tokens', 1_000), 'retry' => (int) config('generation.event_evidence_repair_retry_max_output_tokens', 4_000)]]
                : (array) data_get($recoveryRun->context_snapshot, 'generation_preferences.repair_budgets', []);
            $inputHash = app(GenerationStageFingerprint::class)->make(
                GenerationStage::EventExtraction,
                $context,
                upstreamChecksums: [$draft->checksum],
                frozen: ['provider' => $settings->provider, 'model' => $settings->model, 'reasoning_effort' => $settings->reasoningEffort],
                contractVersion: $promptVersion,
            );
            $baseKey = $recoveryRun === null
                ? "events:{$draft->checksum}:{$context['state_version']}:{$promptVersion}"
                : (string) data_get($recoveryRun->context_snapshot, 'recovery.base_key');
            if ($baseKey === '') {
                throw new AiProviderException('extractor_recovery_contract_missing', '事件提取恢复 Run 缺少幂等链标识。', false);
            }
            [$run, $reused] = $this->startRun(
                $chapter,
                $baseKey,
                $inputHash,
                $context,
                $settings->provider,
                $settings->model,
                $promptVersion,
                $regenerate,
                $recoveryRun?->getKey(),
            );
        } catch (Throwable $exception) {
            if ($recoveryRunId !== null) {
                $this->failPreparedRecoveryRun($recoveryRunId, $chapter->getKey(), $exception);
            }

            throw $exception;
        }

        if ($reused) {
            return $run->artifacts()->where('type', ArtifactType::EventCandidate)->latest('version')->first();
        }

        $budget = null;
        try {
            $budget = $this->resolveRequestBudget($run, $baseKey);
            $metadata = [
                'generation_run_id' => $run->getKey(),
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'stage' => AiStage::Extractor->value,
            ];
            $beforeRequest = function (string $substage) use ($singleProviderCall, &$providerCalls): void {
                if ($singleProviderCall && $providerCalls >= 1) {
                    throw new GenerationStageDeferredException(GenerationStage::EventExtraction, $substage);
                }
                $providerCalls++;
            };
            $payload = $this->latestPayloadCheckpoint($chapter, $inputHash);
            if ($payload === null) {
                $beforeRequest('event_extraction');
                $request = new AiRequest(
                    model: $settings->model,
                    provider: $settings->provider,
                    reasoningEffort: $settings->reasoningEffort,
                    systemPrompt: '你是 XNovel 故事事件提取器。只识别会改变后续故事状态的事件，并返回符合 Schema 的 JSON。event_type 与 subject_type 必须严格遵守 event_subject_type_rules；subject_id 必须引用 current_state 中已存在的实体，或引用 Chapter Plan 冻结的 Candidate Key。正文确实引入批准人物候选时必须输出 character_introduced，subject_type=character，subject_id=chapter_plan.character_candidates[].candidate_key，payload 包含 candidate_key；正文确实引入批准世界实体候选时必须输出 world_entity_introduced，subject_type=world_entity，subject_id=chapter_plan.world_entity_candidates[].candidate_key，payload 包含 candidate_key；不得为未批准候选生成 Introduced Event。不要在 events 中输出 story_arc_beat_completed 或 story_arc_beat_milestone_completed；必须改为在 outline_completion 中按冻结条件原顺序分别审计 Milestone、Beat Exit 和 Handoff，不得返回数据库 ID。fulfilled/contradicted 必须给出当前正文逐字证据和所属数据库 Scene ID，not_met 的 evidence 必须为 null。Laravel 会结合历史 Canonical Milestone Event、恢复冻结 ID 并决定是否创建 Completion Candidate。没有有效主体时必须省略该事件，不能借用角色 ID 充当其他类型 ID。current_state.world.entities 中的对象统一使用 subject_type=world_entity，其内部 type（例如 concept、rule、location、faction）不能作为 subject_type。foreshadowing_contract 是本章冻结的唯一伏笔动作契约；foreshadowing_* 候选只能引用 actions 中的 foreshadowing_id，事件类型必须与 plan_action.action 一致，而且对应 Scene 的最终 foreshadowing_coverage 必须为 fulfilled。事件 evidence 必须覆盖逐字证据并使用目标 Scene；evidence.scene_id 只能填 current_scene_references[].scene_id 中的数据库 ID，不得把 sequence 当作 scene_id。未列入契约、Coverage 为 missing/contradicted、动作不匹配或只有主题相似的内容不能生成事件。其他自然语言内容必须使用简体中文。每条 evidence quote 必须逐字复制自给定章节草稿，不得改写、概括或补字。含义不确定时必须降低 confidence。不得修改正式故事数据。',
                    prompt: '请从以下章节草稿和权威上下文中提取故事事件候选：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    temperature: 0.2,
                    maxTokens: $budget['max_completion_tokens'],
                    responseSchema: $this->responseSchema(),
                    promptVersion: $promptVersion,
                    metadata: $metadata,
                );
                // 事件提取的输入正文可能很长，必须在发送前同时检查冻结预算与剩余上下文。
                $this->outputCapacity->assertRequestWithinFrozenRoute(
                    $chapter,
                    $run,
                    AiStage::Extractor,
                    $request,
                    'event_extraction',
                    $budget,
                );
                $response = $this->provider->generate($request);
                $payload = StructuredOutput::require($response, 'event', 'Story Event Candidates');
                $this->savePayloadCheckpoint($run, $payload, $inputHash, $draft);
            }

            [$candidates, $outlineCompletion] = $this->validateCandidates(
                $payload,
                $chapter,
                $draft,
                $settings->provider,
                $settings->model,
                $metadata,
                $context['foreshadowing_contract'],
                $settings->reasoningEffort,
                $beforeRequest,
                $run,
                $inputHash,
            );

            return $this->complete(
                $run,
                $chapter,
                $draft,
                $candidates,
                $outlineCompletion,
                $context['state_version'],
                $context['foreshadowing_contract_checksum'],
            );
        } catch (Throwable $exception) {
            if ($exception instanceof AiProviderException && is_array($budget)) {
                // 事件提取只在下一档冻结预算能解决当前耗尽类型时重试。
                $exception = $this->requestBudget->classifyRetry(
                    $exception,
                    (array) data_get($run->context_snapshot, 'generation_preferences.request_budgets', []),
                    AiStage::Extractor,
                    $budget,
                    'event',
                    'Story Event Extraction',
                    data_get($run->fresh()->context_snapshot, 'generation_preferences.request_budget_tier'),
                );
            }
            $this->failRun($run, $exception);

            throw $exception;
        }
    }

    public function markTerminalFailure(int $chapterId): void
    {
        Chapter::query()->whereKey($chapterId)->update(['status' => ChapterStatus::Blocked]);
    }

    /** @return array<string, mixed> */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['events', 'outline_completion'],
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => StoryEventCandidate::schema(),
                ],
                'outline_completion' => OutlineCompletionService::extractionSchema(),
            ],
        ];
    }

    private function latestChapterDraft(Chapter $chapter): GenerationArtifact
    {
        $draft = GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey())->whereNull('scene_id'))
            ->orderByDesc('id')
            ->first();

        if ($draft === null) {
            throw new AiProviderException('event_input_incomplete', 'Story Event Extraction 缺少 Chapter Draft。', false);
        }

        return $draft;
    }

    /** @return array<string, mixed> */
    private function context(Chapter $chapter, GenerationArtifact $draft): array
    {
        if ($chapter->latestPlan === null || $chapter->novel->canonicalStateVersion === null) {
            throw new AiProviderException('event_context_incomplete', 'Story Event Extraction 缺少 Chapter Plan 或 Story State。', false);
        }

        $foreshadowingContract = $this->contextBuilder->foreshadowingContractForChapter($chapter);

        return [
            'chapter_id' => $chapter->getKey(),
            'chapter_draft' => [
                'artifact_id' => $draft->getKey(),
                'checksum' => $draft->checksum,
                'content' => $draft->content,
            ],
            'chapter_plan' => $chapter->latestPlan->only([
                'id', 'version', 'chapter_function', 'arc_contribution', 'reader_promise',
                'must_reveal', 'may_hint', 'must_not_reveal', 'required_facts', 'forbidden_conflicts',
                'foreshadowing_actions', 'character_candidates',
                'arc_contributions', 'world_entity_candidates',
            ]),
            'current_scene_references' => $chapter->scenes->map(fn ($scene): array => [
                'scene_id' => $scene->getKey(),
                'sequence' => $scene->sequence,
                'source_artifact_id' => $scene->current_artifact_id,
            ])->values()->all(),
            'bible_version' => $foreshadowingContract['bible_version'],
            'state_version' => $chapter->novel->canonicalStateVersion->version,
            'current_state' => $chapter->novel->canonicalStateVersion->state,
            'foreshadowing_contract_checksum' => $foreshadowingContract['checksum'],
            'foreshadowing_contract' => $foreshadowingContract,
            'outline_completion_contract' => $this->outlineCompletion->contract($chapter),
            'event_subject_type_rules' => collect(EventType::generationCases())->mapWithKeys(
                fn (EventType $type): array => [$type->value => $type->allowedSubjectTypes()],
            )->all(),
            'locked_facts' => $chapter->novel->facts()
                ->where('locked', true)
                ->where('status', 'active')
                ->get()
                ->map->only(['id', 'subject_type', 'subject_id', 'predicate', 'value'])
                ->all(),
        ];
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $provider, string $model, string $promptVersion, bool $regenerate, ?int $recoveryRunId = null): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $provider, $model, $promptVersion, $recoveryRunId): array {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            if ($recoveryRunId !== null) {
                $prepared = GenerationRun::query()->lockForUpdate()->findOrFail($recoveryRunId);
                if ($prepared->status === RunStatus::Queued) {
                    if ($prepared->chapter_id !== $chapter->getKey()
                        || $prepared->stage !== GenerationStage::EventExtraction
                        || $prepared->idempotency_key !== $baseKey) {
                        throw new AiProviderException('extractor_recovery_contract_mismatch', '排队中的事件提取恢复 Run 与当前章节或幂等链不一致。', false);
                    }
                    if ($chapter->status === ChapterStatus::Blocked) {
                        $chapter->update(['status' => ChapterStatus::Generating]);
                    }
                    // Worker 激活页面预先持久化的 Run；Route、容量和预算来自该 Run，绝不回写旧 Plan Admission。
                    $prepared->update([
                        'status' => RunStatus::Running,
                        'input_hash' => $inputHash,
                        'state_version' => $context['state_version'],
                        'bible_version' => $context['bible_version'],
                        'context_snapshot' => [
                            ...$context,
                            'chapter_draft' => collect($context['chapter_draft'])->except('content')->all(),
                        ],
                        'started_at' => now(),
                        'finished_at' => null,
                        'error_code' => null,
                        'error_message' => null,
                        'error_retryable' => null,
                        'error_metadata' => null,
                    ]);

                    return [$prepared->refresh(), false];
                }
            }

            $runs = $chapter->generationRuns()->where('stage', GenerationStage::EventExtraction);
            $resolution = $this->runCoordinator->resolve($runs->getQuery(), $inputHash, 'Event Extraction Run 超时未完成，已由后续投递恢复。');
            if ($resolution['reused']) {
                return [$resolution['run'], true];
            }
            $attempt = $resolution['attempt'];

            if ($chapter->status === ChapterStatus::Blocked) {
                $chapter->update(['status' => ChapterStatus::Generating]);
            }

            return [GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scene_id' => null,
                'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::EventExtraction,
                'status' => RunStatus::Running,
                'attempt' => $attempt,
                'idempotency_key' => $attempt === 1 ? $baseKey : $baseKey.':attempt:'.$attempt,
                'input_hash' => $inputHash,
                'state_version' => $context['state_version'],
                'bible_version' => $context['bible_version'],
                'prompt_version' => $promptVersion,
                'provider' => $provider,
                'model_policy' => $model,
                'context_snapshot' => [
                    ...$context,
                    'chapter_draft' => collect($context['chapter_draft'])->except('content')->all(),
                ],
                'started_at' => now(),
            ]), false];
        });
    }

    /** @param array<string, mixed> $context */
    private function legacyRecoveryRun(int $runId, Chapter $chapter, GenerationArtifact $draft, array $context): GenerationRun
    {
        $run = GenerationRun::query()->findOrFail($runId);
        $source = data_get($run->context_snapshot, 'recovery.source');
        $plan = $chapter->latestPlan;
        $currentScenes = $chapter->scenes->map(fn ($scene): array => [
            'scene_id' => $scene->getKey(),
            'sequence' => $scene->sequence,
            'current_artifact_id' => $scene->current_artifact_id,
        ])->values()->all();
        $currentPlanChecksum = $plan?->checksum ?: $plan?->semanticChecksum();
        $currentAdmissionChecksum = hash('sha256', json_encode(
            app(GenerationStageFingerprint::class)->normalize($plan?->admission_snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        if ($run->chapter_id !== $chapter->getKey()
            || $run->stage !== GenerationStage::EventExtraction
            || data_get($run->context_snapshot, 'generation_preferences.route_contract_source') !== GenerationOutputCapacityGuard::LEGACY_EVENT_RECOVERY_CONTRACT
            || ! is_array($source)
            || (int) ($source['plan_id'] ?? 0) !== $plan?->getKey()
            || (int) ($source['plan_version'] ?? 0) !== $plan?->version
            || (string) ($source['plan_checksum'] ?? '') !== (string) $currentPlanChecksum
            || (int) data_get($plan?->admission_snapshot, 'schema_version') !== 1
            || (string) ($source['admission_snapshot_checksum'] ?? '') !== $currentAdmissionChecksum
            || (int) ($source['draft_artifact_id'] ?? 0) !== $draft->getKey()
            || (string) ($source['draft_checksum'] ?? '') !== $draft->checksum
            || (int) ($source['state_version'] ?? -1) !== (int) $context['state_version']
            || (int) ($source['bible_version'] ?? -1) !== (int) $context['bible_version']
            || ($source['scene_artifacts'] ?? null) !== $currentScenes) {
            $exception = new AiProviderException(
                'extractor_recovery_source_changed',
                '事件提取恢复合同冻结后，Plan、Scene、Chapter Draft、Bible 或 Canonical State 已变化；未发起 Provider 请求。',
                false,
            );
            // 预检发生在 Provider Run 激活前，也必须把失败写回预创建 Run，避免页面永久显示 queued。
            if (in_array($run->status, [RunStatus::Queued, RunStatus::Running], true)) {
                $run->update([
                    'status' => RunStatus::Failed,
                    'error_code' => $exception->errorCode,
                    'error_message' => $exception->getMessage(),
                    'error_retryable' => false,
                    'error_metadata' => [
                        'category' => 'recovery_source_changed',
                        'stage' => GenerationStage::EventExtraction->value,
                        'next_action' => '重新创建事件提取恢复合同',
                    ],
                    'finished_at' => now(),
                ]);
            }

            throw $exception;
        }

        return $run;
    }

    private function failPreparedRecoveryRun(int $runId, int $chapterId, Throwable $exception): void
    {
        $run = GenerationRun::query()
            ->whereKey($runId)
            ->where('chapter_id', $chapterId)
            ->where('stage', GenerationStage::EventExtraction)
            ->first();

        if ($run === null || ! in_array($run->status, [RunStatus::Queued, RunStatus::Running], true)) {
            return;
        }

        // 恢复合同先于 Provider 请求持久化，因此任何预检失败也必须结束该 Run，不能留下虚假的排队状态。
        $this->failurePolicy->record($run, $exception, 'event_recovery_preflight_failed');
    }

    /** @return array{0: array<int, StoryEventCandidate>, 1: array<string, mixed>} */
    private function validateCandidates(array $payload, Chapter $chapter, GenerationArtifact $draft, string $provider, string $model, array $metadata, array $foreshadowingContract, ?string $reasoningEffort, ?callable $beforeRequest, GenerationRun $run, string $inputHash): array
    {
        if (! $this->hasExactKeys($payload, ['events', 'outline_completion']) || ! is_array($payload['events']) || ! is_array($payload['outline_completion'])) {
            throw ValidationException::withMessages(['events' => 'Story Event Extractor 必须返回 events 与 outline_completion。']);
        }

        $outlineCompletion = $this->outlineCompletion->validateExtraction($payload['outline_completion'], $chapter, $draft);

        $candidates = collect($payload['events'])
            ->reject(fn (mixed $event): bool => is_array($event) && in_array(
                $event['event_type'] ?? null,
                [EventType::StoryArcBeatCompleted->value, EventType::StoryArcBeatMilestoneCompleted->value],
                true,
            ))
            ->values()
            ->map(function (mixed $event, int $index) use ($chapter, $draft, $provider, $model, $metadata, $reasoningEffort, $beforeRequest, $run, $inputHash): StoryEventCandidate {
                if (! is_array($event)) {
                    throw ValidationException::withMessages(["events.{$index}" => '第 '.($index + 1).' 个事件必须是对象。']);
                }

                try {
                    $event = $this->normalizeEvidence($event, $chapter, $draft);

                    try {
                        $candidate = StoryEventCandidate::fromArray($event);
                        $this->validateEvidence($candidate, $chapter, $draft);
                    } catch (ValidationException $exception) {
                        if (! $this->isEvidenceQuoteMismatch($exception)) {
                            throw $exception;
                        }

                        $repairCheckpoint = $this->latestEvidenceRepairCheckpoint($chapter, $inputHash, $index);
                        $event['evidence'] = $this->evidenceRepairer->repair(
                            event: $event,
                            content: (string) $draft->content,
                            provider: $provider,
                            model: $model,
                            metadata: $metadata,
                            eventIndex: $index,
                            reasoningEffort: $reasoningEffort,
                            beforeRequest: $beforeRequest,
                            startingAttempt: (int) ($repairCheckpoint['attempt'] ?? 0) + 1,
                            resumeStructuredData: $repairCheckpoint['response'] ?? null,
                            afterResponse: fn (?array $response, int $attempt) => $this->saveEvidenceRepairCheckpoint($run, $inputHash, $draft, $index, $attempt, $response),
                        );
                        $event = $this->normalizeEvidence($event, $chapter, $draft);
                        $candidate = StoryEventCandidate::fromArray($event);
                        $this->validateEvidence($candidate, $chapter, $draft);
                    }

                    $this->validateSubject($candidate, $chapter);

                    return $candidate;
                } catch (ValidationException $exception) {
                    throw $this->withCandidateIndex($exception, $index);
                }
            })->all();

        $candidates = $this->foreshadowingEventValidator->attachCoverageEvidence(
            $chapter,
            $draft,
            $candidates,
            $foreshadowingContract,
        );
        $violations = $this->foreshadowingEventValidator->violations($chapter, $draft, $candidates, $foreshadowingContract);

        if ($violations !== []) {
            throw ValidationException::withMessages(collect($violations)->mapWithKeys(fn (array $violation): array => [
                "events.{$violation['event_index']}.foreshadowing" => $violation['message'],
            ])->all());
        }

        return [
            [...$candidates, ...$this->outlineCompletion->eventCandidates($outlineCompletion, $chapter, $draft)],
            $outlineCompletion,
        ];
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function normalizeEvidence(array $event, Chapter $chapter, GenerationArtifact $draft): array
    {
        $event['evidence'] = collect($event['evidence'] ?? [])->map(function (mixed $evidence) use ($chapter, $draft): mixed {
            if (! is_array($evidence)) {
                return $evidence;
            }

            // The source artifact is authoritative server context, not a value the model should infer.
            $evidence['artifact_id'] = $draft->getKey();

            if (is_string($evidence['quote'] ?? null)) {
                $evidence['quote'] = $this->evidenceQuoteResolver->resolve((string) $draft->content, $evidence['quote']);
            }

            $evidence['scene_id'] = $this->resolveEvidenceSceneId($evidence, $chapter);

            return $evidence;
        })->all();

        return $event;
    }

    /** @param array<string, mixed> $evidence */
    private function resolveEvidenceSceneId(array $evidence, Chapter $chapter): mixed
    {
        if (! is_int($evidence['scene_id'] ?? null) || ! is_string($evidence['quote'] ?? null)) {
            return $evidence['scene_id'] ?? null;
        }

        $matchingSceneIds = $chapter->scenes
            ->filter(fn ($scene): bool => $scene->currentArtifact !== null
                && str_contains((string) $scene->currentArtifact->content, $evidence['quote']))
            ->values()
            ->modelKeys();

        // A verbatim quote that occurs in exactly one current Scene Draft is authoritative.
        // This safely repairs the common model error of returning a Scene sequence as scene_id.
        return count($matchingSceneIds) === 1
            ? $matchingSceneIds[0]
            : $evidence['scene_id'];
    }

    private function isEvidenceQuoteMismatch(ValidationException $exception): bool
    {
        $errors = $exception->errors();

        return array_keys($errors) === ['evidence']
            && collect($errors['evidence'])->contains(
                fn (string $message): bool => str_contains($message, 'Evidence quote 必须逐字来自当前 Chapter Draft'),
            );
    }

    /** @return array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int} */
    private function resolveRequestBudget(GenerationRun $run, string $baseKey): array
    {
        $priorRuns = GenerationRun::query()
            ->where('chapter_id', $run->chapter_id)
            ->where('stage', GenerationStage::EventExtraction)
            ->where('provider', $run->provider)
            ->where('model_policy', $run->model_policy)
            ->where('id', '<', $run->getKey())
            ->whereNotNull('error_code')
            ->orderBy('id')
            ->get(['idempotency_key', 'error_code', 'context_snapshot'])
            ->filter(fn (GenerationRun $prior): bool => $prior->idempotency_key === $baseKey
                || str_starts_with($prior->idempotency_key, $baseKey.':attempt:'))
            ->values();
        $budgets = (array) data_get($run->context_snapshot, 'generation_preferences.request_budgets', []);
        $selection = $this->requestBudget->resolveForAttempt($budgets, AiStage::Extractor, $priorRuns, 'event', 'Story Event Extraction');
        $budget = $selection['budget'];
        $snapshot = $run->context_snapshot ?? [];
        data_set($snapshot, 'generation_preferences.event_retry_ordinal', $selection['ordinal']);
        data_set($snapshot, 'generation_preferences.request_budget_tier', $selection['tier']);
        data_set($snapshot, 'generation_preferences.request_budget_trigger', $selection['trigger']);
        data_set($snapshot, 'generation_preferences.selected_request_budget', $budget);
        data_set($snapshot, 'generation_preferences.max_completion_tokens', $budget['max_completion_tokens']);
        $run->update(['context_snapshot' => $snapshot]);

        return $budget;
    }

    private function withCandidateIndex(ValidationException $exception, int $index): ValidationException
    {
        $messages = [];

        foreach ($exception->errors() as $field => $fieldMessages) {
            $messages["events.{$index}.{$field}"] = array_map(
                fn (string $message): string => '第 '.($index + 1)." 个事件字段 {$field}：{$message}",
                $fieldMessages,
            );
        }

        return ValidationException::withMessages($messages ?: [
            "events.{$index}" => '第 '.($index + 1).' 个事件校验失败。',
        ]);
    }

    private function validateEvidence(StoryEventCandidate $candidate, Chapter $chapter, GenerationArtifact $draft): void
    {
        foreach ($candidate->evidence as $evidence) {
            if ($evidence['artifact_id'] !== $draft->getKey()) {
                throw ValidationException::withMessages(['evidence' => 'Candidate Evidence 的来源产物与当前 Chapter Draft 不一致。']);
            }

            if (! str_contains((string) $draft->content, $evidence['quote'])) {
                throw ValidationException::withMessages(['evidence' => 'Candidate Evidence quote 必须逐字来自当前 Chapter Draft。']);
            }

            if ($evidence['scene_id'] !== null && ! $chapter->scenes->contains('id', $evidence['scene_id'])) {
                throw ValidationException::withMessages(['evidence' => 'Candidate Evidence 引用了其他 Chapter 的 Scene。']);
            }
        }
    }

    private function validateSubject(StoryEventCandidate $candidate, Chapter $chapter): void
    {
        if ($candidate->subjectId === null) {
            return;
        }

        $valid = match ($candidate->subjectType) {
            'character' => $chapter->novel->characters()->whereKey($candidate->subjectId)->exists()
                || ($candidate->eventType === EventType::CharacterIntroduced
                    && collect($chapter->latestPlan?->character_candidates ?? [])->contains(
                        fn (array $item): bool => ($item['candidate_key'] ?? null) === $candidate->subjectId,
                    )),
            'world_entity' => $chapter->novel->worldEntities()->whereKey($candidate->subjectId)->exists()
                || ($candidate->eventType === EventType::WorldEntityIntroduced
                    && collect($chapter->latestPlan?->world_entity_candidates ?? [])->contains(
                        fn (array $item): bool => ($item['candidate_key'] ?? null) === $candidate->subjectId,
                    )),
            'story_arc' => $chapter->novel->storyArcs()->whereKey($candidate->subjectId)->exists(),
            'foreshadowing' => $chapter->novel->foreshadowings()->whereKey($candidate->subjectId)->exists(),
            'chapter' => (string) $chapter->getKey() === $candidate->subjectId,
            default => true,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['subject_id' => 'Story Event Candidate 引用了当前 Novel 之外的实体。']);
        }
    }

    /** @return array<string, mixed>|null */
    private function latestPayloadCheckpoint(Chapter $chapter, string $inputHash): ?array
    {
        $artifact = GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('stage', GenerationStage::EventExtraction)
                ->where('input_hash', $inputHash))
            ->latest('id')->get()
            ->first(fn (GenerationArtifact $candidate): bool => data_get($candidate->data, 'checkpoint') === 'event_payload');
        $payload = data_get($artifact?->data, 'payload');

        return is_array($payload) ? $payload : null;
    }

    /** @param array<string, mixed> $payload */
    private function savePayloadCheckpoint(GenerationRun $run, array $payload, string $inputHash, GenerationArtifact $draft): void
    {
        $substageFingerprint = app(GenerationStageFingerprint::class)->make(
            GenerationStage::EventExtraction,
            $payload,
            upstreamChecksums: [$inputHash, $draft->checksum],
            contractVersion: 'event-payload-checkpoint-v1',
        );
        $data = [
            'checkpoint' => 'event_payload',
            'input_hash' => $inputHash,
            'substage_fingerprint' => $substageFingerprint,
            'source_artifact_id' => $draft->getKey(),
            'source_artifact_checksum' => $draft->checksum,
            'payload' => $payload,
        ];
        $run->artifacts()->create([
            'type' => ArtifactType::Context,
            'version' => ((int) $run->artifacts()->where('type', ArtifactType::Context)->max('version')) + 1,
            'content' => null,
            'data' => $data,
            'checksum' => $substageFingerprint,
        ]);
    }

    /** @return array{attempt: int, response: array<string, mixed>|null}|null */
    private function latestEvidenceRepairCheckpoint(Chapter $chapter, string $inputHash, int $eventIndex): ?array
    {
        $artifact = GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->where('stage', GenerationStage::EventExtraction)
                ->where('input_hash', $inputHash))
            ->latest('id')->get()
            ->first(fn (GenerationArtifact $candidate): bool => data_get($candidate->data, 'checkpoint') === 'event_evidence_repair'
                && (int) data_get($candidate->data, 'event_index', -1) === $eventIndex);

        if ($artifact === null) {
            return null;
        }

        $response = data_get($artifact->data, 'response');

        return [
            'attempt' => (int) data_get($artifact->data, 'repair_attempt'),
            'response' => is_array($response) ? $response : null,
        ];
    }

    /** @param array<string, mixed>|null $response */
    private function saveEvidenceRepairCheckpoint(GenerationRun $run, string $inputHash, GenerationArtifact $draft, int $eventIndex, int $attempt, ?array $response): void
    {
        $substageFingerprint = app(GenerationStageFingerprint::class)->make(
            GenerationStage::EventExtraction,
            ['event_index' => $eventIndex, 'repair_attempt' => $attempt, 'response' => $response],
            upstreamChecksums: [$inputHash, $draft->checksum],
            contractVersion: 'event-evidence-repair-v1',
        );
        $run->artifacts()->create([
            'type' => ArtifactType::Context,
            'version' => ((int) $run->artifacts()->where('type', ArtifactType::Context)->max('version')) + 1,
            'content' => null,
            'data' => [
                'checkpoint' => 'event_evidence_repair',
                'input_hash' => $inputHash,
                'substage_fingerprint' => $substageFingerprint,
                'source_artifact_id' => $draft->getKey(),
                'event_index' => $eventIndex,
                'repair_attempt' => $attempt,
                'response' => $response,
            ],
            'checksum' => $substageFingerprint,
        ]);
    }

    /** @param array<int, StoryEventCandidate> $candidates */
    private function complete(GenerationRun $run, Chapter $chapter, GenerationArtifact $draft, array $candidates, array $outlineCompletion, int $expectedStateVersion, string $foreshadowingContractChecksum): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $chapter, $draft, $candidates, $outlineCompletion, $expectedStateVersion, $foreshadowingContractChecksum): GenerationArtifact {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());

            if ($chapter->novel->canonicalStateVersion?->version !== $expectedStateVersion) {
                throw new AiProviderException('state_version_conflict', 'Event Extraction 期间 Canonical Story State 已变化。', false);
            }

            $events = array_map(fn (StoryEventCandidate $candidate): array => $candidate->toArray(), $candidates);
            $version = GenerationArtifact::query()
                ->where('type', ArtifactType::EventCandidate)
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->max('version');
            $data = [
                'status' => 'candidate',
                'source_artifact_id' => $draft->getKey(),
                'foreshadowing_contract_checksum' => $foreshadowingContractChecksum,
                'outline_completion' => $outlineCompletion,
                'events' => $events,
            ];
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::EventCandidate,
                'version' => ((int) $version) + 1,
                'content' => json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'data' => $data,
                'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    private function failRun(GenerationRun $run, Throwable $exception): void
    {
        $this->failurePolicy->record(
            $run,
            $exception,
            $exception instanceof ValidationException ? 'event_validation_failed' : 'event_extraction_failed',
        );
    }

    /** @param array<string, mixed> $value @param array<int, string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
