<?php

namespace App\Services;

use App\Actions\Novels\CreateNormalizedNovelOutlineVersionAction;
use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\NarrativeProsePolicy;
use App\AI\StructuredOutput;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Jobs\AssembleNovelOutlineSkeletonJob;
use App\Jobs\FinalizeNovelOutlineJob;
use App\Jobs\GenerateNovelArcBeatsJob;
use App\Jobs\GenerateNovelBeatDetailJob;
use App\Jobs\GenerateNovelFoundationJob;
use App\Jobs\GenerateNovelOutlineSkeletonJob;
use App\Jobs\GenerateNovelOutlineStructureJob;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 以可恢复的小阶段生成小说 Foundation 与关系化 Outline 候选。
 */
class NovelOutlinePipeline
{
    public const BATCH_SCOPE = 'novel_outline_batch';

    public const FOUNDATION_SCOPE = 'novel_outline_foundation';

    public const STRUCTURE_SCOPE = 'novel_outline_structure';

    public const ARC_BEATS_SCOPE = 'novel_outline_arc_beats';

    public const SKELETON_ASSEMBLY_SCOPE = 'novel_outline_skeleton_assembly';

    public const SKELETON_SCOPE = 'novel_outline_skeleton';

    public const BEAT_DETAIL_SCOPE = 'novel_outline_beat_detail';

    public const FINALIZE_SCOPE = 'novel_outline_finalize';

    public const LEGACY_BATCH_PROMPT_VERSION = 'novel-outline-pipeline-v2';

    public const BATCH_PROMPT_VERSION = 'novel-outline-pipeline-v3';

    public const FOUNDATION_PROMPT_VERSION = 'novel-outline-foundation-v2';

    public const SKELETON_PROMPT_VERSION = 'novel-outline-skeleton-v1';

    public const BEAT_DETAIL_PROMPT_VERSION = 'novel-outline-beat-detail-v1';

    public const FINALIZE_PROMPT_VERSION = 'novel-outline-finalize-v1';

    public const SKELETON_ASSEMBLY_VERSION = 'novel-outline-skeleton-assembly-v1';

    public const FOUNDATION_MAX_TOKENS = 8_000;

    public const SKELETON_MAX_TOKENS = 12_000;

    public const BEAT_DETAIL_MAX_TOKENS = 5_000;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly NormalizedNovelOutlineValidator $outlineValidator,
        private readonly CreateNormalizedNovelOutlineVersionAction $createOutlineVersion,
        private readonly GenerationFailurePolicy $failurePolicy,
        private readonly GenerationRunLease $runLease,
        private readonly TargetPlatformResolver $targetPlatformResolver,
        private readonly NovelOutlineStageContract $stageContract,
    ) {}

    /**
     * 在 Web 请求内先持久化 queued 批次，使队列启动前也能查询真实进度。
     */
    public function prepareBatch(Novel $novel, int $volumeCount): GenerationRun
    {
        if ($volumeCount < 1 || $volumeCount > 12) {
            throw ValidationException::withMessages(['volume_count' => '预计分卷数必须在 1 至 12 之间。']);
        }

        return DB::transaction(function () use ($novel, $volumeCount): GenerationRun {
            $locked = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $batches = GenerationRun::query()
                ->where('novel_id', $locked->getKey())
                ->whereNull('chapter_id')
                ->where('scope_type', self::BATCH_SCOPE)
                ->where('scope_id', $locked->getKey())
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running, RunStatus::Succeeded])
                ->latest('id')
                ->get();
            $sameBatch = $batches->first(fn (GenerationRun $run): bool => (int) data_get($run->context_snapshot, 'requested_volume_count') === $volumeCount);
            if ($sameBatch !== null) {
                // 重复投递与后台路由变化都必须回到已冻结的原批次，不能创建另一套半完成来源链。
                return $sameBatch;
            }
            if ($batches->contains(fn (GenerationRun $run): bool => in_array($run->status, [RunStatus::Queued, RunStatus::Running], true))) {
                throw new AiProviderException('outline_batch_conflict', '当前小说已有不同参数的 Outline 规划批次正在执行。', false);
            }
            $this->assertNovelPlanningAvailable($locked);

            $settings = $this->settingsResolver->resolve(AiStage::Planner, $locked);
            $targetPlatform = $this->targetPlatformResolver->forNovel($locked);
            $capacity = $this->configuredPlannerCapacity($settings->provider, $settings->model);
            $context = [
                'novel' => $locked->only(['id', 'title', 'genre', 'premise', 'target_words']),
                'requested_volume_count' => $volumeCount,
                'target_platform' => $targetPlatform,
                'generation_preferences' => [
                    'chapter_target_words' => (int) data_get($locked->settings, 'generation.chapter_target_words', 3_000),
                    'provider' => $settings->provider,
                    'model' => $settings->model,
                    'reasoning_effort' => $settings->reasoningEffort,
                    'model_capacity' => $capacity,
                    'prompt_versions' => $this->promptVersions(),
                ],
            ];
            $inputHash = $this->hash([
                'context' => $context,
                'batch_prompt_version' => self::BATCH_PROMPT_VERSION,
            ]);

            $existing = GenerationRun::query()
                ->where('novel_id', $locked->getKey())
                ->whereNull('chapter_id')
                ->where('scope_type', self::BATCH_SCOPE)
                ->where('scope_id', $locked->getKey())
                ->where('input_hash', $inputHash)
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running, RunStatus::Succeeded])
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $attempt = ((int) GenerationRun::query()
                ->where('novel_id', $locked->getKey())
                ->where('scope_type', self::BATCH_SCOPE)
                ->where('scope_id', $locked->getKey())
                ->max('attempt')) + 1;

            return GenerationRun::query()->create([
                'novel_id' => $locked->getKey(),
                'scope_type' => self::BATCH_SCOPE,
                'scope_id' => $locked->getKey(),
                'stage' => GenerationStage::ChapterPlanning,
                'status' => RunStatus::Queued,
                'attempt' => $attempt,
                'idempotency_key' => "novel-outline-batch:{$locked->getKey()}:{$inputHash}:{$attempt}",
                'input_hash' => $inputHash,
                'prompt_version' => self::BATCH_PROMPT_VERSION,
                'provider' => $settings->provider,
                'model_policy' => $settings->model,
                'context_snapshot' => $context,
            ]);
        });
    }

    /**
     * 激活页面已经准备好的批次；独立方法保证旧 Queue Payload 无需增加 batchRunId。
     */
    public function activateBatch(GenerationRun $batch): GenerationRun
    {
        return DB::transaction(function () use ($batch): GenerationRun {
            $locked = GenerationRun::query()->lockForUpdate()->findOrFail($batch->getKey());
            $this->assertBatch($locked);
            if ($locked->status === RunStatus::Succeeded) {
                return $locked;
            }
            if ($locked->status === RunStatus::Running) {
                return $locked;
            }
            if ($locked->status !== RunStatus::Queued) {
                throw ValidationException::withMessages(['run' => '只有 queued Outline 主批次可以由 Worker 激活。']);
            }

            $novel = Novel::query()->lockForUpdate()->findOrFail($locked->novel_id);
            $this->assertNovelPlanningAvailable($novel);
            $locked->update([
                'status' => RunStatus::Running,
                'started_at' => $locked->started_at ?? now(),
                'finished_at' => null,
                'error_code' => null,
                'error_message' => null,
                'error_retryable' => null,
                'error_metadata' => null,
            ]);

            return $locked->refresh();
        }, 3);
    }

    /**
     * 保留同步调用与旧入口兼容：准备批次后立即激活，但不自动恢复 failed 批次。
     */
    public function startOrResume(Novel $novel, int $volumeCount): GenerationRun
    {
        return $this->activateBatch($this->prepareBatch($novel, $volumeCount));
    }

    /**
     * 只派发当前批次最早缺失的阶段，保证同一小说的 Outline 规划严格串行。
     */
    public function dispatchNext(GenerationRun $batch): void
    {
        $batch->refresh();
        $this->assertBatch($batch);
        $novelStatus = $batch->novel()->value('status');
        if ($batch->status !== RunStatus::Running || $novelStatus === NovelStatus::Paused || $novelStatus === NovelStatus::Paused->value) {
            return;
        }

        $batch->touch();
        if ($this->artifact($batch, ArtifactType::OutlineFoundation) === null) {
            GenerateNovelFoundationJob::dispatch($batch->getKey());

            return;
        }

        if ($this->isLegacyBatch($batch)) {
            $this->dispatchLegacyNext($batch);

            return;
        }
        $this->assertCurrentBatch($batch);

        $structure = $this->currentStructureArtifact($batch);
        if ($structure === null) {
            GenerateNovelOutlineStructureJob::dispatch($batch->getKey());

            return;
        }
        foreach ($this->orderedArcs($this->payload($structure)) as $arc) {
            if ($this->arcBeatsArtifact($batch, (string) $arc['key']) === null) {
                GenerateNovelArcBeatsJob::dispatch($batch->getKey(), (string) $arc['key']);

                return;
            }
        }

        $skeleton = $this->artifact($batch, ArtifactType::OutlineSkeleton);
        if ($skeleton === null) {
            AssembleNovelOutlineSkeletonJob::dispatch($batch->getKey());

            return;
        }

        foreach ($this->mainBeats($this->payload($skeleton)) as $beat) {
            if ($this->beatDetailArtifact($batch, (string) $beat['key']) === null) {
                GenerateNovelBeatDetailJob::dispatch($batch->getKey(), (string) $beat['key']);

                return;
            }
        }

        if ($this->artifact($batch, ArtifactType::OutlineBlueprint) === null) {
            FinalizeNovelOutlineJob::dispatch($batch->getKey());
        }
    }

    /**
     * 为测试或显式同步调用顺序执行全部小阶段；每个 Provider 阶段仍只有一次请求。
     */
    public function runSynchronously(Novel $novel, int $volumeCount): GenerationArtifact
    {
        $batch = $this->startOrResume($novel, $volumeCount);
        $this->generateFoundation($batch);
        if ($this->isLegacyBatch($batch)) {
            $skeleton = $this->generateSkeleton($batch);
        } else {
            $structure = $this->generateStructure($batch);
            if ($structure === null) {
                throw new AiProviderException('outline_stage_in_progress', 'Outline Structure 正在由其他 Worker 处理。', true);
            }
            foreach ($this->orderedArcs($this->payload($structure)) as $arc) {
                $this->generateArcBeats($batch, (string) $arc['key']);
            }
            $skeleton = $this->assembleSkeleton($batch);
        }
        if ($skeleton === null) {
            throw new AiProviderException('outline_stage_in_progress', 'Outline Skeleton 正在由其他 Worker 处理。', true);
        }
        foreach ($this->mainBeats($this->payload($skeleton)) as $beat) {
            $this->generateBeatDetail($batch, (string) $beat['key']);
        }

        return $this->finalize($batch);
    }

    /**
     * Filament 使用与领域入口相同的目标平台预检；已有批次只验证冻结值，不读取变化后的环境默认值。
     */
    public function assertTargetPlatformReady(Novel $novel, int $volumeCount): void
    {
        $batch = GenerationRun::query()
            ->where('novel_id', $novel->getKey())
            ->whereNull('chapter_id')
            ->where('scope_type', self::BATCH_SCOPE)
            ->where('scope_id', $novel->getKey())
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running, RunStatus::Succeeded])
            ->latest('id')
            ->get()
            ->first(fn (GenerationRun $run): bool => (int) data_get($run->context_snapshot, 'requested_volume_count') === $volumeCount);

        if ($batch !== null) {
            $this->frozenTargetPlatform($batch);

            return;
        }

        $this->targetPlatformResolver->forNovel($novel);
    }

    /** Resume 只接受当前 Pipeline 合同创建且冻结信息完整的主批次。 */
    public function assertResumeCompatible(GenerationRun $batch): void
    {
        $this->assertBatch($batch);
        if ($batch->prompt_version !== self::BATCH_PROMPT_VERSION
            || data_get($batch->context_snapshot, 'generation_preferences.prompt_versions') !== $this->promptVersions()) {
            throw ValidationException::withMessages(['run' => 'Outline 主批次版本与当前 Pipeline 合同不兼容。']);
        }
        if (blank($batch->provider) || blank($batch->model_policy)) {
            throw ValidationException::withMessages(['run' => 'Outline 主批次缺少冻结的 Provider 或 Model。']);
        }
        $volumeCount = data_get($batch->context_snapshot, 'requested_volume_count');
        if (! is_int($volumeCount) || $volumeCount < 1 || $volumeCount > 12) {
            throw ValidationException::withMessages(['run' => 'Outline 主批次缺少有效的预计分卷数。']);
        }

        $this->frozenTargetPlatform($batch);
    }

    /** Queue 投递异常必须关闭刚准备的批次，不能让它永久停在 queued。 */
    public function markQueueDispatchFailed(GenerationRun $batch, Throwable $exception): void
    {
        DB::transaction(function () use ($batch, $exception): void {
            $locked = GenerationRun::query()->lockForUpdate()->find($batch->getKey());
            if ($locked === null || $locked->status !== RunStatus::Queued) {
                return;
            }

            $locked->update([
                'status' => RunStatus::Failed,
                'error_code' => 'queue_dispatch_failed',
                'error_message' => $exception->getMessage(),
                'error_retryable' => true,
                'error_metadata' => [
                    'category' => 'infrastructure_temporary',
                    'failed_scope' => self::BATCH_SCOPE,
                    'auto_retry_exhausted' => false,
                ],
                'finished_at' => now(),
            ]);
        }, 3);
    }

    /**
     * 子 Job 终止时聚合失败到主批次；旧回调不能覆盖更新的成功结果。
     */
    public function markBatchFailed(
        int $batchRunId,
        Throwable $exception,
        string $failedScope,
        ?string $discriminator,
        bool $autoRetryExhausted,
    ): bool {
        return DB::transaction(function () use ($batchRunId, $exception, $failedScope, $discriminator, $autoRetryExhausted): bool {
            $batch = GenerationRun::query()->lockForUpdate()->find($batchRunId);
            if ($batch === null || ! in_array($batch->status, [RunStatus::Queued, RunStatus::Running], true)) {
                return false;
            }
            $this->assertBatch($batch);

            $childRun = null;
            if ($failedScope !== self::BATCH_SCOPE) {
                $children = GenerationRun::query()
                    ->where('novel_id', $batch->novel_id)
                    ->where('scope_type', $failedScope)
                    ->where('scope_id', $batch->getKey())
                    ->where('stage', GenerationStage::ChapterPlanning);
                if ($discriminator !== null) {
                    $children->where('context_snapshot->discriminator', $discriminator);
                }
                $childRun = $children->latest('id')->first();

                // 已有更新的成功尝试时，迟到的旧 Job 失败回调只能保留历史，不能回退批次。
                if ($childRun?->status === RunStatus::Succeeded) {
                    return false;
                }
            }

            $failure = $childRun?->status === RunStatus::Failed
                ? $this->failurePolicy->forRun($childRun)
                : $this->failurePolicy->fromException(
                    $exception,
                    $failedScope === self::BATCH_SCOPE ? 'outline_batch_failed' : 'outline_stage_failed',
                );
            $metadata = array_filter([
                'failed_scope' => $failedScope,
                'discriminator' => $discriminator,
                'child_run_id' => $childRun?->getKey(),
                'auto_retry_exhausted' => $autoRetryExhausted,
            ], static fn (mixed $value): bool => $value !== null) + $failure->metadata;

            $batch->update([
                'status' => RunStatus::Failed,
                'error_code' => $failure->code,
                'error_message' => $failure->message,
                'error_retryable' => $failure->retryable,
                'error_metadata' => $metadata,
                'finished_at' => now(),
            ]);

            return true;
        }, 3);
    }

    public function generateFoundation(GenerationRun $batch): ?GenerationArtifact
    {
        $this->assertRunnableBatch($batch);
        $targetPlatform = $this->frozenTargetPlatform($batch);
        $context = [
            'novel' => data_get($batch->context_snapshot, 'novel'),
            'chapter_target_words' => data_get($batch->context_snapshot, 'generation_preferences.chapter_target_words'),
            'target_platform' => $targetPlatform,
        ];

        return $this->providerStage(
            batch: $batch,
            scopeType: self::FOUNDATION_SCOPE,
            artifactType: ArtifactType::OutlineFoundation,
            promptVersion: self::FOUNDATION_PROMPT_VERSION,
            context: $context,
            maxTokens: self::FOUNDATION_MAX_TOKENS,
            systemPrompt: '你是 XNovel 小说 Foundation 规划器。只返回严格 JSON。只规划开篇即成立的小说圣经、初始人物、初始世界实体和伏笔候选；不得返回 Volume、Arc、Beat、Milestone、Handoff 或任何数据库 ID。bible.style_profile.target_platform 必须精确返回输入 target_platform.code，不得自行更换平台。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning(),
            prompt: '请根据小说信息生成 Foundation 候选：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            schema: $this->foundationSchema($targetPlatform['code']),
            outputName: 'novel_outline_foundation',
            validate: fn (array $data): array => $this->validateFoundation($data, $targetPlatform['code']),
        );
    }

    public function generateSkeleton(GenerationRun $batch): ?GenerationArtifact
    {
        $this->assertRunnableBatch($batch);
        if (! $this->isLegacyBatch($batch)) {
            throw ValidationException::withMessages(['run' => '新版 Outline 批次禁止调用旧 Skeleton Provider 阶段。']);
        }
        $foundation = $this->requiredArtifact($batch, ArtifactType::OutlineFoundation);
        $volumeCount = (int) data_get($batch->context_snapshot, 'requested_volume_count');
        $context = [
            'novel' => data_get($batch->context_snapshot, 'novel'),
            'requested_volume_count' => $volumeCount,
            'foundation' => $this->payload($foundation),
            'foundation_artifact' => $this->artifactReference($foundation),
        ];

        return $this->providerStage(
            batch: $batch,
            scopeType: self::SKELETON_SCOPE,
            artifactType: ArtifactType::OutlineSkeleton,
            promptVersion: self::SKELETON_PROMPT_VERSION,
            context: $context,
            maxTokens: self::SKELETON_MAX_TOKENS,
            systemPrompt: '你是 XNovel 全书 Outline Skeleton 规划器。只返回严格 JSON。生成 Volume、Arc、Beat 骨架和 Beat 级约束，不得生成 Milestone、Handoff 或数据库 ID。稳定 Key 必须全局唯一；Laravel 决定顺序和后续引用。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning(),
            prompt: json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            schema: $this->skeletonSchema($volumeCount),
            outputName: 'novel_outline_skeleton',
            validate: fn (array $data): array => $this->validateSkeleton($data, $volumeCount),
            sourceArtifacts: [$foundation],
        );
    }

    /** 基于 Foundation 生成只包含 Volume / Arc 的 Structure。 */
    public function generateStructure(GenerationRun $batch): ?GenerationArtifact
    {
        $this->assertRunnableBatch($batch);
        $this->assertCurrentBatch($batch);
        $foundation = $this->requiredArtifact($batch, ArtifactType::OutlineFoundation);
        $volumeCount = (int) data_get($batch->context_snapshot, 'requested_volume_count');
        $context = [
            'novel' => data_get($batch->context_snapshot, 'novel'),
            'requested_volume_count' => $volumeCount,
            'foundation' => $this->payload($foundation),
            'foundation_artifact' => $this->artifactReference($foundation),
        ];
        $schema = $this->stageContract->structureSchema($volumeCount);
        $systemPrompt = '你是 XNovel 全书 Structure 规划器。只返回严格 JSON。只生成全书、Volume 与 Arc 结构，不得返回 Beat、Milestone、Handoff、sequence、mainline_sequence 或数据库 ID。稳定 Key 必须全局唯一。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning();
        $prompt = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $capacity = $this->capacitySnapshot($batch, compact('systemPrompt', 'prompt', 'schema'), NovelOutlineStageContract::STRUCTURE_MAX_OUTPUT_TOKENS);

        return $this->providerStage(
            batch: $batch,
            scopeType: self::STRUCTURE_SCOPE,
            artifactType: ArtifactType::OutlineStructure,
            promptVersion: NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION,
            context: [...$context, 'capacity_snapshot' => $capacity],
            maxTokens: NovelOutlineStageContract::STRUCTURE_MAX_OUTPUT_TOKENS,
            systemPrompt: $systemPrompt,
            prompt: $prompt,
            schema: $schema,
            outputName: 'novel_outline_structure',
            validate: fn (array $data): array => $this->stageContract->validateStructure($data, $volumeCount),
            sourceArtifacts: [$foundation],
        );
    }

    /** 一次只生成 Structure 中一个 Arc 的 Beats。 */
    public function generateArcBeats(GenerationRun $batch, string $arcKey): ?GenerationArtifact
    {
        $this->assertRunnableBatch($batch);
        $this->assertCurrentBatch($batch);
        $foundation = $this->requiredArtifact($batch, ArtifactType::OutlineFoundation);
        $structure = $this->requiredCurrentStructure($batch);
        $structureData = $this->payload($structure);
        $context = $this->stageContract->arcBeatsContext($this->payload($foundation), $structureData, $arcKey) + [
            'source_artifacts' => [$this->artifactReference($foundation), $this->artifactReference($structure)],
        ];
        $schema = $this->stageContract->arcBeatsSchema();
        $systemPrompt = '你是 XNovel 单 Arc Beats 规划器。只返回严格 JSON。只生成目标 Arc 的 Beats、预算、验收条件和候选；不得返回其他 Arc、Volume、Milestone、Handoff、sequence、mainline_sequence 或数据库 ID。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning();
        $prompt = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $capacity = $this->capacitySnapshot($batch, compact('systemPrompt', 'prompt', 'schema'), NovelOutlineStageContract::ARC_BEATS_MAX_OUTPUT_TOKENS);

        return $this->providerStage(
            batch: $batch,
            scopeType: self::ARC_BEATS_SCOPE,
            artifactType: ArtifactType::OutlineArcBeats,
            promptVersion: NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION,
            context: [...$context, 'capacity_snapshot' => $capacity],
            maxTokens: NovelOutlineStageContract::ARC_BEATS_MAX_OUTPUT_TOKENS,
            systemPrompt: $systemPrompt,
            prompt: $prompt,
            schema: $schema,
            outputName: 'novel_outline_arc_beats',
            validate: fn (array $data): array => $this->stageContract->validateArcBeats($data, $arcKey, $structureData),
            sourceArtifacts: [$foundation, $structure],
            discriminator: $arcKey,
        );
    }

    /** 按 Structure 顺序确定性合并全部 Arc Beats；不调用 Provider。 */
    public function assembleSkeleton(GenerationRun $batch): ?GenerationArtifact
    {
        $this->assertRunnableBatch($batch);
        $this->assertCurrentBatch($batch);
        $foundation = $this->requiredArtifact($batch, ArtifactType::OutlineFoundation);
        $structure = $this->requiredCurrentStructure($batch);
        $structureData = $this->payload($structure);
        $arcArtifacts = [];
        foreach ($this->orderedArcs($structureData) as $arc) {
            $arcKey = (string) $arc['key'];
            $artifact = $this->arcBeatsArtifact($batch, $arcKey);
            if ($artifact === null) {
                throw ValidationException::withMessages(['outline' => "Arc {$arcKey} 缺少 Beats Artifact。"]);
            }
            $arcArtifacts[$arcKey] = $artifact;
        }

        $sourceReferences = [
            'foundation' => $this->artifactReference($foundation),
            'structure' => $this->artifactReference($structure),
            'arc_beats' => collect($arcArtifacts)->map(fn (GenerationArtifact $artifact): array => $this->artifactReference($artifact))->all(),
        ];
        $inputHash = $this->hash(['algorithm' => self::SKELETON_ASSEMBLY_VERSION, 'sources' => $sourceReferences]);
        $runs = $this->stageRuns($batch, self::SKELETON_ASSEMBLY_SCOPE);
        $reusable = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first();
        $artifact = $reusable?->artifacts()->where('type', ArtifactType::OutlineSkeleton)->first();
        if ($artifact instanceof GenerationArtifact) {
            return $artifact;
        }
        $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
        if ($this->runLease->isFresh($active)) {
            return null;
        }
        if ($active !== null) {
            $active->update([
                'status' => RunStatus::Failed,
                'error_code' => 'worker_interrupted',
                'error_message' => 'Skeleton Assembly Worker 超时，已由后续投递恢复。',
                'error_retryable' => false,
                'error_metadata' => ['category' => 'worker_lost', 'result_uncertain' => true],
                'finished_at' => now(),
            ]);
        }

        $this->assertStageArtifact($batch, $foundation, ArtifactType::OutlineFoundation, self::FOUNDATION_SCOPE);
        $this->assertStageArtifact($batch, $structure, ArtifactType::OutlineStructure, self::STRUCTURE_SCOPE, null, $this->structureInputHash($batch, $foundation));
        foreach ($arcArtifacts as $arcKey => $arcArtifact) {
            $this->assertStageArtifact($batch, $arcArtifact, ArtifactType::OutlineArcBeats, self::ARC_BEATS_SCOPE, $arcKey, $this->arcBeatsInputHash($batch, $foundation, $structure, $arcKey));
        }

        $skeleton = $structureData;
        foreach ($skeleton['volumes'] as &$volume) {
            foreach ($volume['arcs'] as &$arc) {
                $arc['beats'] = $this->payload($arcArtifacts[(string) $arc['key']])['beats'];
            }
            unset($arc);
        }
        unset($volume);
        $skeleton = $this->validateSkeleton($skeleton, (int) data_get($batch->context_snapshot, 'requested_volume_count'));
        $attempt = ((int) $runs->clone()->max('attempt')) + 1;
        $run = GenerationRun::query()->create([
            'novel_id' => $batch->novel_id,
            'scope_type' => self::SKELETON_ASSEMBLY_SCOPE,
            'scope_id' => $batch->getKey(),
            'stage' => GenerationStage::ChapterPlanning,
            'status' => RunStatus::Running,
            'attempt' => $attempt,
            'idempotency_key' => 'outline-skeleton-assembly:'.$batch->getKey().":{$inputHash}:{$attempt}",
            'input_hash' => $inputHash,
            'prompt_version' => self::SKELETON_ASSEMBLY_VERSION,
            'provider' => null,
            'model_policy' => null,
            'context_snapshot' => ['batch_run_id' => $batch->getKey(), 'algorithm' => self::SKELETON_ASSEMBLY_VERSION, 'source_artifacts' => $sourceReferences],
            'started_at' => now(),
        ]);

        try {
            return DB::transaction(function () use ($run, $batch, $sourceReferences, $skeleton): GenerationArtifact {
                $data = [
                    'batch_run_id' => $batch->getKey(),
                    'stage' => self::SKELETON_ASSEMBLY_SCOPE,
                    'discriminator' => null,
                    'algorithm' => self::SKELETON_ASSEMBLY_VERSION,
                    'source_artifacts' => $sourceReferences,
                    'payload' => $skeleton,
                ];
                $artifact = $run->artifacts()->create([
                    'type' => ArtifactType::OutlineSkeleton,
                    'version' => 1,
                    'content' => json_encode($skeleton, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'data' => $data,
                    'checksum' => $this->hash($data),
                ]);
                $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

                return $artifact;
            });
        } catch (Throwable $exception) {
            $this->failurePolicy->record($run, $exception, 'outline_skeleton_assembly_failed');

            throw $exception;
        }
    }

    public function generateBeatDetail(GenerationRun $batch, string $beatKey): ?GenerationArtifact
    {
        $this->assertRunnableBatch($batch);
        $foundation = $this->requiredArtifact($batch, ArtifactType::OutlineFoundation);
        $skeleton = $this->requiredArtifact($batch, ArtifactType::OutlineSkeleton);
        [$context, $nextBeatKey] = $this->beatDetailContext($foundation, $skeleton, $beatKey);

        return $this->providerStage(
            batch: $batch,
            scopeType: self::BEAT_DETAIL_SCOPE,
            artifactType: ArtifactType::OutlineBeatDetail,
            promptVersion: self::BEAT_DETAIL_PROMPT_VERSION,
            context: $context,
            maxTokens: self::BEAT_DETAIL_MAX_TOKENS,
            systemPrompt: '你是 XNovel 单 Main Beat 细化器。只返回目标 Beat 的稳定 beat_key、Milestones 和出站 Handoff。不得返回其他 Beat、完整 Outline 或任何数据库 ID。Handoff 只能指向给定的相邻下一 Main Beat；最终 Beat 必须返回 null。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning(),
            prompt: json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            schema: $this->beatDetailSchema(),
            outputName: 'novel_outline_beat_detail',
            validate: fn (array $data): array => $this->validateBeatDetail($data, $beatKey, $nextBeatKey),
            sourceArtifacts: [$foundation, $skeleton],
            discriminator: $beatKey,
        );
    }

    /**
     * 合并不可变阶段 Artifact；此方法不调用 Provider。
     */
    public function finalize(GenerationRun $batch): GenerationArtifact
    {
        $this->assertRunnableBatch($batch);
        $foundation = $this->requiredArtifact($batch, ArtifactType::OutlineFoundation);
        $skeleton = $this->requiredArtifact($batch, ArtifactType::OutlineSkeleton);
        $skeletonData = $this->payload($skeleton);
        $details = [];
        foreach ($this->mainBeats($skeletonData) as $beat) {
            $artifact = $this->beatDetailArtifact($batch, (string) $beat['key']);
            if ($artifact === null) {
                throw ValidationException::withMessages(['outline' => "Main Beat {$beat['key']} 缺少 Detail Artifact。"]);
            }
            $details[(string) $beat['key']] = $artifact;
        }

        $this->assertStageArtifact($batch, $foundation, ArtifactType::OutlineFoundation, self::FOUNDATION_SCOPE);
        $structure = null;
        $arcArtifacts = [];
        if ($this->isLegacyBatch($batch)) {
            $this->assertStageArtifact($batch, $skeleton, ArtifactType::OutlineSkeleton, self::SKELETON_SCOPE);
        } else {
            $structure = $this->requiredCurrentStructure($batch);
            foreach ($this->orderedArcs($this->payload($structure)) as $arc) {
                $arcKey = (string) $arc['key'];
                $arcArtifact = $this->arcBeatsArtifact($batch, $arcKey)
                    ?? throw ValidationException::withMessages(['outline' => "Arc {$arcKey} 缺少 Beats Artifact。"]);
                $this->assertStageArtifact($batch, $arcArtifact, ArtifactType::OutlineArcBeats, self::ARC_BEATS_SCOPE, $arcKey, $this->arcBeatsInputHash($batch, $foundation, $structure, $arcKey));
                $arcArtifacts[$arcKey] = $arcArtifact;
            }
            $assemblyInputHash = $this->hash([
                'algorithm' => self::SKELETON_ASSEMBLY_VERSION,
                'sources' => [
                    'foundation' => $this->artifactReference($foundation),
                    'structure' => $this->artifactReference($structure),
                    'arc_beats' => collect($arcArtifacts)->map(fn (GenerationArtifact $artifact): array => $this->artifactReference($artifact))->all(),
                ],
            ]);
            $this->assertStageArtifact($batch, $skeleton, ArtifactType::OutlineSkeleton, self::SKELETON_ASSEMBLY_SCOPE, null, $assemblyInputHash);
        }
        foreach ($details as $beatKey => $artifact) {
            $this->assertStageArtifact(
                $batch,
                $artifact,
                ArtifactType::OutlineBeatDetail,
                self::BEAT_DETAIL_SCOPE,
                $beatKey,
                $this->beatDetailInputHash($batch, $foundation, $skeleton, $beatKey),
            );
        }

        $outline = $this->mergeOutline($skeletonData, $details);
        $this->outlineValidator->assertValid($outline);
        $foundationData = $this->payload($foundation);
        $this->assertFoundationReferences($foundationData, $outline);
        $lineage = [
            'batch_run_id' => $batch->getKey(),
            'foundation' => $this->artifactReference($foundation),
            'skeleton' => $this->artifactReference($skeleton),
            'beat_details' => collect($details)->map(fn (GenerationArtifact $artifact): array => $this->artifactReference($artifact))->all(),
        ];
        if ($structure !== null) {
            $lineage['structure'] = $this->artifactReference($structure);
            $lineage['arc_beats'] = collect($arcArtifacts)->map(fn (GenerationArtifact $artifact): array => $this->artifactReference($artifact))->all();
        }
        $blueprint = [
            ...$foundationData,
            'outline' => $outline,
            'lineage' => $lineage,
        ];
        $inputHash = $this->hash($blueprint['lineage']);

        $reusableRun = $this->stageRuns($batch, self::FINALIZE_SCOPE)
            ->where('input_hash', $inputHash)
            ->where('status', RunStatus::Succeeded)
            ->latest('id')
            ->first();
        $reusable = $reusableRun?->artifacts()->where('type', ArtifactType::OutlineBlueprint)->first();
        if ($reusable instanceof GenerationArtifact) {
            return $reusable;
        }

        $attempt = ((int) $this->stageRuns($batch, self::FINALIZE_SCOPE)->max('attempt')) + 1;
        $run = GenerationRun::query()->create([
            'novel_id' => $batch->novel_id,
            'scope_type' => self::FINALIZE_SCOPE,
            'scope_id' => $batch->getKey(),
            'stage' => GenerationStage::ChapterPlanning,
            'status' => RunStatus::Running,
            'attempt' => $attempt,
            'idempotency_key' => "outline-finalize:{$batch->getKey()}:{$inputHash}:{$attempt}",
            'input_hash' => $inputHash,
            'prompt_version' => self::FINALIZE_PROMPT_VERSION,
            'provider' => $batch->provider,
            'model_policy' => $batch->model_policy,
            'context_snapshot' => ['batch_run_id' => $batch->getKey(), 'lineage' => $blueprint['lineage']],
            'started_at' => now(),
        ]);

        try {
            return DB::transaction(function () use ($run, $batch, $blueprint, $outline): GenerationArtifact {
                $artifact = $run->artifacts()->create([
                    'type' => ArtifactType::OutlineBlueprint,
                    'version' => 1,
                    'content' => json_encode($blueprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'data' => $blueprint,
                    'checksum' => $this->hash($blueprint),
                ]);
                // 来源校验要求 Finalize Run 已成功；该状态与后续 Outline 写入仍处于同一事务，失败会一起回滚。
                $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);
                $this->createOutlineVersion->handle(
                    novel: $batch->novel()->firstOrFail(),
                    outline: $outline,
                    source: NovelOutlineSource::Ai,
                    sourceArtifact: $artifact,
                );
                $batch->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);
                $batch->novel()->update(['status' => NovelStatus::Planning]);

                return $artifact;
            }, 3);
        } catch (Throwable $exception) {
            $this->failurePolicy->record($run, $exception, 'outline_finalize_failed');

            throw $exception;
        }
    }

    /** 返回主批次需要冻结的全部阶段 Prompt 版本。 */
    public function promptVersions(): array
    {
        return [
            'foundation' => self::FOUNDATION_PROMPT_VERSION,
            'structure' => NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION,
            'arc_beats' => NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION,
            'skeleton_assembly' => self::SKELETON_ASSEMBLY_VERSION,
            'beat_detail' => self::BEAT_DETAIL_PROMPT_VERSION,
            'finalize' => self::FINALIZE_PROMPT_VERSION,
        ];
    }

    /** Foundation 只允许 Bible 与初始领域候选，不包含 Outline 节点。 */
    public function foundationSchema(?string $targetPlatform = null): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $currentState = $this->object(['location' => ['type' => ['string', 'null']], 'summary' => ['type' => 'string']]);

        return $this->object([
            'bible' => $this->object([
                'logline' => ['type' => 'string'], 'themes' => $strings, 'tone' => ['type' => 'string'], 'pov' => ['type' => 'string'], 'tense' => ['type' => 'string'],
                'taboos' => $strings, 'hard_constraints' => $strings,
                'ending_contract' => $this->object([
                    'final_protagonist_state' => ['type' => 'string'], 'main_conflict_resolution' => ['type' => 'string'], 'theme_payoff' => ['type' => 'string'],
                    'required_foreshadowing_payoff' => $strings, 'character_arc_requirements' => $strings, 'allowed_open_endings' => $strings,
                ]),
                'style_profile' => $this->styleProfileSchema($targetPlatform),
            ]),
            'characters' => ['type' => 'array', 'items' => $this->object([
                'name' => ['type' => 'string'], 'role' => ['type' => 'string', 'enum' => ['主角', '配角', '反派']], 'motivation' => ['type' => 'string'],
                'profile' => $strings, 'personality' => $strings, 'abilities' => $strings, 'knowledge' => $strings, 'current_state' => $currentState,
            ])],
            'world_entities' => ['type' => 'array', 'items' => $this->object([
                'type' => ['type' => 'string', 'enum' => ['location', 'item', 'faction', 'organization', 'rule', 'concept']], 'name' => ['type' => 'string'],
                'description' => ['type' => 'string'], 'attributes' => $strings, 'rules' => $strings, 'current_state' => $strings,
            ])],
            'foreshadowings' => ['type' => 'array', 'items' => $this->object([
                'title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'promised_payoff' => ['type' => 'string'],
                'due_from_chapter' => ['type' => 'integer', 'minimum' => 1], 'due_to_chapter' => ['type' => 'integer', 'minimum' => 1],
                'importance' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']], 'owner_arc_key' => ['type' => ['string', 'null']],
            ])],
        ]);
    }

    /** Skeleton 只允许 Volume、Arc、Beat 骨架，不包含 Milestone 与 Handoff。 */
    public function skeletonSchema(int $volumeCount): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $stableKey = ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*$'];
        $candidateBase = [
            'candidate_key' => $stableKey, 'name' => ['type' => 'string'], 'deduplication_basis' => ['type' => 'string'],
            'introduction_reason' => ['type' => 'string'], 'target_scene_sequence' => ['type' => 'integer', 'minimum' => 1],
        ];
        $characterCandidate = $this->object($candidateBase + [
            'role' => ['type' => 'string'], 'motivation' => ['type' => 'string'], 'profile' => $strings,
            'personality' => $strings, 'abilities' => $strings, 'knowledge' => $strings,
        ]);
        $worldCandidate = $this->object($candidateBase + [
            'type' => ['type' => 'string', 'enum' => ['location', 'item', 'faction', 'organization', 'rule', 'concept']], 'description' => ['type' => 'string'],
        ]);
        $beat = $this->object([
            'key' => ['type' => 'string', 'pattern' => '^beat-[0-9]{2,}$'], 'sequence' => ['type' => 'integer', 'minimum' => 1],
            'mainline_sequence' => ['type' => ['integer', 'null'], 'minimum' => 1], 'title' => ['type' => 'string'], 'summary' => ['type' => 'string'],
            'chapter_budget' => $this->object(['min' => ['type' => 'integer', 'minimum' => 1], 'max' => ['type' => ['integer', 'null'], 'minimum' => 1]]),
            'acceptance_criteria' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']], 'must_include' => $strings, 'must_not_include' => $strings,
            'character_candidates' => ['type' => 'array', 'items' => $characterCandidate], 'world_entity_candidates' => ['type' => 'array', 'items' => $worldCandidate],
        ]);
        $arc = $this->object([
            'key' => ['type' => 'string', 'pattern' => '^arc-[0-9]{2,}$'], 'sequence' => ['type' => 'integer', 'minimum' => 1],
            'mainline_sequence' => ['type' => ['integer', 'null'], 'minimum' => 1], 'type' => ['type' => 'string', 'enum' => ['main', 'subplot']],
            'title' => ['type' => 'string'], 'goal' => ['type' => 'string'], 'stakes' => ['type' => 'string'],
            'completion_conditions' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
            'beats' => ['type' => 'array', 'minItems' => 1, 'items' => $beat],
        ]);
        $volume = $this->object([
            'key' => ['type' => 'string', 'pattern' => '^vol-[0-9]{2}$'], 'sequence' => ['type' => 'integer', 'minimum' => 1],
            'title' => ['type' => 'string'], 'goal' => ['type' => 'string'], 'climax' => ['type' => 'string'], 'target_words' => ['type' => 'integer', 'minimum' => 1],
            'arcs' => ['type' => 'array', 'minItems' => 1, 'items' => $arc],
        ]);

        return $this->object([
            'title' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'must_include' => $strings, 'must_not_include' => $strings,
            'volumes' => ['type' => 'array', 'minItems' => $volumeCount, 'maxItems' => $volumeCount, 'items' => $volume],
        ]);
    }

    /** Beat Detail 只允许单个 Main Beat 的 Milestones 与一个 Handoff。 */
    public function beatDetailSchema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $milestone = $this->object([
            'key' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*$'], 'sequence' => ['type' => 'integer', 'minimum' => 1],
            'title' => ['type' => 'string'], 'objective' => ['type' => 'string'],
            'acceptance_criteria' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']], 'must_include' => $strings, 'must_not_include' => $strings,
        ]);

        return $this->object([
            'beat_key' => ['type' => 'string', 'pattern' => '^beat-[0-9]{2,}$'],
            'milestones' => ['type' => 'array', 'minItems' => 1, 'items' => $milestone],
            'handoff' => $this->object([
                'next_beat_key' => ['type' => ['string', 'null']], 'transition_mode' => ['type' => ['string', 'null']],
                'exit_result' => ['type' => ['string', 'null']], 'next_trigger' => ['type' => ['string', 'null']],
                'carried_states' => $strings, 'open_threads' => $strings, 'required_transition' => $strings, 'forbidden_jump' => $strings,
            ]),
        ]);
    }

    /**
     * 执行一次有界 Provider 阶段，并负责 Run 复用、失败分类和 Artifact 原子保存。
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $validate
     * @param  array<int, GenerationArtifact>  $sourceArtifacts
     */
    private function providerStage(
        GenerationRun $batch,
        string $scopeType,
        ArtifactType $artifactType,
        string $promptVersion,
        array $context,
        int $maxTokens,
        string $systemPrompt,
        string $prompt,
        array $schema,
        string $outputName,
        callable $validate,
        array $sourceArtifacts = [],
        ?string $discriminator = null,
    ): ?GenerationArtifact {
        $this->assertFrozenRoute($batch);
        $inputHash = $this->providerInputHash($batch, $context, $promptVersion);
        $runs = $this->stageRuns($batch, $scopeType);
        $reusable = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first();
        $artifact = $reusable?->artifacts()->where('type', $artifactType)->first();
        if ($artifact instanceof GenerationArtifact) {
            return $artifact;
        }

        $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
        if ($this->runLease->isFresh($active)) {
            return null;
        }
        if ($active !== null) {
            $active->update([
                'status' => RunStatus::Failed,
                'error_code' => 'worker_interrupted',
                'error_message' => 'Outline 阶段 Worker 超时，已由后续投递从当前阶段恢复。',
                'error_retryable' => false,
                'error_metadata' => ['category' => 'worker_lost', 'result_uncertain' => true],
                'finished_at' => now(),
            ]);
        }

        $attempt = ((int) $runs->clone()->max('attempt')) + 1;
        $suffix = $discriminator === null ? '' : ':'.$discriminator;
        $run = GenerationRun::query()->create([
            'novel_id' => $batch->novel_id,
            'scope_type' => $scopeType,
            'scope_id' => $batch->getKey(),
            'stage' => GenerationStage::ChapterPlanning,
            'status' => RunStatus::Running,
            'attempt' => $attempt,
            'idempotency_key' => "{$scopeType}:{$batch->getKey()}{$suffix}:{$inputHash}:{$attempt}",
            'input_hash' => $inputHash,
            'prompt_version' => $promptVersion,
            'provider' => $batch->provider,
            'model_policy' => $batch->model_policy,
            'context_snapshot' => [
                'batch_run_id' => $batch->getKey(),
                'discriminator' => $discriminator,
                'source_artifacts' => array_map(fn (GenerationArtifact $item): array => $this->artifactReference($item), $sourceArtifacts),
                'input' => $context,
                'reasoning_effort' => data_get($batch->context_snapshot, 'generation_preferences.reasoning_effort'),
                'max_completion_tokens' => $maxTokens,
            ],
            'started_at' => now(),
        ]);

        $failureCode = 'outline_stage_failed';
        try {
            $response = $this->provider->generate(new AiRequest(
                model: (string) $batch->model_policy,
                provider: (string) $batch->provider,
                reasoningEffort: data_get($batch->context_snapshot, 'generation_preferences.reasoning_effort'),
                systemPrompt: $systemPrompt,
                prompt: $prompt,
                temperature: 0.5,
                maxTokens: $maxTokens,
                responseSchema: $schema,
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $batch->novel_id,
                    'batch_run_id' => $batch->getKey(),
                    'outline_stage' => $scopeType,
                    'stage' => AiStage::Planner->value,
                ],
            ));
            $failureCode = 'outline_stage_structured_output_invalid';
            $payload = StructuredOutput::require($response, $outputName, '小说 Outline 分阶段规划');
            $failureCode = 'outline_stage_schema_invalid';
            $this->assertSchemaShape($payload, $schema);
            $failureCode = 'outline_stage_domain_validation_failed';
            $payload = $validate($payload);
            $data = [
                'batch_run_id' => $batch->getKey(),
                'stage' => $scopeType,
                'discriminator' => $discriminator,
                'source_artifacts' => array_map(fn (GenerationArtifact $item): array => $this->artifactReference($item), $sourceArtifacts),
                'payload' => $payload,
            ];
        } catch (Throwable $exception) {
            // Provider、解析、Strict Schema 与领域校验都保留原始错误语义，不能伪装成持久化不确定。
            $this->failurePolicy->record($run, $exception, $failureCode);

            throw $exception;
        }

        try {
            return DB::transaction(function () use ($run, $artifactType, $response, $data): GenerationArtifact {
                $artifact = $run->artifacts()->create([
                    'type' => $artifactType,
                    'version' => 1,
                    'content' => $response->content,
                    'data' => $data,
                    'checksum' => $this->hash($data),
                ]);
                $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

                return $artifact;
            });
        } catch (Throwable $exception) {
            // 只有完整验证后的结果在 Artifact 事务中失败，才表示持久化结果无法确认。
            $run->update([
                'status' => RunStatus::Failed,
                'error_code' => 'outline_stage_result_uncertain',
                'error_message' => $exception->getMessage(),
                'error_retryable' => false,
                'error_metadata' => array_filter([
                    'category' => 'infrastructure_temporary',
                    'result_uncertain' => true,
                    'provider_request_id' => $response->providerRequestId,
                ]),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /** 在持久化前验证 Foundation 的叙事、文风与初始对象边界。 */
    private function validateFoundation(array $data, string $targetPlatform): array
    {
        $rules = [
            'bible' => ['required', 'array'], 'bible.logline' => ['required', 'string'], 'bible.themes' => ['required', 'array', 'min:1'],
            'bible.themes.*' => ['string'],
            'bible.tone' => ['required', 'string'], 'bible.pov' => ['required', 'string'], 'bible.tense' => ['required', 'string'],
            'bible.taboos' => ['present', 'array'], 'bible.taboos.*' => ['string'],
            'bible.hard_constraints' => ['present', 'array'], 'bible.hard_constraints.*' => ['string'],
            'bible.ending_contract' => ['required', 'array'],
            'bible.style_profile' => ['required', 'array'],
            'bible.style_profile.subgenre' => ['present', 'nullable', 'string'],
            'bible.style_profile.target_platform' => ['required', Rule::in([$targetPlatform])],
            'bible.style_profile.primary_style' => ['required', Rule::in(array_keys(config('narrative.styles', [])))],
            'bible.style_profile.secondary_styles' => ['present', 'array', 'max:2'],
            'bible.style_profile.secondary_styles.*' => ['string', 'distinct:strict', Rule::in(array_keys(config('narrative.styles', [])))],
            'bible.style_profile.language_era' => ['required', Rule::in(array_keys(config('narrative.language_eras', [])))],
            'bible.style_profile.pacing' => ['required', Rule::in(array_keys(config('narrative.paces', [])))],
            'characters' => ['required', 'array', 'min:1'],
            'characters.*.name' => ['required', 'string'], 'characters.*.role' => ['required', Rule::in(['主角', '配角', '反派'])],
            'characters.*.motivation' => ['required', 'string'], 'characters.*.profile' => ['required', 'array'],
            'characters.*.personality' => ['required', 'array'], 'characters.*.abilities' => ['required', 'array'],
            'characters.*.knowledge' => ['required', 'array'], 'characters.*.current_state' => ['required', 'array'],
            'world_entities' => ['required', 'array', 'min:1'],
            'world_entities.*.type' => ['required', Rule::in(['location', 'item', 'faction', 'organization', 'rule', 'concept'])],
            'world_entities.*.name' => ['required', 'string'], 'world_entities.*.description' => ['required', 'string'],
            'world_entities.*.attributes' => ['required', 'array'], 'world_entities.*.rules' => ['required', 'array'],
            'world_entities.*.current_state' => ['required', 'array'],
            'foreshadowings' => ['present', 'array'], 'foreshadowings.*.title' => ['required', 'string'],
            'foreshadowings.*.description' => ['required', 'string'], 'foreshadowings.*.promised_payoff' => ['required', 'string'],
            'foreshadowings.*.due_from_chapter' => ['required', 'integer', 'min:1'], 'foreshadowings.*.due_to_chapter' => ['required', 'integer', 'min:1'],
            'foreshadowings.*.importance' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'foreshadowings.*.owner_arc_key' => ['present', 'nullable', 'string', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
        ];
        foreach (config('narrative.parameter_keys', []) as $key) {
            $rules["bible.style_profile.parameters.{$key}"] = ['required', 'integer', 'between:1,5'];
        }
        $valid = Validator::make($data, $rules)->validate();
        $valid = array_replace_recursive($data, $valid);
        if (in_array(data_get($valid, 'bible.style_profile.primary_style'), data_get($valid, 'bible.style_profile.secondary_styles', []), true)) {
            throw ValidationException::withMessages(['bible.style_profile.secondary_styles' => '辅助文风不能与主文风重复。']);
        }
        if (! collect($valid['characters'])->contains(fn (array $character): bool => $character['role'] === '主角')) {
            throw new AiProviderException('novel_plan_protagonist_missing', 'Foundation 必须包含至少一名主角。', false);
        }
        if (collect($valid['foreshadowings'])->contains(fn (array $item): bool => $item['due_to_chapter'] < $item['due_from_chapter'])) {
            throw new AiProviderException('novel_plan_due_window_invalid', 'Foundation 包含无效的伏笔回收区间。', false);
        }

        return $valid;
    }

    /** 验证 Skeleton 的层级、Key、预算与主线连续性。 */
    private function validateSkeleton(array $data, int $volumeCount): array
    {
        $data = $this->normalizeSkeletonSequences($data);
        Validator::make($data, [
            'title' => ['required', 'string'], 'summary' => ['required', 'string'], 'must_include' => ['array'], 'must_not_include' => ['array'],
            'must_include.*' => ['string'], 'must_not_include.*' => ['string'],
            'volumes' => ['required', 'array', 'size:'.$volumeCount],
            'volumes.*.key' => ['required', 'string', 'regex:/^vol-[0-9]{2}$/'],
            'volumes.*.title' => ['required', 'string'], 'volumes.*.goal' => ['required', 'string'],
            'volumes.*.climax' => ['required', 'string'], 'volumes.*.target_words' => ['required', 'integer', 'min:1'],
            'volumes.*.arcs' => ['required', 'array', 'min:1'],
            'volumes.*.arcs.*.key' => ['required', 'string', 'regex:/^arc-[0-9]{2,}$/'],
            'volumes.*.arcs.*.type' => ['required', Rule::in(['main', 'subplot'])],
            'volumes.*.arcs.*.title' => ['required', 'string'], 'volumes.*.arcs.*.goal' => ['required', 'string'],
            'volumes.*.arcs.*.stakes' => ['required', 'string'], 'volumes.*.arcs.*.completion_conditions' => ['required', 'array', 'min:1'],
            'volumes.*.arcs.*.completion_conditions.*' => ['string'],
            'volumes.*.arcs.*.beats' => ['required', 'array', 'min:1'],
            'volumes.*.arcs.*.beats.*.key' => ['required', 'string', 'regex:/^beat-[0-9]{2,}$/'],
            'volumes.*.arcs.*.beats.*.title' => ['required', 'string'], 'volumes.*.arcs.*.beats.*.summary' => ['required', 'string'],
            'volumes.*.arcs.*.beats.*.chapter_budget.min' => ['required', 'integer', 'min:1'],
            'volumes.*.arcs.*.beats.*.chapter_budget.max' => ['present', 'nullable', 'integer', 'min:1'],
            'volumes.*.arcs.*.beats.*.acceptance_criteria' => ['required', 'array', 'min:1'],
            'volumes.*.arcs.*.beats.*.must_include' => ['present', 'array'], 'volumes.*.arcs.*.beats.*.must_not_include' => ['present', 'array'],
            'volumes.*.arcs.*.beats.*.character_candidates' => ['present', 'array'],
            'volumes.*.arcs.*.beats.*.character_candidates.*.candidate_key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'volumes.*.arcs.*.beats.*.character_candidates.*.name' => ['required', 'string'],
            'volumes.*.arcs.*.beats.*.character_candidates.*.role' => ['required', 'string'],
            'volumes.*.arcs.*.beats.*.character_candidates.*.motivation' => ['required', 'string'],
            'volumes.*.arcs.*.beats.*.character_candidates.*.target_scene_sequence' => ['required', 'integer', 'min:1'],
            'volumes.*.arcs.*.beats.*.world_entity_candidates' => ['present', 'array'],
            'volumes.*.arcs.*.beats.*.world_entity_candidates.*.candidate_key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'volumes.*.arcs.*.beats.*.world_entity_candidates.*.type' => ['required', Rule::in(['location', 'item', 'faction', 'organization', 'rule', 'concept'])],
            'volumes.*.arcs.*.beats.*.world_entity_candidates.*.name' => ['required', 'string'],
            'volumes.*.arcs.*.beats.*.world_entity_candidates.*.description' => ['required', 'string'],
            'volumes.*.arcs.*.beats.*.world_entity_candidates.*.target_scene_sequence' => ['required', 'integer', 'min:1'],
        ])->validate();

        $keys = [];
        $mainArcSequence = 0;
        $mainBeatSequence = 0;
        foreach ($data['volumes'] as $volume) {
            $this->assertUniqueStableKey((string) $volume['key'], $keys, 'Volume');
            foreach ($volume['arcs'] as $arc) {
                $this->assertUniqueStableKey((string) $arc['key'], $keys, 'Arc');
                $isMain = $arc['type'] === 'main';
                if ($isMain && $arc['mainline_sequence'] !== ++$mainArcSequence) {
                    throw ValidationException::withMessages(['outline' => 'Main Arc mainline_sequence 必须连续。']);
                }
                if (! $isMain && $arc['mainline_sequence'] !== null) {
                    throw ValidationException::withMessages(['outline' => 'Subplot Arc 的 mainline_sequence 必须为空。']);
                }
                foreach ($arc['beats'] as $beat) {
                    $this->assertUniqueStableKey((string) $beat['key'], $keys, 'Beat');
                    $minimum = $beat['chapter_budget']['min'];
                    $maximum = $beat['chapter_budget']['max'];
                    if ($maximum !== null && $maximum < $minimum) {
                        throw ValidationException::withMessages(['outline' => 'Beat chapter_budget.max 不能小于 min。']);
                    }
                    foreach ([...$beat['character_candidates'], ...$beat['world_entity_candidates']] as $candidate) {
                        $this->assertUniqueStableKey((string) $candidate['candidate_key'], $keys, 'Candidate');
                    }
                    if ($isMain && $beat['mainline_sequence'] !== ++$mainBeatSequence) {
                        throw ValidationException::withMessages(['outline' => 'Main Beat mainline_sequence 必须连续。']);
                    }
                    if (! $isMain && $beat['mainline_sequence'] !== null) {
                        throw ValidationException::withMessages(['outline' => 'Subplot Beat 的 mainline_sequence 必须为空。']);
                    }
                }
            }
        }
        if ($mainBeatSequence === 0) {
            throw ValidationException::withMessages(['outline' => 'Skeleton 至少需要一个 Main Beat。']);
        }

        return $data;
    }

    /** 验证 Detail 只属于目标 Beat，并且 Handoff 精确指向相邻 Main Beat。 */
    private function validateBeatDetail(array $data, string $beatKey, ?string $nextBeatKey): array
    {
        Validator::make($data, [
            'beat_key' => ['required', 'string', 'regex:/^beat-[0-9]{2,}$/'],
            'milestones' => ['required', 'array', 'min:1'],
            'milestones.*.key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'distinct:strict'],
            'milestones.*.title' => ['required', 'string'], 'milestones.*.objective' => ['required', 'string'],
            'milestones.*.acceptance_criteria' => ['required', 'array', 'min:1'], 'milestones.*.acceptance_criteria.*' => ['string'],
            'milestones.*.must_include' => ['present', 'array'], 'milestones.*.must_include.*' => ['string'],
            'milestones.*.must_not_include' => ['present', 'array'], 'milestones.*.must_not_include.*' => ['string'],
            'handoff' => ['required', 'array'], 'handoff.next_beat_key' => ['present', 'nullable', 'string'],
            'handoff.transition_mode' => ['present', 'nullable', 'string'], 'handoff.exit_result' => ['present', 'nullable', 'string'],
            'handoff.next_trigger' => ['present', 'nullable', 'string'], 'handoff.carried_states' => ['present', 'array'],
            'handoff.open_threads' => ['present', 'array'], 'handoff.required_transition' => ['present', 'array'],
            'handoff.forbidden_jump' => ['present', 'array'],
        ])->validate();
        if (($data['beat_key'] ?? null) !== $beatKey) {
            throw ValidationException::withMessages(['beat_key' => 'Beat Detail 返回了非目标 Beat。']);
        }
        foreach ($data['milestones'] as $index => &$milestone) {
            $milestone['sequence'] = $index + 1;
        }
        unset($milestone);
        if (($data['handoff']['next_beat_key'] ?? null) !== $nextBeatKey) {
            throw ValidationException::withMessages(['handoff' => $nextBeatKey === null
                ? '最后一个 Main Beat 不能指向下一 Beat。'
                : "Handoff 必须指向相邻 Main Beat {$nextBeatKey}。"]);
        }
        if ($nextBeatKey !== null) {
            foreach (['transition_mode', 'exit_result', 'next_trigger'] as $field) {
                if (blank($data['handoff'][$field] ?? null)) {
                    throw ValidationException::withMessages(['handoff' => "非最终 Main Beat 缺少 {$field}。"]);
                }
            }
        }

        return $data;
    }

    /** 将 Skeleton 与逐 Beat Detail 合并为完整关系化 Outline DTO。 */
    private function mergeOutline(array $skeleton, array $details): array
    {
        foreach ($skeleton['volumes'] as &$volume) {
            foreach ($volume['arcs'] as &$arc) {
                foreach ($arc['beats'] as &$beat) {
                    if ($arc['type'] === 'main') {
                        $detail = $this->payload($details[(string) $beat['key']]);
                        $beat['milestones'] = $detail['milestones'];
                        $beat['handoff'] = $detail['handoff'];
                    } else {
                        $beat['milestones'] = [];
                        $beat['handoff'] = $this->emptyHandoff();
                    }
                    foreach ($beat['character_candidates'] as &$candidate) {
                        $candidate['possible_duplicate_character_ids'] = [];
                    }
                    unset($candidate);
                    foreach ($beat['world_entity_candidates'] as &$candidate) {
                        $candidate['possible_duplicate_entity_ids'] = [];
                    }
                    unset($candidate);
                }
                unset($beat);
            }
            unset($arc);
        }
        unset($volume);

        return $skeleton;
    }

    /** Finalize 时确认 Foundation 的稳定 Arc Key 都能在完整 Outline 中解析。 */
    private function assertFoundationReferences(array $foundation, array $outline): void
    {
        $arcKeys = collect($outline['volumes'])->flatMap(fn (array $volume): array => $volume['arcs'])->pluck('key');
        if (collect($foundation['foreshadowings'])->contains(
            fn (array $item): bool => filled($item['owner_arc_key'] ?? null) && ! $arcKeys->contains($item['owner_arc_key'])
        )) {
            throw ValidationException::withMessages(['outline' => 'Foundation 伏笔引用了 Skeleton 中不存在的 Arc Key。']);
        }
    }

    /** 拒绝跨小说、跨批次、类型错误或内容被篡改的阶段 Artifact。 */
    private function assertStageArtifact(
        GenerationRun $batch,
        GenerationArtifact $artifact,
        ArtifactType $type,
        string $scopeType,
        ?string $discriminator = null,
        ?string $inputHash = null,
    ): void {
        $run = $artifact->generationRun;
        $data = $artifact->data;
        if ($artifact->type !== $type
            || $run->novel_id !== $batch->novel_id
            || $run->scope_type !== $scopeType
            || $run->scope_id !== $batch->getKey()
            || $run->status !== RunStatus::Succeeded
            || data_get($data, 'batch_run_id') !== $batch->getKey()
            || data_get($data, 'stage') !== $scopeType
            || data_get($data, 'discriminator') !== $discriminator
            || ($inputHash !== null && $run->input_hash !== $inputHash)
            || ! hash_equals($artifact->checksum, $this->hash($data))) {
            throw ValidationException::withMessages(['outline' => 'Outline 阶段 Artifact 的来源、输入指纹、类型或 Checksum 不一致。']);
        }
    }

    /** 确认调用目标是复用 ChapterPlanning 枚举的 Outline 主批次。 */
    private function assertBatch(GenerationRun $batch): void
    {
        if ($batch->scope_type !== self::BATCH_SCOPE || $batch->stage !== GenerationStage::ChapterPlanning) {
            throw ValidationException::withMessages(['run' => '指定 Run 不是 Novel Outline 主批次。']);
        }
    }

    /** 初始规划只能发生在尚未进入正式故事生命周期的 Novel。 */
    public function assertNovelPlanningAvailable(Novel $novel): void
    {
        if ($novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能创建或激活 Outline 规划批次。', false);
        }
        if (! in_array($novel->status, [NovelStatus::Draft, NovelStatus::Planning], true)) {
            throw new AiProviderException('novel_planning_unavailable', '只有草稿或规划中的小说可以生成初始规划。', false);
        }
        if ($novel->current_outline_id !== null || $novel->chapters()->exists() || $novel->storyEvents()->exists()) {
            throw new AiProviderException('novel_planning_unavailable', '小说已经进入正式生命周期，不能创建新的初始规划批次。', false);
        }
    }

    /** 在每个新阶段开始前重新检查批次状态与暂停门禁。 */
    private function assertRunnableBatch(GenerationRun $batch): void
    {
        $batch->refresh();
        $this->assertBatch($batch);
        if ($batch->status === RunStatus::Succeeded && $this->artifact($batch, ArtifactType::OutlineBlueprint) !== null) {
            return;
        }
        if ($batch->status !== RunStatus::Running) {
            throw ValidationException::withMessages(['run' => 'Novel Outline 主批次当前不可执行。']);
        }
        $novelStatus = $batch->novel()->value('status');
        if ($novelStatus === NovelStatus::Paused || $novelStatus === NovelStatus::Paused->value) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能创建新的 Outline 阶段。', false);
        }
    }

    /** 新旧批次只能进入各自冻结的阶段图。 */
    public function assertLegacyBatch(GenerationRun $batch): void
    {
        $this->assertBatch($batch);
        if (! $this->isLegacyBatch($batch)) {
            throw ValidationException::withMessages(['run' => '旧 Skeleton Job 不能处理新版 Outline 批次。']);
        }
    }

    private function assertCurrentBatch(GenerationRun $batch): void
    {
        if ($batch->prompt_version !== self::BATCH_PROMPT_VERSION) {
            throw ValidationException::withMessages(['run' => 'Outline 主批次版本与新版阶段图不兼容。']);
        }
    }

    private function isLegacyBatch(GenerationRun $batch): bool
    {
        return $batch->prompt_version === self::LEGACY_BATCH_PROMPT_VERSION;
    }

    /** 旧 v2 批次仅用于消化已存在的 Queue Payload，不会读取新版阶段 Artifact。 */
    private function dispatchLegacyNext(GenerationRun $batch): void
    {
        $skeleton = $this->artifact($batch, ArtifactType::OutlineSkeleton);
        if ($skeleton === null) {
            GenerateNovelOutlineSkeletonJob::dispatch($batch->getKey());

            return;
        }
        foreach ($this->mainBeats($this->payload($skeleton)) as $beat) {
            if ($this->beatDetailArtifact($batch, (string) $beat['key']) === null) {
                GenerateNovelBeatDetailJob::dispatch($batch->getKey(), (string) $beat['key']);

                return;
            }
        }
        if ($this->artifact($batch, ArtifactType::OutlineBlueprint) === null) {
            FinalizeNovelOutlineJob::dispatch($batch->getKey());
        }
    }

    /** 子阶段只能使用主 Run 已冻结的 Provider 与 Model。 */
    private function assertFrozenRoute(GenerationRun $batch): void
    {
        if (blank($batch->provider) || blank($batch->model_policy)) {
            throw new AiProviderException('provider_run_route_missing', 'Outline 主批次缺少冻结的 Provider 或 Model。', false);
        }
    }

    /** 读取前置阶段 Artifact，缺失时立即停止后续阶段。 */
    private function requiredArtifact(GenerationRun $batch, ArtifactType $type): GenerationArtifact
    {
        return $this->artifact($batch, $type)
            ?? throw ValidationException::withMessages(['outline' => "缺少 {$type->value} Artifact。"]);
    }

    /** 查找当前批次指定类型的最新成功 Artifact。 */
    private function artifact(GenerationRun $batch, ArtifactType $type): ?GenerationArtifact
    {
        if ($type === ArtifactType::OutlineSkeleton && ! $this->isLegacyBatch($batch)) {
            return $this->currentSkeletonArtifact($batch);
        }

        $scope = match ($type) {
            ArtifactType::OutlineFoundation => self::FOUNDATION_SCOPE,
            ArtifactType::OutlineStructure => self::STRUCTURE_SCOPE,
            ArtifactType::OutlineSkeleton => self::SKELETON_SCOPE,
            ArtifactType::OutlineBlueprint => self::FINALIZE_SCOPE,
            default => null,
        };
        if ($scope === null) {
            return null;
        }

        return GenerationArtifact::query()
            ->where('type', $type)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('novel_id', $batch->novel_id)
                ->where('scope_type', $scope)
                ->where('scope_id', $batch->getKey())
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->first();
    }

    /** 只选择与当前 Structure 和全部 Arc Beats 来源链一致的确定性 Skeleton。 */
    private function currentSkeletonArtifact(GenerationRun $batch): ?GenerationArtifact
    {
        $foundation = $this->artifact($batch, ArtifactType::OutlineFoundation);
        $structure = $this->currentStructureArtifact($batch);
        if ($foundation === null || $structure === null) {
            return null;
        }
        $arcArtifacts = [];
        foreach ($this->orderedArcs($this->payload($structure)) as $arc) {
            $arcKey = (string) $arc['key'];
            $arcArtifact = $this->arcBeatsArtifact($batch, $arcKey);
            if ($arcArtifact === null) {
                return null;
            }
            $arcArtifacts[$arcKey] = $arcArtifact;
        }
        $inputHash = $this->hash([
            'algorithm' => self::SKELETON_ASSEMBLY_VERSION,
            'sources' => [
                'foundation' => $this->artifactReference($foundation),
                'structure' => $this->artifactReference($structure),
                'arc_beats' => collect($arcArtifacts)->map(fn (GenerationArtifact $artifact): array => $this->artifactReference($artifact))->all(),
            ],
        ]);

        return GenerationArtifact::query()
            ->where('type', ArtifactType::OutlineSkeleton)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('novel_id', $batch->novel_id)
                ->where('scope_type', self::SKELETON_ASSEMBLY_SCOPE)
                ->where('scope_id', $batch->getKey())
                ->where('input_hash', $inputHash)
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->first();
    }

    private function requiredCurrentStructure(GenerationRun $batch): GenerationArtifact
    {
        return $this->currentStructureArtifact($batch)
            ?? throw ValidationException::withMessages(['outline' => '缺少当前输入对应的 outline_structure Artifact。']);
    }

    /** 只选择与当前 Foundation、容量和 Prompt 指纹完全一致的 Structure。 */
    private function currentStructureArtifact(GenerationRun $batch): ?GenerationArtifact
    {
        $foundation = $this->artifact($batch, ArtifactType::OutlineFoundation);
        if ($foundation === null) {
            return null;
        }

        return GenerationArtifact::query()
            ->where('type', ArtifactType::OutlineStructure)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('novel_id', $batch->novel_id)
                ->where('scope_type', self::STRUCTURE_SCOPE)
                ->where('scope_id', $batch->getKey())
                ->where('input_hash', $this->structureInputHash($batch, $foundation))
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->first();
    }

    /** 按 Arc Key 与当前 Foundation/Structure 输入指纹选择成功 Artifact。 */
    private function arcBeatsArtifact(GenerationRun $batch, string $arcKey): ?GenerationArtifact
    {
        $foundation = $this->artifact($batch, ArtifactType::OutlineFoundation);
        $structure = $this->currentStructureArtifact($batch);
        if ($foundation === null || $structure === null) {
            return null;
        }

        return GenerationArtifact::query()
            ->where('type', ArtifactType::OutlineArcBeats)
            ->where('data->discriminator', $arcKey)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('novel_id', $batch->novel_id)
                ->where('scope_type', self::ARC_BEATS_SCOPE)
                ->where('scope_id', $batch->getKey())
                ->where('input_hash', $this->arcBeatsInputHash($batch, $foundation, $structure, $arcKey))
                ->where('context_snapshot->discriminator', $arcKey)
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->first();
    }

    /** 按稳定 Beat Key 查找当前批次的成功 Detail Artifact。 */
    private function beatDetailArtifact(GenerationRun $batch, string $beatKey): ?GenerationArtifact
    {
        $foundation = $this->artifact($batch, ArtifactType::OutlineFoundation);
        $skeleton = $this->artifact($batch, ArtifactType::OutlineSkeleton);
        if ($foundation === null || $skeleton === null) {
            return null;
        }

        return GenerationArtifact::query()
            ->where('type', ArtifactType::OutlineBeatDetail)
            ->where('data->discriminator', $beatKey)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('novel_id', $batch->novel_id)
                ->where('scope_type', self::BEAT_DETAIL_SCOPE)
                ->where('scope_id', $batch->getKey())
                ->where('input_hash', $this->beatDetailInputHash($batch, $foundation, $skeleton, $beatKey))
                ->where('context_snapshot->discriminator', $beatKey)
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->first();
    }

    /** @return array{0: array<string, mixed>, 1: string|null} */
    private function beatDetailContext(
        GenerationArtifact $foundation,
        GenerationArtifact $skeleton,
        string $beatKey,
    ): array {
        $beats = $this->mainBeats($this->payload($skeleton));
        $index = array_search($beatKey, array_column($beats, 'key'), true);
        if ($index === false) {
            throw ValidationException::withMessages(['beat_key' => "Main Beat {$beatKey} 不属于当前 Skeleton。"]);
        }
        $target = $beats[$index];
        $previous = $index > 0 ? $beats[$index - 1] : null;
        $next = $beats[$index + 1] ?? null;

        return [[
            'outline' => collect($this->payload($skeleton))->except('volumes')->all(),
            'foundation_summary' => [
                'logline' => data_get($this->payload($foundation), 'bible.logline'),
                'themes' => data_get($this->payload($foundation), 'bible.themes'),
                'ending_contract' => data_get($this->payload($foundation), 'bible.ending_contract'),
            ],
            'target_beat' => $target,
            'previous_main_beat' => $previous === null ? null : collect($previous)->only(['key', 'title', 'summary', 'acceptance_criteria'])->all(),
            'next_main_beat' => $next === null ? null : collect($next)->only(['key', 'title', 'summary', 'acceptance_criteria'])->all(),
            'expected_next_beat_key' => $next['key'] ?? null,
            'source_artifacts' => [$this->artifactReference($foundation), $this->artifactReference($skeleton)],
        ], $next['key'] ?? null];
    }

    private function beatDetailInputHash(
        GenerationRun $batch,
        GenerationArtifact $foundation,
        GenerationArtifact $skeleton,
        string $beatKey,
    ): string {
        [$context] = $this->beatDetailContext($foundation, $skeleton, $beatKey);

        return $this->providerInputHash($batch, $context, self::BEAT_DETAIL_PROMPT_VERSION);
    }

    /** 统一限定子 Run 的小说、固定 Scope、主 Run ID 与阶段枚举。 */
    private function stageRuns(GenerationRun $batch, string $scopeType)
    {
        return GenerationRun::query()
            ->where('novel_id', $batch->novel_id)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $batch->getKey())
            ->where('stage', GenerationStage::ChapterPlanning);
    }

    /** 从不可变 Artifact 解包阶段 Payload，并拒绝缺失数据。 */
    private function payload(GenerationArtifact $artifact): array
    {
        $payload = data_get($artifact->data, 'payload');
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['outline' => "Artifact {$artifact->getKey()} 缺少阶段 Payload。"]);
        }

        return $payload;
    }

    /** 输出 Finalize 与 Apply 都能复核的最小来源引用。 */
    private function artifactReference(GenerationArtifact $artifact): array
    {
        return ['id' => $artifact->getKey(), 'checksum' => $artifact->checksum, 'type' => $artifact->type->value];
    }

    /** 按 mainline_sequence 返回需要逐个细化的 Main Beat。 */
    private function mainBeats(array $skeleton): array
    {
        $beats = collect($skeleton['volumes'] ?? [])->flatMap(fn (array $volume) => collect($volume['arcs'] ?? [])
            ->where('type', 'main')
            ->flatMap(fn (array $arc): array => $arc['beats'] ?? []))
            ->sortBy('mainline_sequence')
            ->values()
            ->all();

        return $beats;
    }

    /** Structure 数组顺序是 Arc 串行生成与 Assembly 的唯一顺序来源。 */
    private function orderedArcs(array $structure): array
    {
        return collect($structure['volumes'] ?? [])
            ->flatMap(fn (array $volume): array => $volume['arcs'] ?? [])
            ->values()
            ->all();
    }

    /** Laravel 从数组顺序重建同级 Sequence，模型不拥有排序权。 */
    private function normalizeSkeletonSequences(array $data): array
    {
        $mainArcSequence = 0;
        $mainBeatSequence = 0;
        foreach ($data['volumes'] as $volumeIndex => &$volume) {
            $volume['sequence'] = $volumeIndex + 1;
            foreach ($volume['arcs'] as $arcIndex => &$arc) {
                $arc['sequence'] = $arcIndex + 1;
                $isMain = ($arc['type'] ?? null) === 'main';
                $arc['mainline_sequence'] = $isMain ? ++$mainArcSequence : null;
                foreach ($arc['beats'] as $beatIndex => &$beat) {
                    $beat['sequence'] = $beatIndex + 1;
                    $beat['mainline_sequence'] = $isMain ? ++$mainBeatSequence : null;
                }
                unset($beat);
            }
            unset($arc);
        }
        unset($volume);

        return $data;
    }

    /** 拒绝无效或在整棵 Skeleton 内重复的稳定 Key。 */
    private function assertUniqueStableKey(string $key, array &$keys, string $type): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $key) || isset($keys[$key])) {
            throw ValidationException::withMessages(['outline' => "{$type} Key 无效或重复：{$key}"]);
        }
        $keys[$key] = true;
    }

    /** 在本地复核 strict object 的 required 与额外字段边界。 */
    private function assertSchemaShape(mixed $value, array $schema, string $path = '$'): void
    {
        $type = $schema['type'] ?? null;
        if ($type === 'object' && is_array($value)) {
            $allowed = array_keys($schema['properties'] ?? []);
            $extra = array_diff(array_keys($value), $allowed);
            $missing = array_diff($schema['required'] ?? [], array_keys($value));
            if ($extra !== [] || $missing !== []) {
                throw ValidationException::withMessages([$path => 'Structured Output 字段与严格 Schema 不一致。']);
            }
            foreach ($schema['properties'] as $key => $child) {
                $this->assertSchemaShape($value[$key], $child, $path.'.'.$key);
            }

            return;
        }
        if ($type === 'array' && is_array($value) && is_array($schema['items'] ?? null)) {
            foreach ($value as $index => $item) {
                $this->assertSchemaShape($item, $schema['items'], $path.'.'.$index);
            }
        }
    }

    /** 构造 required/property 完全对齐的 Strict Structured Output 对象。 */
    private function object(array $properties): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    }

    /** 构造与当前叙事配置枚举一致的完整文风 Schema。 */
    private function styleProfileSchema(?string $targetPlatform = null): array
    {
        $parameters = collect(config('narrative.parameter_keys', []))->mapWithKeys(fn (string $key): array => [$key => [
            'type' => 'integer', 'minimum' => 1, 'maximum' => 5,
        ]])->all();

        return $this->object([
            'subgenre' => ['type' => ['string', 'null']],
            'target_platform' => ['type' => 'string', 'enum' => $targetPlatform === null ? array_keys(config('narrative.platforms', [])) : [$targetPlatform]],
            'primary_style' => ['type' => 'string', 'enum' => array_keys(config('narrative.styles', []))],
            'secondary_styles' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string', 'enum' => array_keys(config('narrative.styles', []))]],
            'language_era' => ['type' => 'string', 'enum' => array_keys(config('narrative.language_eras', []))],
            'pacing' => ['type' => 'string', 'enum' => array_keys(config('narrative.paces', []))],
            'parameters' => $this->object($parameters),
        ]);
    }

    /** @return array{provider: string, model: string, context_window_tokens: int, max_output_tokens: int} */
    private function configuredPlannerCapacity(string $provider, string $model): array
    {
        $capacity = config('generation.outline_planner_capacity', []);
        if (($capacity['provider'] ?? null) !== $provider || ($capacity['model'] ?? null) !== $model) {
            throw ValidationException::withMessages([
                'capacity' => "Planner 路由 {$provider}/{$model} 缺少匹配的已核实模型容量配置。",
            ]);
        }
        $contextWindow = (int) ($capacity['context_window_tokens'] ?? 0);
        $maxOutput = (int) ($capacity['max_output_tokens'] ?? 0);
        if ($contextWindow < 1 || $maxOutput < 1) {
            throw ValidationException::withMessages(['capacity' => 'Planner 模型容量配置必须为正整数。']);
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'context_window_tokens' => $contextWindow,
            'max_output_tokens' => $maxOutput,
        ];
    }

    /** @return array<string, int> */
    private function capacitySnapshot(GenerationRun $batch, array $requestInput, int $requestedOutputTokens): array
    {
        $capacity = data_get($batch->context_snapshot, 'generation_preferences.model_capacity');
        if (! is_array($capacity)
            || ($capacity['provider'] ?? null) !== $batch->provider
            || ($capacity['model'] ?? null) !== $batch->model_policy) {
            throw ValidationException::withMessages(['capacity' => 'Outline 主批次缺少与冻结路由匹配的模型容量。']);
        }

        return $this->stageContract->capacitySnapshot(
            $requestInput,
            $requestedOutputTokens,
            (int) ($capacity['context_window_tokens'] ?? 0),
            (int) ($capacity['max_output_tokens'] ?? 0),
        );
    }

    private function providerInputHash(GenerationRun $batch, array $context, string $promptVersion): string
    {
        return $this->hash([
            'context' => $context,
            'provider' => $batch->provider,
            'model' => $batch->model_policy,
            'reasoning_effort' => data_get($batch->context_snapshot, 'generation_preferences.reasoning_effort'),
            'prompt_version' => $promptVersion,
        ]);
    }

    private function structureInputHash(GenerationRun $batch, GenerationArtifact $foundation): string
    {
        $volumeCount = (int) data_get($batch->context_snapshot, 'requested_volume_count');
        $context = [
            'novel' => data_get($batch->context_snapshot, 'novel'),
            'requested_volume_count' => $volumeCount,
            'foundation' => $this->payload($foundation),
            'foundation_artifact' => $this->artifactReference($foundation),
        ];
        $schema = $this->stageContract->structureSchema($volumeCount);
        $systemPrompt = '你是 XNovel 全书 Structure 规划器。只返回严格 JSON。只生成全书、Volume 与 Arc 结构，不得返回 Beat、Milestone、Handoff、sequence、mainline_sequence 或数据库 ID。稳定 Key 必须全局唯一。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning();
        $prompt = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $capacity = $this->capacitySnapshot($batch, compact('systemPrompt', 'prompt', 'schema'), NovelOutlineStageContract::STRUCTURE_MAX_OUTPUT_TOKENS);

        return $this->providerInputHash($batch, [...$context, 'capacity_snapshot' => $capacity], NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION);
    }

    private function arcBeatsInputHash(
        GenerationRun $batch,
        GenerationArtifact $foundation,
        GenerationArtifact $structure,
        string $arcKey,
    ): string {
        $context = $this->stageContract->arcBeatsContext($this->payload($foundation), $this->payload($structure), $arcKey) + [
            'source_artifacts' => [$this->artifactReference($foundation), $this->artifactReference($structure)],
        ];
        $schema = $this->stageContract->arcBeatsSchema();
        $systemPrompt = '你是 XNovel 单 Arc Beats 规划器。只返回严格 JSON。只生成目标 Arc 的 Beats、预算、验收条件和候选；不得返回其他 Arc、Volume、Milestone、Handoff、sequence、mainline_sequence 或数据库 ID。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning();
        $prompt = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $capacity = $this->capacitySnapshot($batch, compact('systemPrompt', 'prompt', 'schema'), NovelOutlineStageContract::ARC_BEATS_MAX_OUTPUT_TOKENS);

        return $this->providerInputHash($batch, [...$context, 'capacity_snapshot' => $capacity], NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION);
    }

    /** @return array{code: string, label: string, source: string} */
    private function frozenTargetPlatform(GenerationRun $batch): array
    {
        $selection = data_get($batch->context_snapshot, 'target_platform');
        $code = is_array($selection) ? ($selection['code'] ?? null) : null;

        if (! is_string($code) || ! array_key_exists($code, config('narrative.platforms', []))) {
            throw ValidationException::withMessages([
                'narrative.default_platform' => 'Outline 批次缺少有效的冻结目标平台，不能调用 AI Provider。',
            ]);
        }

        return [
            'code' => $code,
            'label' => is_string($selection['label'] ?? null) ? $selection['label'] : config("narrative.platforms.{$code}"),
            'source' => is_string($selection['source'] ?? null) ? $selection['source'] : 'default_config',
        ];
    }

    /** Subplot 与最终 Main Beat 使用显式空 Handoff，而不是 null 占位对象。 */
    private function emptyHandoff(): array
    {
        return [
            'next_beat_key' => null, 'transition_mode' => null, 'exit_result' => null, 'next_trigger' => null,
            'carried_states' => [], 'open_threads' => [], 'required_transition' => [], 'forbidden_jump' => [],
        ];
    }

    /** 对稳定 JSON 表示计算阶段输入或 Artifact Checksum。 */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
