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
use App\Models\AIModelPrice;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public const SINGLE_ROUTE_BATCH_PROMPT_VERSION = 'novel-outline-pipeline-v3';

    public const BATCH_PROMPT_VERSION = 'novel-outline-pipeline-v4';

    public const FOUNDATION_PROMPT_VERSION = 'novel-outline-foundation-v2';

    public const SKELETON_PROMPT_VERSION = 'novel-outline-skeleton-v1';

    public const BEAT_DETAIL_PROMPT_VERSION = 'novel-outline-beat-detail-v2';

    public const FINALIZE_PROMPT_VERSION = 'novel-outline-finalize-v1';

    public const SKELETON_ASSEMBLY_VERSION = 'novel-outline-skeleton-assembly-v2';

    public const SKELETON_MAX_TOKENS = 12_000;

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

            $targetPlatform = $this->targetPlatformResolver->forNovel($locked);
            $outlineRoutes = $this->resolveOutlineRoutes($locked);
            $context = [
                'novel' => $locked->only(['id', 'title', 'genre', 'premise', 'target_words']),
                'requested_volume_count' => $volumeCount,
                'target_platform' => $targetPlatform,
                'generation_preferences' => [
                    'chapter_target_words' => (int) data_get($locked->settings, 'generation.chapter_target_words', 3_000),
                    'outline_routes' => $outlineRoutes,
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
                'provider' => null,
                'model_policy' => null,
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

    /** @return array<string, array<string, mixed>> */
    public function outlineRoutePreview(Novel $novel): array
    {
        $preview = [];
        foreach ($this->outlineRouteStages() as $stage) {
            try {
                // 预览与创建 Batch 共用同一解析入口，并且每个 Stage 只解析一次，避免展示值前后漂移。
                $route = $this->resolveOutlineRoute($novel, $stage);
                $preview[$stage->value] = [
                    'stage' => $stage->value,
                    'label' => $stage->getLabel(),
                    'ready' => true,
                    ...$route,
                    'error_code' => null,
                    'error' => null,
                ];
            } catch (Throwable $exception) {
                $message = $exception instanceof ValidationException
                    ? (string) collect($exception->errors())->flatten()->first()
                    : ($exception instanceof AiProviderException
                        ? $exception->getMessage()
                        : '路由预检失败，请查看日志后重试。');
                $errorCode = $exception instanceof AiProviderException
                    ? $exception->errorCode
                    : ($exception instanceof ValidationException ? 'outline_route_validation_failed' : 'outline_route_preview_failed');
                if (! $exception instanceof ValidationException && ! $exception instanceof AiProviderException) {
                    // 未知异常不能作为普通配置错误静默吞掉，同时日志不记录 Provider 凭据或完整请求内容。
                    Log::warning('Outline route preview failed unexpectedly.', [
                        'novel_id' => $novel->getKey(),
                        'stage' => $stage->value,
                        'exception' => $exception::class,
                    ]);
                }
                $preview[$stage->value] = [
                    'stage' => $stage->value,
                    'label' => $stage->getLabel(),
                    'ready' => false,
                    'provider' => null,
                    'model' => null,
                    'reasoning_effort' => null,
                    'source' => null,
                    'prompt_version' => $this->promptVersionForRoute($stage),
                    'request_budget' => null,
                    'model_capacity' => null,
                    'error_code' => $errorCode,
                    'error' => $message,
                ];
            }
        }

        return $preview;
    }

    /** Resume 只接受当前 Pipeline 合同创建且冻结信息完整的主批次。 */
    public function assertResumeCompatible(GenerationRun $batch): void
    {
        $this->assertBatch($batch);
        if (! in_array($batch->prompt_version, [self::SINGLE_ROUTE_BATCH_PROMPT_VERSION, self::BATCH_PROMPT_VERSION], true)
            || data_get($batch->context_snapshot, 'generation_preferences.prompt_versions') !== $this->promptVersions()) {
            throw ValidationException::withMessages(['run' => 'Outline 主批次版本与当前 Pipeline 合同不兼容。']);
        }
        if ($batch->prompt_version === self::BATCH_PROMPT_VERSION) {
            foreach ($this->outlineRouteStages() as $stage) {
                $this->frozenRoute($batch, $stage);
            }
        } elseif (blank($batch->provider) || blank($batch->model_policy)) {
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
            routeStage: AiStage::OutlineFoundation,
            scopeType: self::FOUNDATION_SCOPE,
            artifactType: ArtifactType::OutlineFoundation,
            promptVersion: self::FOUNDATION_PROMPT_VERSION,
            context: $context,
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
            routeStage: AiStage::Planner,
            scopeType: self::SKELETON_SCOPE,
            artifactType: ArtifactType::OutlineSkeleton,
            promptVersion: self::SKELETON_PROMPT_VERSION,
            context: $context,
            systemPrompt: '你是 XNovel 全书 Outline Skeleton 规划器。只返回严格 JSON。生成 Volume、Arc、Beat 骨架和 Beat 级约束，不得生成 Milestone、Handoff 或数据库 ID。稳定 Key 必须全局唯一；Laravel 决定顺序和后续引用。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning(),
            prompt: json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            schema: $this->skeletonSchema($volumeCount),
            outputName: 'novel_outline_skeleton',
            validate: fn (array $data): array => $this->validateSkeleton($data, $volumeCount),
            sourceArtifacts: [$foundation],
            legacyMaxTokens: self::SKELETON_MAX_TOKENS,
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

        return $this->providerStage(
            batch: $batch,
            routeStage: AiStage::OutlineStructure,
            scopeType: self::STRUCTURE_SCOPE,
            artifactType: ArtifactType::OutlineStructure,
            promptVersion: NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION,
            context: $context,
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
        $systemPrompt = '你是 XNovel 单 Arc Beats 规划器。只返回严格 JSON。只生成目标 Arc 的 Beats、预算、验收条件和候选；不得返回其他 Arc、Volume、Milestone、Handoff、Beat Key、Candidate Key、sequence、mainline_sequence 或数据库 ID。Beat 与 Candidate 的稳定 Key 由 Laravel 在合并时统一分配。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning();
        $prompt = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $this->providerStage(
            batch: $batch,
            routeStage: AiStage::OutlineArcBeats,
            scopeType: self::ARC_BEATS_SCOPE,
            artifactType: ArtifactType::OutlineArcBeats,
            promptVersion: NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION,
            context: $context,
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
            routeStage: AiStage::OutlineBeatDetail,
            scopeType: self::BEAT_DETAIL_SCOPE,
            artifactType: ArtifactType::OutlineBeatDetail,
            promptVersion: self::BEAT_DETAIL_PROMPT_VERSION,
            context: $context,
            systemPrompt: '你是 XNovel 单 Main Beat 细化器。只返回目标 Beat 的 Milestones 和出站 Handoff 内容；不得返回 Beat Key、Milestone Key、next_beat_key、sequence、其他 Beat、完整 Outline 或任何数据库 ID。稳定 Key、顺序和相邻下一 Main Beat 由 Laravel 统一分配。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning(),
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
            'provider' => null,
            'model_policy' => null,
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
        $strings = $this->boundedStringList();
        $currentState = $this->object(['location' => $this->nullableShortText(), 'summary' => $this->boundedText()]);

        return $this->object([
            'bible' => $this->object([
                'logline' => $this->boundedText(), 'themes' => $strings, 'tone' => $this->boundedShortText(), 'pov' => $this->boundedShortText(), 'tense' => $this->boundedShortText(),
                'taboos' => $strings, 'hard_constraints' => $strings,
                'ending_contract' => $this->object([
                    'final_protagonist_state' => $this->boundedText(), 'main_conflict_resolution' => $this->boundedText(), 'theme_payoff' => $this->boundedText(),
                    'required_foreshadowing_payoff' => $strings, 'character_arc_requirements' => $strings, 'allowed_open_endings' => $strings,
                ]),
                'style_profile' => $this->styleProfileSchema($targetPlatform),
            ]),
            'characters' => ['type' => 'array', 'maxItems' => NovelOutlineStageContract::MAX_FOUNDATION_CHARACTERS, 'items' => $this->object([
                'name' => $this->boundedShortText(), 'role' => ['type' => 'string', 'enum' => ['主角', '配角', '反派'], 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH], 'motivation' => $this->boundedText(),
                'profile' => $strings, 'personality' => $strings, 'abilities' => $strings, 'knowledge' => $strings, 'current_state' => $currentState,
            ])],
            'world_entities' => ['type' => 'array', 'maxItems' => NovelOutlineStageContract::MAX_FOUNDATION_WORLD_ENTITIES, 'items' => $this->object([
                'type' => ['type' => 'string', 'enum' => ['location', 'item', 'faction', 'organization', 'rule', 'concept'], 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH], 'name' => $this->boundedShortText(),
                'description' => $this->boundedText(), 'attributes' => $strings, 'rules' => $strings, 'current_state' => $strings,
            ])],
            'foreshadowings' => ['type' => 'array', 'maxItems' => NovelOutlineStageContract::MAX_FOUNDATION_FORESHADOWINGS, 'items' => $this->object([
                'title' => $this->boundedShortText(), 'description' => $this->boundedText(), 'promised_payoff' => $this->boundedText(),
                'due_from_chapter' => ['type' => 'integer', 'minimum' => 1], 'due_to_chapter' => ['type' => 'integer', 'minimum' => 1],
                'importance' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical'], 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH], 'owner_arc_key' => ['type' => ['string', 'null'], 'maxLength' => 64],
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
        $strings = $this->boundedStringList();
        $milestone = $this->object([
            'title' => $this->boundedShortText(), 'objective' => $this->boundedText(),
            'acceptance_criteria' => $this->boundedStringList(minItems: 1), 'must_include' => $strings, 'must_not_include' => $strings,
        ]);

        return $this->object([
            'milestones' => ['type' => 'array', 'minItems' => 1, 'maxItems' => NovelOutlineStageContract::MAX_MILESTONES_PER_BEAT, 'items' => $milestone],
            'handoff' => $this->object([
                'transition_mode' => $this->nullableShortText(),
                'exit_result' => $this->nullableText(), 'next_trigger' => $this->nullableText(),
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
        AiStage $routeStage,
        string $scopeType,
        ArtifactType $artifactType,
        string $promptVersion,
        array $context,
        string $systemPrompt,
        string $prompt,
        array $schema,
        string $outputName,
        callable $validate,
        array $sourceArtifacts = [],
        ?string $discriminator = null,
        ?int $legacyMaxTokens = null,
    ): ?GenerationArtifact {
        $route = $this->frozenRoute($batch, $routeStage);
        if ($route['prompt_version'] !== $promptVersion) {
            throw new AiProviderException('provider_run_route_missing', "Outline 路由 {$routeStage->value} 的 Prompt 版本与阶段合同不一致。", false);
        }
        $requestContract = $this->providerRequestContract(
            $batch,
            $routeStage,
            $route,
            $context,
            $promptVersion,
            $systemPrompt,
            $prompt,
            $schema,
            $legacyMaxTokens,
        );
        $context = $requestContract['context'];
        $requestBudget = $requestContract['request_budget'];
        $maxTokens = $requestBudget['max_completion_tokens'];
        $inputHash = $requestContract['input_hash'];
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
            'provider' => $route['provider'],
            'model_policy' => $route['model'],
            'context_snapshot' => [
                'batch_run_id' => $batch->getKey(),
                'discriminator' => $discriminator,
                'source_artifacts' => array_map(fn (GenerationArtifact $item): array => $this->artifactReference($item), $sourceArtifacts),
                'input' => $context,
                'reasoning_effort' => $route['reasoning_effort'],
                'request_budget' => $requestBudget,
                'max_completion_tokens' => $maxTokens,
                'generation_preferences' => [
                    'substage_routes' => [
                        $routeStage->value => $route,
                    ],
                ],
            ],
            'started_at' => now(),
        ]);

        $failureCode = 'outline_stage_failed';
        try {
            $response = $this->provider->generate(new AiRequest(
                model: $route['model'],
                provider: $route['provider'],
                reasoningEffort: $route['reasoning_effort'],
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
                    'stage' => $routeStage->value,
                    'route_key' => $routeStage->value,
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
            'bible' => ['required', 'array'], 'bible.logline' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH], 'bible.themes' => ['required', 'array', 'min:1', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS],
            'bible.themes.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'bible.tone' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH], 'bible.pov' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH], 'bible.tense' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH],
            'bible.taboos' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'bible.taboos.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'bible.hard_constraints' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'bible.hard_constraints.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'bible.ending_contract' => ['required', 'array'],
            'bible.ending_contract.final_protagonist_state' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH],
            'bible.ending_contract.main_conflict_resolution' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH],
            'bible.ending_contract.theme_payoff' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH],
            'bible.ending_contract.required_foreshadowing_payoff' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS],
            'bible.ending_contract.required_foreshadowing_payoff.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'bible.ending_contract.character_arc_requirements' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS],
            'bible.ending_contract.character_arc_requirements.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'bible.ending_contract.allowed_open_endings' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS],
            'bible.ending_contract.allowed_open_endings.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'bible.style_profile' => ['required', 'array'],
            'bible.style_profile.subgenre' => ['present', 'nullable', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH],
            'bible.style_profile.target_platform' => ['required', Rule::in([$targetPlatform])],
            'bible.style_profile.primary_style' => ['required', Rule::in(array_keys(config('narrative.styles', [])))],
            'bible.style_profile.secondary_styles' => ['present', 'array', 'max:2'],
            'bible.style_profile.secondary_styles.*' => ['string', 'distinct:strict', Rule::in(array_keys(config('narrative.styles', [])))],
            'bible.style_profile.language_era' => ['required', Rule::in(array_keys(config('narrative.language_eras', [])))],
            'bible.style_profile.pacing' => ['required', Rule::in(array_keys(config('narrative.paces', [])))],
            'characters' => ['required', 'array', 'min:1', 'max:'.NovelOutlineStageContract::MAX_FOUNDATION_CHARACTERS],
            'characters.*.name' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH], 'characters.*.role' => ['required', Rule::in(['主角', '配角', '反派'])],
            'characters.*.motivation' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH], 'characters.*.profile' => ['required', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS],
            'characters.*.profile.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'characters.*.personality' => ['required', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'characters.*.personality.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'characters.*.abilities' => ['required', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'characters.*.abilities.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'characters.*.knowledge' => ['required', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'characters.*.knowledge.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'characters.*.current_state' => ['required', 'array'], 'characters.*.current_state.location' => ['present', 'nullable', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH],
            'characters.*.current_state.summary' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH],
            'world_entities' => ['required', 'array', 'min:1', 'max:'.NovelOutlineStageContract::MAX_FOUNDATION_WORLD_ENTITIES],
            'world_entities.*.type' => ['required', Rule::in(['location', 'item', 'faction', 'organization', 'rule', 'concept'])],
            'world_entities.*.name' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH], 'world_entities.*.description' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH],
            'world_entities.*.attributes' => ['required', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'world_entities.*.attributes.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'world_entities.*.rules' => ['required', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'world_entities.*.rules.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'world_entities.*.current_state' => ['required', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'world_entities.*.current_state.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'foreshadowings' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_FOUNDATION_FORESHADOWINGS], 'foreshadowings.*.title' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH],
            'foreshadowings.*.description' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH], 'foreshadowings.*.promised_payoff' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH],
            'foreshadowings.*.due_from_chapter' => ['required', 'integer', 'min:1'], 'foreshadowings.*.due_to_chapter' => ['required', 'integer', 'min:1'],
            'foreshadowings.*.importance' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'foreshadowings.*.owner_arc_key' => ['present', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
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
        // Provider 不再拥有任何跨请求稳定标识，先按 Strict Schema 拒绝旧 Key 或额外字段。
        $this->assertSchemaShape($data, $this->beatDetailSchema());
        Validator::make($data, [
            'milestones' => ['required', 'array', 'min:1', 'max:'.NovelOutlineStageContract::MAX_MILESTONES_PER_BEAT],
            'milestones.*.title' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH], 'milestones.*.objective' => ['required', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH],
            'milestones.*.acceptance_criteria' => ['required', 'array', 'min:1', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'milestones.*.acceptance_criteria.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'milestones.*.must_include' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'milestones.*.must_include.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'milestones.*.must_not_include' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'milestones.*.must_not_include.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'handoff' => ['required', 'array'],
            'handoff.transition_mode' => ['present', 'nullable', 'string', 'max:'.NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH], 'handoff.exit_result' => ['present', 'nullable', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH],
            'handoff.next_trigger' => ['present', 'nullable', 'string', 'max:'.NovelOutlineStageContract::MAX_TEXT_LENGTH], 'handoff.carried_states' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS],
            'handoff.carried_states.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'handoff.open_threads' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'handoff.open_threads.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'handoff.required_transition' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'handoff.required_transition.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
            'handoff.forbidden_jump' => ['present', 'array', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS], 'handoff.forbidden_jump.*' => ['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
        ])->validate();
        // 独立 Beat Detail 请求看不到其他 Detail，稳定标识必须由 Laravel 从目标 Beat 派生。
        $data['beat_key'] = $beatKey;
        foreach ($data['milestones'] as $index => &$milestone) {
            $milestone['key'] = $beatKey.'-milestone-'.sprintf('%02d', $index + 1);
            $milestone['sequence'] = $index + 1;
        }
        unset($milestone);
        $data['handoff']['next_beat_key'] = $nextBeatKey;
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
        if (! in_array($batch->prompt_version, [self::SINGLE_ROUTE_BATCH_PROMPT_VERSION, self::BATCH_PROMPT_VERSION], true)) {
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
        $schema = $this->beatDetailSchema();
        $systemPrompt = '你是 XNovel 单 Main Beat 细化器。只返回目标 Beat 的 Milestones 和出站 Handoff 内容；不得返回 Beat Key、Milestone Key、next_beat_key、sequence、其他 Beat、完整 Outline 或任何数据库 ID。稳定 Key、顺序和相邻下一 Main Beat 由 Laravel 统一分配。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning();
        $prompt = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $route = $this->frozenRoute($batch, AiStage::OutlineBeatDetail);

        return $this->providerRequestContract(
            $batch,
            AiStage::OutlineBeatDetail,
            $route,
            $context,
            self::BEAT_DETAIL_PROMPT_VERSION,
            $systemPrompt,
            $prompt,
            $schema,
        )['input_hash'];
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

    /** Laravel 从冻结结构和数组顺序重建全书稳定 Key 与 Sequence，模型不拥有全局身份和排序权。 */
    private function normalizeSkeletonSequences(array $data): array
    {
        $mainArcSequence = 0;
        $mainBeatSequence = 0;
        $beatSequence = 0;
        foreach ($data['volumes'] as $volumeIndex => &$volume) {
            $volume['sequence'] = $volumeIndex + 1;
            foreach ($volume['arcs'] as $arcIndex => &$arc) {
                $arc['sequence'] = $arcIndex + 1;
                $isMain = ($arc['type'] ?? null) === 'main';
                $arc['mainline_sequence'] = $isMain ? ++$mainArcSequence : null;
                foreach ($arc['beats'] as $beatIndex => &$beat) {
                    // 各 Arc Provider 相互隔离，按全书冻结顺序统一编号才能从根源上避免重复 beat-01。
                    $beat['key'] = 'beat-'.sprintf('%02d', ++$beatSequence);
                    $beat['sequence'] = $beatIndex + 1;
                    $beat['mainline_sequence'] = $isMain ? ++$mainBeatSequence : null;
                    foreach ($beat['character_candidates'] as $candidateIndex => &$candidate) {
                        $candidate['candidate_key'] = $beat['key'].'-character-'.sprintf('%02d', $candidateIndex + 1);
                    }
                    unset($candidate);
                    foreach ($beat['world_entity_candidates'] as $candidateIndex => &$candidate) {
                        $candidate['candidate_key'] = $beat['key'].'-world-'.sprintf('%02d', $candidateIndex + 1);
                    }
                    unset($candidate);
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

    /** @return array{type: string, maxLength: int} */
    private function boundedShortText(): array
    {
        return ['type' => 'string', 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH];
    }

    /** @return array{type: string, maxLength: int} */
    private function boundedText(): array
    {
        return ['type' => 'string', 'maxLength' => NovelOutlineStageContract::MAX_TEXT_LENGTH];
    }

    /** @return array{type: array<int, string>, maxLength: int} */
    private function nullableShortText(): array
    {
        return ['type' => ['string', 'null'], 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH];
    }

    /** @return array{type: array<int, string>, maxLength: int} */
    private function nullableText(): array
    {
        return ['type' => ['string', 'null'], 'maxLength' => NovelOutlineStageContract::MAX_TEXT_LENGTH];
    }

    /** @return array<string, mixed> */
    private function boundedStringList(int $minItems = 0): array
    {
        return array_filter([
            'type' => 'array',
            'minItems' => $minItems > 0 ? $minItems : null,
            'maxItems' => NovelOutlineStageContract::MAX_LIST_ITEMS,
            'items' => ['type' => 'string', 'maxLength' => NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** 构造与当前叙事配置枚举一致的完整文风 Schema。 */
    private function styleProfileSchema(?string $targetPlatform = null): array
    {
        $parameters = collect(config('narrative.parameter_keys', []))->mapWithKeys(fn (string $key): array => [$key => [
            'type' => 'integer', 'minimum' => 1, 'maximum' => 5,
        ]])->all();

        return $this->object([
            'subgenre' => $this->nullableShortText(),
            'target_platform' => ['type' => 'string', 'enum' => $targetPlatform === null ? array_keys(config('narrative.platforms', [])) : [$targetPlatform], 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH],
            'primary_style' => ['type' => 'string', 'enum' => array_keys(config('narrative.styles', [])), 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH],
            'secondary_styles' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string', 'enum' => array_keys(config('narrative.styles', [])), 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH]],
            'language_era' => ['type' => 'string', 'enum' => array_keys(config('narrative.language_eras', [])), 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH],
            'pacing' => ['type' => 'string', 'enum' => array_keys(config('narrative.paces', [])), 'maxLength' => NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH],
            'parameters' => $this->object($parameters),
        ]);
    }

    /** @return array<int, AiStage> */
    private function outlineRouteStages(): array
    {
        return [
            AiStage::OutlineFoundation,
            AiStage::OutlineStructure,
            AiStage::OutlineArcBeats,
            AiStage::OutlineBeatDetail,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function resolveOutlineRoutes(Novel $novel): array
    {
        $routes = [];
        foreach ($this->outlineRouteStages() as $stage) {
            $routes[$stage->value] = $this->resolveOutlineRoute($novel, $stage);
        }

        return $routes;
    }

    /** @return array<string, mixed> */
    private function resolveOutlineRoute(Novel $novel, AiStage $stage): array
    {
        $settings = $this->settingsResolver->resolve($stage, $novel);
        $requestBudget = $this->configuredOutlineRequestBudget($stage);
        $modelCapacity = $this->configuredOutlineCapacity(
            $stage,
            $settings->provider,
            $settings->model,
            $settings->reasoningEffort,
        );
        $staticCompletionLimit = min(
            (int) $modelCapacity['context_window_tokens'],
            (int) $modelCapacity['max_output_tokens'],
        );
        if ($requestBudget['max_completion_tokens'] > $staticCompletionLimit) {
            throw ValidationException::withMessages([
                'request_budget' => "Outline 任务 {$stage->value} 的请求预算 {$requestBudget['max_completion_tokens']} Token 超过模型静态容量 {$staticCompletionLimit} Token。",
            ]);
        }

        return [
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'source' => $settings->source,
            'prompt_version' => $this->promptVersionForRoute($stage),
            'request_budget' => $requestBudget,
            'model_capacity' => $modelCapacity,
        ];
    }

    private function promptVersionForRoute(AiStage $stage): string
    {
        return match ($stage) {
            AiStage::OutlineFoundation => self::FOUNDATION_PROMPT_VERSION,
            AiStage::OutlineStructure => NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION,
            AiStage::OutlineArcBeats => NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION,
            AiStage::OutlineBeatDetail => self::BEAT_DETAIL_PROMPT_VERSION,
            AiStage::Planner => self::SKELETON_PROMPT_VERSION,
            default => throw new AiProviderException('provider_run_route_missing', "AI 阶段 {$stage->value} 不是 Outline Provider 任务。", false),
        };
    }

    /** @return array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int} */
    private function configuredOutlineRequestBudget(AiStage $stage): array
    {
        $configured = config("generation.outline_request_budgets.{$stage->value}");
        $outputTokens = is_array($configured) ? (int) ($configured['output_tokens'] ?? 0) : 0;
        $reasoningReserveTokens = is_array($configured) ? (int) ($configured['reasoning_reserve_tokens'] ?? -1) : -1;
        if ($outputTokens < 1 || $reasoningReserveTokens < 0) {
            throw ValidationException::withMessages([
                'request_budget' => "Outline 任务 {$stage->value} 缺少有效的输出 Token 或推理预留配置。",
            ]);
        }

        return [
            'output_tokens' => $outputTokens,
            'reasoning_reserve_tokens' => $reasoningReserveTokens,
            'max_completion_tokens' => $outputTokens + $reasoningReserveTokens,
        ];
    }

    /** @return array<string, int|string|bool> */
    private function configuredOutlineCapacity(AiStage $stage, string $provider, string $model, ?string $reasoningEffort): array
    {
        $profile = AIModelPrice::findEnabledForRoute($provider, $model);
        if ($profile === null) {
            throw ValidationException::withMessages([
                'capacity' => "Outline 任务 {$stage->value} 的路由 {$provider}/{$model} 缺少已启用的模型价格记录，无法取得容量与能力。",
            ]);
        }
        $errors = $profile->outlineSuitabilityErrors($reasoningEffort);
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'capacity' => "Outline 任务 {$stage->value} 的模型 {$provider}/{$model} 不适用：".implode(' ', $errors),
            ]);
        }

        // 新批次冻结当前模型记录的容量与能力，后续配置修改不能改变 Retry 或 Resume 行为。
        return $profile->outlineCapacitySnapshot();
    }

    /**
     * @return array{provider: string, model: string, reasoning_effort: string|null, source: string, prompt_version: string, request_budget: array<string, int>, model_capacity: array<string, mixed>}
     */
    private function frozenRoute(GenerationRun $batch, AiStage $stage): array
    {
        if ($batch->prompt_version === self::BATCH_PROMPT_VERSION) {
            $route = data_get($batch->context_snapshot, "generation_preferences.outline_routes.{$stage->value}");
            if (! is_array($route)
                || blank($route['provider'] ?? null)
                || blank($route['model'] ?? null)
                || ($route['prompt_version'] ?? null) !== $this->promptVersionForRoute($stage)
                || ! is_array($route['request_budget'] ?? null)
                || ! is_array($route['model_capacity'] ?? null)) {
                throw new AiProviderException('provider_run_route_missing', "Outline 主批次缺少任务 {$stage->value} 的完整冻结路由。", false);
            }
            $capacity = $route['model_capacity'];
            if (($capacity['provider'] ?? null) !== $route['provider']
                || ($capacity['model'] ?? null) !== $route['model']
                || (int) ($capacity['context_window_tokens'] ?? 0) < 1
                || (int) ($capacity['max_output_tokens'] ?? 0) < 1) {
                throw new AiProviderException('provider_run_route_missing', "Outline 任务 {$stage->value} 的冻结容量与路由不匹配。", false);
            }
            $requestBudget = $this->normalizeFrozenRequestBudget($route['request_budget'], $stage);

            return [
                'provider' => strtolower(trim((string) $route['provider'])),
                'model' => trim((string) $route['model']),
                'reasoning_effort' => filled($route['reasoning_effort'] ?? null) ? trim((string) $route['reasoning_effort']) : null,
                'source' => trim((string) ($route['source'] ?? 'frozen_batch')),
                'prompt_version' => (string) $route['prompt_version'],
                'request_budget' => $requestBudget,
                'model_capacity' => $capacity,
            ];
        }

        if (blank($batch->provider) || blank($batch->model_policy)) {
            throw new AiProviderException('provider_run_route_missing', 'Outline 主批次缺少冻结的 Provider 或 Model。', false);
        }
        $capacity = data_get($batch->context_snapshot, 'generation_preferences.model_capacity');

        return [
            'provider' => strtolower(trim((string) $batch->provider)),
            'model' => trim((string) $batch->model_policy),
            'reasoning_effort' => data_get($batch->context_snapshot, 'generation_preferences.reasoning_effort'),
            'source' => 'legacy_batch',
            'prompt_version' => $this->promptVersionForRoute($stage),
            'request_budget' => $this->legacyRequestBudget($stage),
            'model_capacity' => is_array($capacity) ? $capacity : [],
        ];
    }

    /** @return array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int} */
    private function normalizeFrozenRequestBudget(array $budget, AiStage $stage): array
    {
        $outputTokens = (int) ($budget['output_tokens'] ?? 0);
        $reasoningReserveTokens = (int) ($budget['reasoning_reserve_tokens'] ?? -1);
        $maxCompletionTokens = (int) ($budget['max_completion_tokens'] ?? 0);
        if ($outputTokens < 1 || $reasoningReserveTokens < 0 || $maxCompletionTokens !== $outputTokens + $reasoningReserveTokens) {
            throw new AiProviderException('provider_run_route_missing', "Outline 任务 {$stage->value} 缺少有效的冻结请求预算。", false);
        }

        return [
            'output_tokens' => $outputTokens,
            'reasoning_reserve_tokens' => $reasoningReserveTokens,
            'max_completion_tokens' => $maxCompletionTokens,
        ];
    }

    /** v3 历史批次没有冻结推理预留，继续使用升级前各阶段的原始请求上限。 */
    private function legacyRequestBudget(AiStage $stage): array
    {
        $outputTokens = match ($stage) {
            AiStage::OutlineFoundation => 8_000,
            AiStage::OutlineStructure => 12_000,
            AiStage::OutlineArcBeats => 8_000,
            AiStage::OutlineBeatDetail => 5_000,
            AiStage::Planner => self::SKELETON_MAX_TOKENS,
            default => throw new AiProviderException('provider_run_route_missing', "AI 阶段 {$stage->value} 没有历史 Outline 请求预算。", false),
        };

        return [
            'output_tokens' => $outputTokens,
            'reasoning_reserve_tokens' => 0,
            'max_completion_tokens' => $outputTokens,
        ];
    }

    /** @return array<string, int> */
    private function capacitySnapshot(GenerationRun $batch, AiStage $stage, array $requestInput, array $requestBudget): array
    {
        $route = $this->frozenRoute($batch, $stage);
        $capacity = $route['model_capacity'];
        if (! is_array($capacity)
            || ($capacity['provider'] ?? null) !== $route['provider']
            || ($capacity['model'] ?? null) !== $route['model']) {
            throw ValidationException::withMessages(['capacity' => 'Outline 主批次缺少与冻结路由匹配的模型容量。']);
        }

        return $this->stageContract->capacitySnapshot(
            $requestInput,
            $requestBudget['output_tokens'],
            $requestBudget['reasoning_reserve_tokens'],
            (int) ($capacity['context_window_tokens'] ?? 0),
            (int) ($capacity['max_output_tokens'] ?? 0),
        );
    }

    /**
     * 四个新版 Provider Stage 共用同一容量门禁；门禁在创建子 Run 和发出请求前完成。
     *
     * @return array{context: array<string, mixed>, request_budget: array<string, int>, input_hash: string}
     */
    private function providerRequestContract(
        GenerationRun $batch,
        AiStage $stage,
        array $route,
        array $context,
        string $promptVersion,
        string $systemPrompt,
        string $prompt,
        array $schema,
        ?int $legacyMaxTokens = null,
    ): array {
        if (in_array($stage, $this->outlineRouteStages(), true)) {
            $requestBudget = $route['request_budget'];
            $capacity = $this->capacitySnapshot(
                $batch,
                $stage,
                compact('systemPrompt', 'prompt', 'schema'),
                $requestBudget,
            );
            $context = [...$context, 'capacity_snapshot' => $capacity];
        } else {
            if ($legacyMaxTokens === null || $legacyMaxTokens < 1) {
                throw new AiProviderException('provider_run_route_missing', "历史 Outline 任务 {$stage->value} 缺少请求预算。", false);
            }
            $requestBudget = [
                'output_tokens' => $legacyMaxTokens,
                'reasoning_reserve_tokens' => 0,
                'max_completion_tokens' => $legacyMaxTokens,
            ];
        }

        return [
            'context' => $context,
            'request_budget' => $requestBudget,
            'input_hash' => $this->providerInputHash($route, $context, $promptVersion, $requestBudget, $schema),
        ];
    }

    /** @param array{provider: string, model: string, reasoning_effort: string|null} $route */
    private function providerInputHash(array $route, array $context, string $promptVersion, array $requestBudget, array $schema): string
    {
        return $this->hash([
            'context' => $context,
            'provider' => $route['provider'],
            'model' => $route['model'],
            'reasoning_effort' => $route['reasoning_effort'],
            'prompt_version' => $promptVersion,
            'request_budget' => $requestBudget,
            'response_schema_hash' => $this->hash($schema),
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
        $route = $this->frozenRoute($batch, AiStage::OutlineStructure);

        return $this->providerRequestContract(
            $batch,
            AiStage::OutlineStructure,
            $route,
            $context,
            NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION,
            $systemPrompt,
            $prompt,
            $schema,
        )['input_hash'];
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
        $systemPrompt = '你是 XNovel 单 Arc Beats 规划器。只返回严格 JSON。只生成目标 Arc 的 Beats、预算、验收条件和候选；不得返回其他 Arc、Volume、Milestone、Handoff、Beat Key、Candidate Key、sequence、mainline_sequence 或数据库 ID。Beat 与 Candidate 的稳定 Key 由 Laravel 在合并时统一分配。所有自然语言使用简体中文。'.NarrativeProsePolicy::planning();
        $prompt = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $route = $this->frozenRoute($batch, AiStage::OutlineArcBeats);

        return $this->providerRequestContract(
            $batch,
            AiStage::OutlineArcBeats,
            $route,
            $context,
            NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION,
            $systemPrompt,
            $prompt,
            $schema,
        )['input_hash'];
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
