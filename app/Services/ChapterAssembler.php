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
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChapterAssembler
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly PromptVersionResolver $promptVersionResolver,
        private readonly ContextBuilder $contextBuilder,
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly PreviousChapterEnding $previousChapterEnding,
        private readonly GenerationRunLease $runLease,
        private readonly PlanCoverageEvidenceRepairer $coverageEvidenceRepairer,
        private readonly ForeshadowingCoverageEvidenceRepairer $foreshadowingCoverageEvidenceRepairer,
        private readonly GenerationFailurePolicy $failurePolicy,
    ) {}

    public function assemble(int $chapterId, bool $regenerate = false): ?GenerationArtifact
    {
        $chapter = Chapter::query()->with([
            'novel.canonicalStateVersion',
            'latestPlan',
            'scenes.currentArtifact.generationRun',
        ])->findOrFail($chapterId);

        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Chapter Assembly。', false);
        }

        $artifacts = $this->orderedSceneArtifacts($chapter);
        $settings = $this->settingsResolver->resolve(AiStage::Assembler, $chapter->novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Assembler);
        $context = $this->context($chapter, $artifacts);
        $context['prompt_version'] = $promptVersion;
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'prompt_version' => $promptVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $checksumHash = hash('sha256', implode('|', $context['ordered_scene_checksums']));
        $baseKey = "assemble:{$chapter->getKey()}:{$checksumHash}:{$promptVersion}";
        [$run, $reused] = $this->startRun(
            $chapter,
            $baseKey,
            $inputHash,
            $context,
            $settings->provider,
            $settings->model,
            $promptVersion,
            $regenerate,
        );

        if ($reused) {
            return $run->artifacts()->where('type', ArtifactType::ChapterDraft)->latest('version')->first();
        }

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                reasoningEffort: $settings->reasoningEffort,
                systemPrompt: '你是 XNovel 章节组装器。将给定场景组装成一章完整、流畅的简体中文正文。foreshadowing_contract 是本章冻结的唯一伏笔动作契约；必须保留各动作在指定 Scene 中已经实现的内容，不得把未列入 actions 的伏笔改写成本章主动处理目标，也不得把 promised_payoff 当作允许直接揭晓的正文信息；始终遵守 chapter_plan.must_not_reveal。l4 是唯一的 Style Contract；保持各 Scene 已有的 POV、时态和叙述声音，不得重新选择文风来源或让辅助文风覆盖主文风。开头必须与 previous_chapter_ending 连续，并保留 chapter_plan.scene_plans[0].transition_from_previous 对时间、地点和行动过渡的交代。必须保留各场景的目标、冲突、转折、结果及 outcome_allowed / outcome_forbidden 行为边界；按 continuity_requirements 合并跨 Scene 持续状态，只保留首次建立、真实变化和章末回扣，persist 场景只保留推动本场动作所需的最短增量表达。scene_coverage 必须按 Scene 顺序逐项返回 goal、conflict、turn、outcome 的 fulfilled、missing 或 contradicted 状态；每个 Scene 的 foreshadowing_coverage 必须按契约顺序完整返回分配给该 Scene 的全部伏笔动作。只有最终正文足以证明 acceptance_criteria 时才能标记 fulfilled；仅有主题相近措辞、但没有动作结果时必须标记 missing；正文反转既定动作时标记 contradicted。所有 fulfilled 和 contradicted 的 evidence 必须逐字引用最终 content，missing 的 evidence 必须为 null。Assembler 不能创作 Scene Draft 中不存在的重大剧情结果来补齐 coverage，也不得删除 Scene Draft 中唯一能够证明伏笔动作已完成的证据；introduced_major_facts 必须返回 []。成稿必须达到 chapter_minimum_words，并尽量接近 chapter_target_words，chapter_maximum_words 是不可超过的硬上限；字数统计排除空白和换行。可以补足必要的场景衔接，但不得用无意义重复凑字，不得把正文压缩成摘要，也不得新增重大事实、能力、世界规则或角色知识。保持场景顺序和结果，返回符合 Schema 的 JSON。'.NarrativeProsePolicy::writing(),
                prompt: '请组装以下场景并返回结构化章节结果：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.3,
                maxTokens: (int) config('generation.assembly_max_output_tokens', 12_000),
                responseSchema: ChapterAssemblyPayload::schema(),
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Assembler->value,
                ],
            ));
            $payload = $this->validatePayloadWithCoverageRepair(
                payload: StructuredOutput::require($response, 'assembly', 'Chapter Assembly'),
                chapter: $chapter,
                artifacts: $artifacts,
                context: $context,
                provider: $settings->provider,
                model: $settings->model,
                reasoningEffort: $settings->reasoningEffort,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Assembler->value,
                ],
            );
            $payload = $this->repairLengthIfNeeded(
                payload: $payload,
                chapter: $chapter,
                artifacts: $artifacts,
                context: $context,
                provider: $settings->provider,
                model: $settings->model,
                promptVersion: $promptVersion,
                reasoningEffort: $settings->reasoningEffort,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Assembler->value,
                ],
            );
            $this->validateLength($payload['content'], $context['writing_constraints']);

            return $this->complete(
                $run,
                $chapter,
                $payload,
                $context['state_version'],
                $context['ordered_scene_checksums'],
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

    /** @return Collection<int, GenerationArtifact> */
    private function orderedSceneArtifacts(Chapter $chapter): Collection
    {
        if ($chapter->latestPlan === null || $chapter->scenes->isEmpty()) {
            throw new AiProviderException('assembly_input_incomplete', 'Chapter Assembly 缺少 Chapter Plan 或 Scenes。', false);
        }

        foreach ($chapter->scenes as $scene) {
            if (! in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true)
                || $scene->currentArtifact === null
                || ! in_array($scene->currentArtifact->type, [ArtifactType::SceneDraft, ArtifactType::RewriteDraft], true)) {
                throw new AiProviderException(
                    'assembly_scene_incomplete',
                    "Scene {$scene->sequence} 尚未成功，不能组装 Chapter。",
                    false,
                );
            }
        }

        return $chapter->scenes->pluck('currentArtifact')->values();
    }

    /** @param Collection<int, GenerationArtifact> $artifacts
     * @return array<string, mixed>
     */
    private function context(Chapter $chapter, Collection $artifacts): array
    {
        $stateVersion = $chapter->novel->canonicalStateVersion?->version;

        if ($stateVersion === null) {
            throw new AiProviderException('assembly_context_incomplete', 'Chapter Assembly 缺少 Story State。', false);
        }

        $targetWords = (int) $chapter->latestPlan->target_words;
        $styleContract = $this->contextBuilder->styleContractForChapter($chapter);
        $foreshadowingContract = $this->contextBuilder->foreshadowingContractForChapter($chapter);

        return [
            'chapter_id' => $chapter->getKey(),
            'state_version' => $stateVersion,
            'bible_version' => $styleContract['bible_version'],
            'style_contract_checksum' => $styleContract['checksum'],
            'l4' => $styleContract,
            'foreshadowing_contract_checksum' => $foreshadowingContract['checksum'],
            'foreshadowing_contract' => $foreshadowingContract,
            'chapter_plan' => $chapter->latestPlan->only([
                'id', 'version', 'chapter_function', 'arc_contribution', 'reader_promise', 'tone', 'hook_type',
                'must_reveal', 'may_hint', 'must_not_reveal', 'forbidden_conflicts', 'arc_contributions',
                'world_entity_candidates', 'foreshadowing_actions', 'scene_plans',
            ]),
            'previous_chapter_ending' => $this->previousChapterEnding->for($chapter),
            'writing_constraints' => [
                'chapter_target_words' => $targetWords,
                'chapter_minimum_words' => $this->lengthPolicy->chapterMinimum($targetWords),
                'chapter_maximum_words' => $this->lengthPolicy->chapterMaximum($targetWords),
                'source_scene_words' => $artifacts->sum(fn (GenerationArtifact $artifact): int => $this->lengthPolicy->count($artifact->content)),
            ],
            'ordered_scene_checksums' => $artifacts->pluck('checksum')->all(),
            'scenes' => $chapter->scenes->values()->map(fn ($scene, int $index): array => [
                'scene_id' => $scene->getKey(),
                'sequence' => $scene->sequence,
                'checksum' => $artifacts[$index]->checksum,
                'content' => $artifacts[$index]->content,
            ])->all(),
        ];
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $provider, string $model, string $promptVersion, bool $regenerate): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $provider, $model, $promptVersion, $regenerate): array {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = $chapter->generationRuns()->where('stage', GenerationStage::ChapterAssembly);
            $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();

            if ($this->runLease->isFresh($active)) {
                return [$active, true];
            }

            if ($active !== null) {
                $active->update([
                    'status' => RunStatus::Failed,
                    'error_code' => 'worker_interrupted',
                    'error_message' => 'Assembly Run 超时未完成，已由后续投递恢复。',
                    'error_retryable' => false,
                    'error_metadata' => ['category' => 'worker_lost'],
                    'finished_at' => now(),
                ]);
            }

            if (! $regenerate && ($succeeded = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$succeeded, true];
            }

            $attempt = ((int) $runs->clone()->max('attempt')) + 1;
            $key = $attempt === 1 ? $baseKey : $baseKey.':attempt:'.$attempt;

            if ($chapter->status === ChapterStatus::Blocked) {
                $chapter->update(['status' => ChapterStatus::Generating]);
            }

            return [GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scene_id' => null,
                'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::ChapterAssembly,
                'status' => RunStatus::Running,
                'attempt' => $attempt,
                'idempotency_key' => $key,
                'input_hash' => $inputHash,
                'state_version' => $context['state_version'],
                'bible_version' => $context['bible_version'],
                'prompt_version' => $promptVersion,
                'provider' => $provider,
                'model_policy' => $model,
                'context_snapshot' => $context,
                'started_at' => now(),
            ]), false];
        });
    }

    /** @param array<int, string> $expectedChecksums */
    private function complete(GenerationRun $run, Chapter $chapter, array $payload, int $expectedStateVersion, array $expectedChecksums): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $chapter, $payload, $expectedStateVersion, $expectedChecksums): GenerationArtifact {
            $chapter = Chapter::query()->lockForUpdate()->with(['novel', 'scenes.currentArtifact'])->findOrFail($chapter->getKey());
            $currentStateVersion = $chapter->novel->canonicalStateVersion()->value('version');
            $currentChecksums = $chapter->scenes->pluck('currentArtifact.checksum')->all();

            if ($currentStateVersion !== $expectedStateVersion) {
                throw new AiProviderException('state_version_conflict', 'Assembly 期间 Canonical Story State 已变化。', false);
            }

            if ($currentChecksums !== $expectedChecksums) {
                throw new AiProviderException('scene_artifact_conflict', 'Assembly 期间 Scene Artifact 已变化。', false);
            }

            $version = GenerationArtifact::query()
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->where('type', ArtifactType::ChapterDraft)
                ->max('version');
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::ChapterDraft,
                'version' => ((int) $version) + 1,
                'content' => $payload['content'],
                'data' => [
                    ...collect($payload)->except('content')->all(),
                    'ordered_scene_checksums' => $expectedChecksums,
                    'word_count' => $this->lengthPolicy->count($payload['content']),
                    'target_words' => (int) data_get($run->context_snapshot, 'writing_constraints.chapter_target_words'),
                    'minimum_words' => (int) data_get($run->context_snapshot, 'writing_constraints.chapter_minimum_words'),
                    'maximum_words' => (int) data_get($run->context_snapshot, 'writing_constraints.chapter_maximum_words'),
                ],
                'checksum' => hash('sha256', $payload['content']),
            ]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    /** @param array<string, mixed> $context
     * @param  array<string, mixed>  $metadata
     */
    private function repairLengthIfNeeded(array $payload, Chapter $chapter, Collection $artifacts, array $context, string $provider, string $model, string $promptVersion, ?string $reasoningEffort, array $metadata): array
    {
        $constraints = $context['writing_constraints'];

        for ($attempt = 1; $attempt <= (int) config('generation.max_length_repair_attempts', 1); $attempt++) {
            $actual = $this->lengthPolicy->count($payload['content']);
            $minimum = (int) $constraints['chapter_minimum_words'];
            $maximum = (int) $constraints['chapter_maximum_words'];
            $tooShort = $actual < $minimum;
            $tooLong = $actual > $maximum;

            if (! $tooShort && ! $tooLong) {
                break;
            }

            $response = $this->provider->generate(new AiRequest(
                model: $model,
                provider: $provider,
                reasoningEffort: $reasoningEffort,
                systemPrompt: ($tooLong
                    ? '你是 XNovel 章节压缩器。l4 是唯一的 Style Contract；压缩后必须保持其中的 POV、时态、主文风和辅助文风层级。将超限草稿压缩为完整章节，保留计划中的场景目标、冲突、转折、结果、行为边界、必要连续性和正式事实。删除重复解释、重复感受、重复争论与不推动情节的细节，但不得删除 Scene Draft 中唯一能够证明伏笔动作已完成的证据。重新按 Schema 输出覆盖最终 content 的 scene_coverage，并按 foreshadowing_contract 完整返回每个 Scene 的 foreshadowing_coverage；不得改变伏笔 ID、动作或目标 Scene，只有最终正文足以证明 acceptance_criteria 时才能标记 fulfilled，仅有主题相近措辞必须标记 missing。所有 fulfilled 和 contradicted 的 evidence 必须逐字引用最终正文，missing 的 evidence 必须为 null，introduced_major_facts 必须返回 []。最终正文应接近 chapter_target_words，且不得超过 chapter_maximum_words；字数统计排除空白和换行。不得截断句子，不得输出摘要或解释，不得新增重大事实。'
                    : '你是 XNovel 章节扩写器。l4 是唯一的 Style Contract；扩写后必须保持其中的 POV、时态、主文风和辅助文风层级。将过短草稿扩写为完整章节，保留计划、行为边界和既定事实，通过既定场景内的动作、对话、环境、感官、心理和自然过渡补足。重新按 Schema 输出覆盖最终 content 的 scene_coverage，并按 foreshadowing_contract 完整返回每个 Scene 的 foreshadowing_coverage；不得改变伏笔 ID、动作或目标 Scene，只有最终正文足以证明 acceptance_criteria 时才能标记 fulfilled，仅有主题相近措辞必须标记 missing。所有 fulfilled 和 contradicted 的 evidence 必须逐字引用最终正文，missing 的 evidence 必须为 null，introduced_major_facts 必须返回 []。最终正文至少达到 chapter_minimum_words，并尽量接近 chapter_target_words，且不得超过 chapter_maximum_words；字数统计排除空白和换行。不得无意义重复，不得新增重大事实。').NarrativeProsePolicy::writing(),
                prompt: ($tooLong ? '请压缩以下超限章节：' : '请扩写以下过短章节：').json_encode([
                    'chapter_plan' => $context['chapter_plan'],
                    'writing_constraints' => $constraints,
                    'l4' => $context['l4'],
                    'foreshadowing_contract' => $context['foreshadowing_contract'],
                    'current_words' => $actual,
                    'required_reduction_words' => $tooLong ? $actual - $maximum : 0,
                    'repair_attempt' => $attempt,
                    'draft' => $payload,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: (int) config('generation.assembly_max_output_tokens', 12_000),
                responseSchema: ChapterAssemblyPayload::schema(),
                promptVersion: $promptVersion,
                metadata: [...$metadata, 'length_repair_attempt' => $attempt, 'length_repair_mode' => $tooLong ? 'compress' : 'expand'],
            ));
            $payload = $this->validatePayloadWithCoverageRepair(
                payload: StructuredOutput::require($response, 'assembly', 'Chapter Assembly'),
                chapter: $chapter,
                artifacts: $artifacts,
                context: $context,
                provider: $provider,
                model: $model,
                reasoningEffort: $reasoningEffort,
                metadata: $metadata,
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, GenerationArtifact>  $artifacts
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function validatePayloadWithCoverageRepair(array $payload, Chapter $chapter, Collection $artifacts, array $context, string $provider, string $model, ?string $reasoningEffort, array $metadata): array
    {
        $repairedIndexes = [];

        while (true) {
            try {
                return ChapterAssemblyPayload::validate(
                    $payload,
                    $chapter,
                    $artifacts,
                    $context['foreshadowing_contract'],
                );
            } catch (ValidationException $exception) {
                $fields = array_keys($exception->errors());
                $planEvidenceErrors = $fields !== [] && collect($fields)->every(
                    fn (string $field): bool => preg_match('/^scene_coverage\.\d+\.(goal|conflict|turn|outcome)\.evidence$/', $field) === 1,
                );
                $foreshadowingEvidenceErrors = $fields !== [] && collect($fields)->every(
                    fn (string $field): bool => preg_match('/^scene_coverage\.\d+\.foreshadowing_coverage\.\d+\.evidence$/', $field) === 1,
                );

                if (! $planEvidenceErrors && ! $foreshadowingEvidenceErrors) {
                    throw $exception;
                }
            }

            $index = (int) explode('.', $fields[0])[1];
            $repairKey = ($foreshadowingEvidenceErrors ? 'foreshadowing:' : 'plan:').$index;

            if (isset($repairedIndexes[$repairKey])) {
                throw $exception;
            }

            $row = data_get($payload, "scene_coverage.{$index}");

            if (! is_array($row)) {
                throw $exception;
            }

            if ($foreshadowingEvidenceErrors) {
                $expectations = ForeshadowingCoverage::expectationsForScene(
                    $context['foreshadowing_contract'],
                    (int) data_get($context, "scenes.{$index}.sequence"),
                );
                $payload['scene_coverage'][$index]['foreshadowing_coverage'] = $this->foreshadowingCoverageEvidenceRepairer->repair(
                    coverage: is_array($row['foreshadowing_coverage'] ?? null) ? $row['foreshadowing_coverage'] : [],
                    content: is_string($payload['content'] ?? null) ? $payload['content'] : '',
                    expectations: $expectations,
                    provider: $provider,
                    model: $model,
                    metadata: $metadata,
                    path: "scene_coverage.{$index}.foreshadowing_coverage",
                    reasoningEffort: $reasoningEffort,
                );
            } else {
                $coverage = $this->coverageEvidenceRepairer->repair(
                    coverage: collect($row)->except(['scene_id', 'foreshadowing_coverage'])->all(),
                    content: is_string($payload['content'] ?? null) ? $payload['content'] : '',
                    provider: $provider,
                    model: $model,
                    metadata: $metadata,
                    task: data_get($context, "chapter_plan.scene_plans.{$index}"),
                    path: "scene_coverage.{$index}",
                    reasoningEffort: $reasoningEffort,
                );
                $payload['scene_coverage'][$index] = [
                    'scene_id' => $row['scene_id'] ?? null,
                    ...$coverage,
                    'foreshadowing_coverage' => $row['foreshadowing_coverage'] ?? [],
                ];
            }
            $repairedIndexes[$repairKey] = true;
        }
    }

    /** @param array<string, mixed> $constraints */
    private function validateLength(string $content, array $constraints): void
    {
        $actual = $this->lengthPolicy->count($content);
        $minimum = (int) $constraints['chapter_minimum_words'];
        $maximum = (int) $constraints['chapter_maximum_words'];

        if ($actual < $minimum || $actual > $maximum) {
            throw new AiProviderException(
                'assembly_length_out_of_range',
                "章节组装稿 {$actual} 字，必须控制在 {$minimum}～{$maximum} 字。",
                false,
            );
        }
    }

    private function failRun(GenerationRun $run, Throwable $exception): void
    {
        $this->failurePolicy->record($run, $exception, 'chapter_assembly_failed');
    }
}
