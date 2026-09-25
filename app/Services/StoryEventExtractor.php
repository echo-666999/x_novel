<?php

namespace App\Services;

use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\PromptVersionResolver;
use App\AI\StructuredOutput;
use App\Data\StoryEventCandidate;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
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
        private readonly AiSettingsResolver $settingsResolver,
        private readonly PromptVersionResolver $promptVersionResolver,
        private readonly GenerationRunLease $runLease,
        private readonly StoryEventEvidenceRepairer $evidenceRepairer,
        private readonly StoryEventEvidenceQuoteResolver $evidenceQuoteResolver,
        private readonly ContextBuilder $contextBuilder,
        private readonly ForeshadowingEventValidator $foreshadowingEventValidator,
        private readonly GenerationFailurePolicy $failurePolicy,
    ) {}

    public function extract(int $chapterId, bool $regenerate = false): ?GenerationArtifact
    {
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

        $draft = $this->latestChapterDraft($chapter);
        $context = $this->context($chapter, $draft);
        $context['generation_preferences']['event_token_budget'] = [
            'initial_max_completion_tokens' => (int) config('generation.event_extraction_max_output_tokens', 4_000),
            'retry_max_completion_tokens' => (int) config('generation.event_extraction_retry_max_output_tokens', 8_000),
            'final_retry_max_completion_tokens' => (int) config('generation.event_extraction_final_retry_max_output_tokens', 12_000),
        ];
        $settings = $this->settingsResolver->resolve(AiStage::Extractor, $chapter->novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Extractor);
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'prompt_version' => $promptVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $baseKey = "events:{$draft->checksum}:{$context['state_version']}:{$promptVersion}";
        [$run, $reused] = $this->startRun($chapter, $baseKey, $inputHash, $context, $settings->provider, $settings->model, $promptVersion, $regenerate);

        if ($reused) {
            return $run->artifacts()->where('type', ArtifactType::EventCandidate)->latest('version')->first();
        }

        try {
            $maxTokens = $this->resolveRequestBudget($run, $baseKey);
            $metadata = [
                'generation_run_id' => $run->getKey(),
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'stage' => AiStage::Extractor->value,
            ];
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                reasoningEffort: $settings->reasoningEffort,
                systemPrompt: '你是 XNovel 故事事件提取器。只识别会改变后续故事状态的事件，并返回符合 Schema 的 JSON。event_type 与 subject_type 必须严格遵守 event_subject_type_rules；subject_id 必须引用 current_state 中已存在的实体，或引用 Chapter Plan 冻结的 Candidate Key。正文确实引入批准人物候选时必须输出 character_introduced，subject_type=character，subject_id=chapter_plan.character_candidates[].candidate_key，payload 包含 candidate_key；正文确实引入批准世界实体候选时必须输出 world_entity_introduced，subject_type=world_entity，subject_id=chapter_plan.world_entity_candidates[].candidate_key，payload 包含 candidate_key；不得为未批准候选生成 Introduced Event。正文确实完成声明 Beat 时输出 story_arc_beat_completed，subject_type=story_arc，subject_id=arc_id，payload 包含 beat_key。没有有效主体时必须省略该事件，不能借用角色 ID 充当其他类型 ID。current_state.world.entities 中的对象统一使用 subject_type=world_entity，其内部 type（例如 concept、rule、location、faction）不能作为 subject_type。foreshadowing_contract 是本章冻结的唯一伏笔动作契约；foreshadowing_* 候选只能引用 actions 中的 foreshadowing_id，事件类型必须与 plan_action.action 一致，而且对应 Scene 的最终 foreshadowing_coverage 必须为 fulfilled。事件 evidence 必须覆盖逐字证据并使用目标 Scene；evidence.scene_id 只能填 current_scene_references[].scene_id 中的数据库 ID，不得把 sequence 当作 scene_id。未列入契约、Coverage 为 missing/contradicted、动作不匹配或只有主题相似的内容不能生成事件。其他自然语言内容必须使用简体中文。每条 evidence quote 必须逐字复制自给定章节草稿，不得改写、概括或补字。含义不确定时必须降低 confidence。不得修改正式故事数据。',
                prompt: '请从以下章节草稿和权威上下文中提取故事事件候选：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: $maxTokens,
                responseSchema: $this->responseSchema(),
                promptVersion: $promptVersion,
                metadata: $metadata,
            ));

            $candidates = $this->validateCandidates(
                StructuredOutput::require($response, 'event', 'Story Event Candidates'),
                $chapter,
                $draft,
                $settings->model,
                $metadata,
                $context['foreshadowing_contract'],
                $settings->reasoningEffort,
            );

            return $this->complete(
                $run,
                $chapter,
                $draft,
                $candidates,
                $context['state_version'],
                $context['foreshadowing_contract_checksum'],
            );
        } catch (Throwable $exception) {
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
            'required' => ['events'],
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => StoryEventCandidate::schema(),
                ],
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
    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $provider, string $model, string $promptVersion, bool $regenerate): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $provider, $model, $promptVersion, $regenerate): array {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = $chapter->generationRuns()->where('stage', GenerationStage::EventExtraction);
            $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();

            if ($this->runLease->isFresh($active)) {
                return [$active, true];
            }

            if ($active !== null) {
                $active->update([
                    'status' => RunStatus::Failed,
                    'error_code' => 'worker_interrupted',
                    'error_message' => 'Event Extraction Run 超时未完成，已由后续投递恢复。',
                    'error_retryable' => false,
                    'error_metadata' => ['category' => 'worker_lost'],
                    'finished_at' => now(),
                ]);
            }

            if (! $regenerate && ($succeeded = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$succeeded, true];
            }

            $attempt = ((int) $runs->clone()->max('attempt')) + 1;

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

    /** @return array<int, StoryEventCandidate> */
    private function validateCandidates(array $payload, Chapter $chapter, GenerationArtifact $draft, string $model, array $metadata, array $foreshadowingContract, ?string $reasoningEffort): array
    {
        if (array_keys($payload) !== ['events'] || ! is_array($payload['events'])) {
            throw ValidationException::withMessages(['events' => 'Story Event Extractor 必须只返回 events 数组。']);
        }

        $candidates = collect($payload['events'])->map(function (mixed $event, int $index) use ($chapter, $draft, $model, $metadata, $reasoningEffort): StoryEventCandidate {
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

                    $event['evidence'] = $this->evidenceRepairer->repair(
                        event: $event,
                        content: (string) $draft->content,
                        model: $model,
                        metadata: $metadata,
                        eventIndex: $index,
                        reasoningEffort: $reasoningEffort,
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

        $violations = $this->foreshadowingEventValidator->violations($chapter, $draft, $candidates, $foreshadowingContract);

        if ($violations !== []) {
            throw ValidationException::withMessages(collect($violations)->mapWithKeys(fn (array $violation): array => [
                "events.{$violation['event_index']}.foreshadowing" => $violation['message'],
            ])->all());
        }

        return $candidates;
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

    private function resolveRequestBudget(GenerationRun $run, string $baseKey): int
    {
        $priorTruncatedRuns = GenerationRun::query()
            ->where('chapter_id', $run->chapter_id)
            ->where('stage', GenerationStage::EventExtraction)
            ->where('provider', $run->provider)
            ->where('model_policy', $run->model_policy)
            ->where('id', '<', $run->getKey())
            ->where('error_code', 'event_output_truncated')
            ->get(['idempotency_key', 'context_snapshot'])
            ->filter(fn (GenerationRun $prior): bool => $prior->idempotency_key === $baseKey
                || str_starts_with($prior->idempotency_key, $baseKey.':attempt:'))
            ->values();
        $retryOrdinal = $priorTruncatedRuns->count() + 1;
        $budget = (array) data_get($run->context_snapshot, 'generation_preferences.event_token_budget', []);
        $maxTokens = match ($retryOrdinal) {
            1 => (int) ($budget['initial_max_completion_tokens'] ?? 4_000),
            2 => (int) ($budget['retry_max_completion_tokens'] ?? 8_000),
            default => (int) ($budget['final_retry_max_completion_tokens'] ?? 12_000),
        };
        $priorMaximum = $priorTruncatedRuns
            ->map(fn (GenerationRun $prior): int => (int) data_get($prior->context_snapshot, 'generation_preferences.max_completion_tokens', 0))
            ->max();
        $snapshot = $run->context_snapshot ?? [];
        data_set($snapshot, 'generation_preferences.event_retry_ordinal', $retryOrdinal);
        data_set($snapshot, 'generation_preferences.max_completion_tokens', $maxTokens);
        $run->update(['context_snapshot' => $snapshot]);

        if (is_int($priorMaximum) && $priorMaximum >= $maxTokens) {
            throw new AiProviderException(
                'event_output_budget_exhausted',
                "Story Event Extraction 已在冻结的最高输出预算 {$maxTokens} Token 下被截断；请提高预算或调整 Extractor 模型后再重试。",
                false,
            );
        }

        return $maxTokens;
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

    /** @param array<int, StoryEventCandidate> $candidates */
    private function complete(GenerationRun $run, Chapter $chapter, GenerationArtifact $draft, array $candidates, int $expectedStateVersion, string $foreshadowingContractChecksum): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $chapter, $draft, $candidates, $expectedStateVersion, $foreshadowingContractChecksum): GenerationArtifact {
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
}
