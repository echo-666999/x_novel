<?php

namespace App\Services;

use App\Data\NovelOutlineProgress;
use App\Data\NovelOutlineStageProgress;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * 从 PostgreSQL Run / Artifact 投影全书大纲生成进度；不修改状态，也不读取 Queue 或 Redis。
 */
final class NovelOutlineProgressResolver
{
    private const STAGE_LABELS = [
        'foundation' => '基础设定',
        'structure' => '分卷与故事线',
        'arc_beats' => '故事线节点',
        'skeleton_assembly' => '合并全书骨架',
        'legacy_skeleton' => '生成全书骨架（旧版）',
        'beat_detail' => '主线节点细化',
        'finalize' => '创建候选版本',
    ];

    private const PAGE_STATUS_LABELS = [
        'not_started' => '尚未开始',
        'queued' => '排队中',
        'running' => '生成中',
        'retrying' => '等待重试',
        'failed' => '生成失败',
        'succeeded' => '生成完成',
        'cancelled' => '已取消',
    ];

    private const OUTLINE_SCOPES = [
        NovelOutlinePipeline::FOUNDATION_SCOPE,
        NovelOutlinePipeline::STRUCTURE_SCOPE,
        NovelOutlinePipeline::ARC_BEATS_SCOPE,
        NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE,
        NovelOutlinePipeline::SKELETON_SCOPE,
        NovelOutlinePipeline::BEAT_DETAIL_SCOPE,
        NovelOutlinePipeline::FINALIZE_SCOPE,
    ];

    public function __construct(private readonly GenerationFailurePolicy $failurePolicy) {}

    public function resolve(Novel $novel): NovelOutlineProgress
    {
        $freshNovel = Novel::query()
            ->withCount(['chapters', 'storyEvents'])
            ->findOrFail($novel->getKey());
        /** @var EloquentCollection<int, GenerationRun> $batches */
        $batches = GenerationRun::query()
            ->where('novel_id', $freshNovel->getKey())
            ->whereNull('chapter_id')
            ->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)
            ->where('scope_id', $freshNovel->getKey())
            ->latest('id')
            ->get();
        $latestBatch = $batches->first();

        if ($latestBatch === null) {
            return $this->notStarted();
        }

        /** @var EloquentCollection<int, GenerationRun> $runs */
        $runs = GenerationRun::query()
            ->with([
                'artifacts:id,generation_run_id,type,version,checksum,data,created_at',
                'usageRecords:id,generation_run_id,provider,model,input_tokens,output_tokens,reasoning_tokens,cached_tokens,latency_ms,estimated_cost,request_id,request_metadata,created_at',
            ])
            ->where('novel_id', $freshNovel->getKey())
            ->where(function ($query) use ($latestBatch): void {
                $query->whereKey($latestBatch->getKey())
                    ->orWhere(function ($children) use ($latestBatch): void {
                        $children->where('scope_id', $latestBatch->getKey())
                            ->whereIn('scope_type', self::OUTLINE_SCOPES);
                    });
            })
            ->orderBy('id')
            ->get();
        $batch = $runs->firstWhere('id', $latestBatch->getKey()) ?? $latestBatch;
        $children = $runs->where('id', '!=', $batch->getKey())->values();
        $chain = $batch->prompt_version === NovelOutlinePipeline::LEGACY_BATCH_PROMPT_VERSION
            ? $this->legacyChain($batch, $children)
            : $this->currentChain($batch, $children);

        $failedScope = is_string(data_get($batch->error_metadata, 'failed_scope'))
            ? data_get($batch->error_metadata, 'failed_scope')
            : null;
        $failedDiscriminator = is_string(data_get($batch->error_metadata, 'discriminator'))
            ? data_get($batch->error_metadata, 'discriminator')
            : null;
        $currentStage = $batch->status === RunStatus::Failed
            ? ($this->stageForScope($failedScope) ?? $chain['current_stage'])
            : $chain['current_stage'];
        $currentItemKey = $batch->status === RunStatus::Failed && $failedDiscriminator !== null
            ? $failedDiscriminator
            : $chain['current_item_key'];
        $currentItemLabel = $this->itemLabel($currentStage, $currentItemKey, $chain)
            ?? $chain['current_item_label'];
        $currentRun = $this->currentRun($batch, $children, $currentStage, $currentItemKey);
        $pageStatus = $this->pageStatus($batch, $currentRun);
        $errorRun = $this->errorRun($batch, $children, $currentRun, $pageStatus);
        $failure = $errorRun === null ? null : $this->failurePolicy->forRun($errorRun);
        $errorMetadata = $failure === null
            ? []
            : (is_array($batch->error_metadata) ? $batch->error_metadata : []) + $failure->metadata;
        $stages = $this->stageProgress($children, $chain, $currentStage, $pageStatus);
        $displayRoute = $this->displayRoute($batch, $currentRun, $currentStage);

        return new NovelOutlineProgress(
            batchId: $batch->getKey(),
            databaseStatus: $batch->status->value,
            pageStatus: $pageStatus,
            pageStatusLabel: self::PAGE_STATUS_LABELS[$pageStatus],
            currentStage: $currentStage,
            currentStageLabel: self::STAGE_LABELS[$currentStage] ?? null,
            currentItemKey: $currentItemKey,
            currentItemLabel: $currentItemLabel,
            stages: $stages,
            // 阶段交接尚未创建子 Run 时不显示主批次 attempt，避免把“第几个批次”误报成当前节点重试次数。
            latestAttempt: $currentRun->is($batch) ? null : $this->displayAttempt($currentRun, $children),
            startedAt: $this->immutable($batch->started_at),
            finishedAt: $this->immutable($batch->finished_at),
            durationMilliseconds: $batch->durationMilliseconds(),
            currentRunStartedAt: $this->immutable($currentRun?->started_at),
            currentRunFinishedAt: $this->immutable($currentRun?->finished_at),
            currentRunDurationMilliseconds: $currentRun?->durationMilliseconds(),
            provider: $displayRoute['provider'],
            model: $displayRoute['model'],
            reasoningEffort: $displayRoute['reasoning_effort'],
            batchPromptVersion: $batch->prompt_version,
            promptVersion: $currentRun?->prompt_version,
            promptVersions: $this->promptVersions($batch),
            errorCode: $failure?->code,
            errorMessage: $failure === null ? null : $this->userErrorMessage($failure->code, $errorMetadata),
            technicalError: $errorRun?->error_message,
            errorMetadata: $errorMetadata,
            recommendedAction: $failure?->recommendedAction,
            canResume: $this->canResume(
                $freshNovel,
                $batch,
                $children,
                $batches->contains(fn (GenerationRun $candidate): bool => $candidate->getKey() !== $batch->getKey()
                    && in_array($candidate->status, [RunStatus::Queued, RunStatus::Running], true)),
            ),
            currentRunId: $currentRun?->getKey(),
            runs: $runs->map(fn (GenerationRun $run): array => $this->runReference(
                $run,
                $run->is($batch) ? (int) $run->attempt : $this->displayAttempt($run, $children),
            ))->all(),
            artifacts: $runs->flatMap->artifacts->map(fn (GenerationArtifact $artifact): array => $this->artifactReference($artifact))->values()->all(),
        );
    }

    private function notStarted(): NovelOutlineProgress
    {
        $stages = collect($this->currentStageKeys())->mapWithKeys(fn (string $key): array => [
            $key => new NovelOutlineStageProgress($key, self::STAGE_LABELS[$key], 'waiting', 0, in_array($key, ['arc_beats', 'beat_detail'], true) ? null : 1),
        ])->all();

        return new NovelOutlineProgress(
            batchId: null,
            databaseStatus: null,
            pageStatus: 'not_started',
            pageStatusLabel: self::PAGE_STATUS_LABELS['not_started'],
            currentStage: null,
            currentStageLabel: null,
            currentItemKey: null,
            currentItemLabel: null,
            stages: $stages,
            latestAttempt: null,
            startedAt: null,
            finishedAt: null,
            durationMilliseconds: null,
            currentRunStartedAt: null,
            currentRunFinishedAt: null,
            currentRunDurationMilliseconds: null,
            provider: null,
            model: null,
            reasoningEffort: null,
            batchPromptVersion: null,
            promptVersion: null,
            promptVersions: [],
            errorCode: null,
            errorMessage: null,
            technicalError: null,
            errorMetadata: [],
            recommendedAction: null,
            canResume: false,
            currentRunId: null,
            runs: [],
            artifacts: [],
        );
    }

    /** @return array<string, mixed> */
    private function currentChain(GenerationRun $batch, Collection $runs): array
    {
        $foundation = $this->latestArtifact($runs, NovelOutlinePipeline::FOUNDATION_SCOPE, ArtifactType::OutlineFoundation, $batch->getKey());
        $structure = $foundation === null ? null : $this->latestArtifact(
            $runs,
            NovelOutlinePipeline::STRUCTURE_SCOPE,
            ArtifactType::OutlineStructure,
            $batch->getKey(),
            null,
            fn (GenerationArtifact $artifact): bool => $this->hasIndexedSources($artifact, [$foundation]),
        );
        $arcDefinitions = $structure === null ? [] : $this->arcDefinitions($this->payload($structure));
        $arcArtifacts = [];
        foreach ($arcDefinitions as $arc) {
            $artifact = $this->latestArtifact(
                $runs,
                NovelOutlinePipeline::ARC_BEATS_SCOPE,
                ArtifactType::OutlineArcBeats,
                $batch->getKey(),
                $arc['key'],
                fn (GenerationArtifact $candidate): bool => $this->hasIndexedSources($candidate, [$foundation, $structure])
                    && data_get($candidate->data, 'payload.arc_key') === $arc['key'],
            );
            if ($artifact !== null) {
                $arcArtifacts[$arc['key']] = $artifact;
            }
        }
        $allArcsComplete = $structure !== null && count($arcDefinitions) > 0 && count($arcArtifacts) === count($arcDefinitions);
        $skeleton = ! $allArcsComplete ? null : $this->latestArtifact(
            $runs,
            NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE,
            ArtifactType::OutlineSkeleton,
            $batch->getKey(),
            null,
            fn (GenerationArtifact $artifact): bool => $this->hasAssemblySources($artifact, $foundation, $structure, $arcArtifacts),
        );
        $beatDefinitions = $skeleton === null ? [] : $this->beatDefinitions($this->payload($skeleton));
        $detailArtifacts = [];
        foreach ($beatDefinitions as $beat) {
            $artifact = $this->latestArtifact(
                $runs,
                NovelOutlinePipeline::BEAT_DETAIL_SCOPE,
                ArtifactType::OutlineBeatDetail,
                $batch->getKey(),
                $beat['key'],
                fn (GenerationArtifact $candidate): bool => $this->hasIndexedSources($candidate, [$foundation, $skeleton])
                    && data_get($candidate->data, 'payload.beat_key') === $beat['key'],
            );
            if ($artifact !== null) {
                $detailArtifacts[$beat['key']] = $artifact;
            }
        }
        $allDetailsComplete = $skeleton !== null && count($detailArtifacts) === count($beatDefinitions);
        $blueprint = ! $allDetailsComplete ? null : $this->latestBlueprint($runs, $batch->getKey(), $foundation, $structure, $arcArtifacts, $skeleton, $detailArtifacts);

        [$currentStage, $currentItemKey, $currentItemLabel] = match (true) {
            $foundation === null => ['foundation', null, null],
            $structure === null => ['structure', null, null],
            ! $allArcsComplete => $this->firstMissingItem('arc_beats', $arcDefinitions, $arcArtifacts),
            $skeleton === null => ['skeleton_assembly', null, null],
            ! $allDetailsComplete => $this->firstMissingItem('beat_detail', $beatDefinitions, $detailArtifacts),
            default => ['finalize', null, null],
        };

        return compact(
            'foundation', 'structure', 'arcDefinitions', 'arcArtifacts', 'skeleton', 'beatDefinitions',
            'detailArtifacts', 'blueprint', 'currentStage', 'currentItemKey', 'currentItemLabel',
        ) + [
            'current_stage' => $currentStage,
            'current_item_key' => $currentItemKey,
            'current_item_label' => $currentItemLabel,
            'legacy' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function legacyChain(GenerationRun $batch, Collection $runs): array
    {
        $foundation = $this->latestArtifact($runs, NovelOutlinePipeline::FOUNDATION_SCOPE, ArtifactType::OutlineFoundation, $batch->getKey());
        $skeleton = $foundation === null ? null : $this->latestArtifact(
            $runs,
            NovelOutlinePipeline::SKELETON_SCOPE,
            ArtifactType::OutlineSkeleton,
            $batch->getKey(),
            null,
            fn (GenerationArtifact $artifact): bool => $this->hasIndexedSources($artifact, [$foundation]),
        );
        $beatDefinitions = $skeleton === null ? [] : $this->beatDefinitions($this->payload($skeleton));
        $detailArtifacts = [];
        foreach ($beatDefinitions as $beat) {
            $artifact = $this->latestArtifact(
                $runs,
                NovelOutlinePipeline::BEAT_DETAIL_SCOPE,
                ArtifactType::OutlineBeatDetail,
                $batch->getKey(),
                $beat['key'],
                fn (GenerationArtifact $candidate): bool => $this->hasIndexedSources($candidate, [$foundation, $skeleton]),
            );
            if ($artifact !== null) {
                $detailArtifacts[$beat['key']] = $artifact;
            }
        }
        $allDetailsComplete = $skeleton !== null && count($detailArtifacts) === count($beatDefinitions);
        $blueprint = ! $allDetailsComplete ? null : $this->latestLegacyBlueprint($runs, $batch->getKey(), $foundation, $skeleton, $detailArtifacts);
        [$currentStage, $currentItemKey, $currentItemLabel] = match (true) {
            $foundation === null => ['foundation', null, null],
            $skeleton === null => ['legacy_skeleton', null, null],
            ! $allDetailsComplete => $this->firstMissingItem('beat_detail', $beatDefinitions, $detailArtifacts),
            default => ['finalize', null, null],
        };

        return [
            'foundation' => $foundation,
            'structure' => null,
            'arcDefinitions' => [],
            'arcArtifacts' => [],
            'skeleton' => $skeleton,
            'beatDefinitions' => $beatDefinitions,
            'detailArtifacts' => $detailArtifacts,
            'blueprint' => $blueprint,
            'current_stage' => $currentStage,
            'current_item_key' => $currentItemKey,
            'current_item_label' => $currentItemLabel,
            'legacy' => true,
        ];
    }

    private function latestArtifact(
        Collection $runs,
        string $scope,
        ArtifactType $type,
        int $batchId,
        ?string $discriminator = null,
        ?callable $additional = null,
    ): ?GenerationArtifact {
        return $runs
            ->where('scope_type', $scope)
            ->where('status', RunStatus::Succeeded)
            ->flatMap->artifacts
            ->filter(fn (GenerationArtifact $artifact): bool => $this->validStageArtifact($artifact, $scope, $type, $batchId, $discriminator)
                && ($additional === null || $additional($artifact)))
            ->sortByDesc('id')
            ->first();
    }

    private function validStageArtifact(GenerationArtifact $artifact, string $scope, ArtifactType $type, int $batchId, ?string $discriminator): bool
    {
        return $artifact->type === $type
            && data_get($artifact->data, 'batch_run_id') === $batchId
            && data_get($artifact->data, 'stage') === $scope
            && data_get($artifact->data, 'discriminator') === $discriminator
            && $this->checksumValid($artifact);
    }

    /** @param array<int, GenerationArtifact> $sources */
    private function hasIndexedSources(GenerationArtifact $artifact, array $sources): bool
    {
        $references = data_get($artifact->data, 'source_artifacts');

        return is_array($references)
            && array_is_list($references)
            && count($references) === count($sources)
            && collect($sources)->every(fn (GenerationArtifact $source, int $index): bool => $this->referenceMatches($references[$index] ?? null, $source));
    }

    /** @param array<string, GenerationArtifact> $arcArtifacts */
    private function hasAssemblySources(
        GenerationArtifact $artifact,
        GenerationArtifact $foundation,
        GenerationArtifact $structure,
        array $arcArtifacts,
    ): bool {
        $sources = data_get($artifact->data, 'source_artifacts');
        $arcReferences = is_array($sources) ? ($sources['arc_beats'] ?? null) : null;

        return is_array($sources)
            && $this->referenceMatches($sources['foundation'] ?? null, $foundation)
            && $this->referenceMatches($sources['structure'] ?? null, $structure)
            && is_array($arcReferences)
            && array_keys($arcReferences) === array_keys($arcArtifacts)
            && collect($arcArtifacts)->every(fn (GenerationArtifact $source, string $key): bool => $this->referenceMatches($arcReferences[$key] ?? null, $source));
    }

    /** @param array<string, GenerationArtifact> $arcArtifacts
     * @param  array<string, GenerationArtifact>  $detailArtifacts
     */
    private function latestBlueprint(
        Collection $runs,
        int $batchId,
        GenerationArtifact $foundation,
        GenerationArtifact $structure,
        array $arcArtifacts,
        GenerationArtifact $skeleton,
        array $detailArtifacts,
    ): ?GenerationArtifact {
        return $this->blueprints($runs, $batchId)->first(fn (GenerationArtifact $artifact): bool => $this->referenceMatches(data_get($artifact->data, 'lineage.foundation'), $foundation)
            && $this->referenceMatches(data_get($artifact->data, 'lineage.structure'), $structure)
            && $this->referenceMapMatches(data_get($artifact->data, 'lineage.arc_beats'), $arcArtifacts)
            && $this->referenceMatches(data_get($artifact->data, 'lineage.skeleton'), $skeleton)
            && $this->referenceMapMatches(data_get($artifact->data, 'lineage.beat_details'), $detailArtifacts));
    }

    /** @param array<string, GenerationArtifact> $detailArtifacts */
    private function latestLegacyBlueprint(Collection $runs, int $batchId, GenerationArtifact $foundation, GenerationArtifact $skeleton, array $detailArtifacts): ?GenerationArtifact
    {
        return $this->blueprints($runs, $batchId)->first(fn (GenerationArtifact $artifact): bool => $this->referenceMatches(data_get($artifact->data, 'lineage.foundation'), $foundation)
            && $this->referenceMatches(data_get($artifact->data, 'lineage.skeleton'), $skeleton)
            && $this->referenceMapMatches(data_get($artifact->data, 'lineage.beat_details'), $detailArtifacts));
    }

    private function blueprints(Collection $runs, int $batchId): Collection
    {
        return $runs
            ->where('scope_type', NovelOutlinePipeline::FINALIZE_SCOPE)
            ->where('status', RunStatus::Succeeded)
            ->flatMap->artifacts
            ->filter(fn (GenerationArtifact $artifact): bool => $artifact->type === ArtifactType::OutlineBlueprint
                && data_get($artifact->data, 'lineage.batch_run_id') === $batchId
                && $this->checksumValid($artifact))
            ->sortByDesc('id')
            ->values();
    }

    /** @param array<string, GenerationArtifact> $artifacts */
    private function referenceMapMatches(mixed $references, array $artifacts): bool
    {
        return is_array($references)
            && array_keys($references) === array_keys($artifacts)
            && collect($artifacts)->every(fn (GenerationArtifact $artifact, string $key): bool => $this->referenceMatches($references[$key] ?? null, $artifact));
    }

    private function referenceMatches(mixed $reference, GenerationArtifact $artifact): bool
    {
        return is_array($reference)
            && ($reference['id'] ?? null) === $artifact->getKey()
            && ($reference['type'] ?? null) === $artifact->type->value
            && is_string($reference['checksum'] ?? null)
            && hash_equals($artifact->checksum, $reference['checksum']);
    }

    private function checksumValid(GenerationArtifact $artifact): bool
    {
        return hash_equals(
            $artifact->checksum,
            hash('sha256', json_encode($artifact->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        );
    }

    /** @return array<int, array{key: string, label: string}> */
    private function arcDefinitions(array $structure): array
    {
        $arcs = [];
        foreach ($structure['volumes'] ?? [] as $volume) {
            foreach ($volume['arcs'] ?? [] as $arc) {
                $arcs[] = [
                    'key' => (string) $arc['key'],
                    'label' => trim((string) ($volume['title'] ?? '')).' · '.trim((string) ($arc['title'] ?? '')),
                ];
            }
        }

        return $arcs;
    }

    /** @return array<int, array{key: string, label: string}> */
    private function beatDefinitions(array $skeleton): array
    {
        $beats = [];
        foreach ($skeleton['volumes'] ?? [] as $volume) {
            foreach ($volume['arcs'] ?? [] as $arc) {
                if (($arc['type'] ?? null) !== 'main') {
                    continue;
                }
                foreach ($arc['beats'] ?? [] as $beat) {
                    $beats[] = [
                        'key' => (string) $beat['key'],
                        'label' => trim((string) ($volume['title'] ?? '')).' · '.trim((string) ($arc['title'] ?? '')).' · '.trim((string) ($beat['title'] ?? '')),
                    ];
                }
            }
        }

        return $beats;
    }

    /** @param array<int, array{key: string, label: string}> $definitions
     * @param  array<string, GenerationArtifact>  $artifacts
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function firstMissingItem(string $stage, array $definitions, array $artifacts): array
    {
        $missing = collect($definitions)->first(fn (array $definition): bool => ! isset($artifacts[$definition['key']]));

        return [$stage, $missing['key'] ?? null, $missing['label'] ?? null];
    }

    private function pageStatus(GenerationRun $batch, ?GenerationRun $currentRun): string
    {
        return match ($batch->status) {
            RunStatus::Queued => 'queued',
            RunStatus::Failed => 'failed',
            RunStatus::Succeeded => 'succeeded',
            RunStatus::Cancelled => 'cancelled',
            RunStatus::Running => $currentRun?->status === RunStatus::Failed && $currentRun->error_retryable === true
                ? 'retrying'
                : 'running',
        };
    }

    private function currentRun(GenerationRun $batch, Collection $runs, ?string $stage, ?string $discriminator): GenerationRun
    {
        $scope = $this->scopeForStage($stage);
        if ($scope === null) {
            return $batch;
        }
        $matching = $runs->where('scope_type', $scope);
        if ($discriminator !== null) {
            $matching = $matching->filter(fn (GenerationRun $run): bool => data_get($run->context_snapshot, 'discriminator') === $discriminator);
        }

        return $matching->sortByDesc('id')->first() ?? $batch;
    }

    private function errorRun(GenerationRun $batch, Collection $runs, GenerationRun $currentRun, string $pageStatus): ?GenerationRun
    {
        if ($pageStatus === 'retrying') {
            return $currentRun;
        }
        if ($pageStatus !== 'failed') {
            return null;
        }
        $childRunId = data_get($batch->error_metadata, 'child_run_id');

        return is_int($childRunId) ? ($runs->firstWhere('id', $childRunId) ?? $batch) : $batch;
    }

    /** @return array<string, NovelOutlineStageProgress> */
    private function stageProgress(Collection $runs, array $chain, string $currentStage, string $pageStatus): array
    {
        $keys = $chain['legacy'] ? ['foundation', 'legacy_skeleton', 'beat_detail', 'finalize'] : $this->currentStageKeys();
        $completed = [
            'foundation' => $chain['foundation'] === null ? 0 : 1,
            'structure' => $chain['structure'] === null ? 0 : 1,
            'arc_beats' => count($chain['arcArtifacts']),
            'skeleton_assembly' => ! $chain['legacy'] && $chain['skeleton'] !== null ? 1 : 0,
            'legacy_skeleton' => $chain['legacy'] && $chain['skeleton'] !== null ? 1 : 0,
            'beat_detail' => count($chain['detailArtifacts']),
            'finalize' => $chain['blueprint'] === null ? 0 : 1,
        ];
        $totals = [
            'foundation' => 1,
            'structure' => 1,
            'arc_beats' => $chain['structure'] === null ? null : count($chain['arcDefinitions']),
            'skeleton_assembly' => 1,
            'legacy_skeleton' => 1,
            'beat_detail' => $chain['skeleton'] === null ? null : count($chain['beatDefinitions']),
            'finalize' => 1,
        ];

        return collect($keys)->mapWithKeys(function (string $key) use ($runs, $chain, $currentStage, $pageStatus, $completed, $totals): array {
            $scope = $this->scopeForStage($key);
            $stageRuns = $runs->where('scope_type', $scope);
            $artifactIds = match ($key) {
                'foundation' => $chain['foundation'] === null ? [] : [$chain['foundation']->getKey()],
                'structure' => $chain['structure'] === null ? [] : [$chain['structure']->getKey()],
                'arc_beats' => collect($chain['arcArtifacts'])->pluck('id')->values()->all(),
                'skeleton_assembly', 'legacy_skeleton' => $chain['skeleton'] === null ? [] : [$chain['skeleton']->getKey()],
                'beat_detail' => collect($chain['detailArtifacts'])->pluck('id')->values()->all(),
                'finalize' => $chain['blueprint'] === null ? [] : [$chain['blueprint']->getKey()],
            };
            $status = $completed[$key] > 0 && $totals[$key] !== null && $completed[$key] === $totals[$key]
                ? 'succeeded'
                : ($key === $currentStage ? $pageStatus : 'waiting');

            return [$key => new NovelOutlineStageProgress(
                key: $key,
                label: self::STAGE_LABELS[$key],
                status: $status,
                completed: $completed[$key],
                total: $totals[$key],
                runIds: $stageRuns->pluck('id')->values()->all(),
                artifactIds: $artifactIds,
            )];
        })->all();
    }

    private function stageForScope(?string $scope): ?string
    {
        return match ($scope) {
            NovelOutlinePipeline::FOUNDATION_SCOPE => 'foundation',
            NovelOutlinePipeline::STRUCTURE_SCOPE => 'structure',
            NovelOutlinePipeline::ARC_BEATS_SCOPE => 'arc_beats',
            NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE => 'skeleton_assembly',
            NovelOutlinePipeline::SKELETON_SCOPE => 'legacy_skeleton',
            NovelOutlinePipeline::BEAT_DETAIL_SCOPE => 'beat_detail',
            NovelOutlinePipeline::FINALIZE_SCOPE => 'finalize',
            default => null,
        };
    }

    private function scopeForStage(?string $stage): ?string
    {
        return match ($stage) {
            'foundation' => NovelOutlinePipeline::FOUNDATION_SCOPE,
            'structure' => NovelOutlinePipeline::STRUCTURE_SCOPE,
            'arc_beats' => NovelOutlinePipeline::ARC_BEATS_SCOPE,
            'skeleton_assembly' => NovelOutlinePipeline::SKELETON_ASSEMBLY_SCOPE,
            'legacy_skeleton' => NovelOutlinePipeline::SKELETON_SCOPE,
            'beat_detail' => NovelOutlinePipeline::BEAT_DETAIL_SCOPE,
            'finalize' => NovelOutlinePipeline::FINALIZE_SCOPE,
            default => null,
        };
    }

    private function itemLabel(?string $stage, ?string $key, array $chain): ?string
    {
        if ($key === null) {
            return null;
        }
        $definitions = $stage === 'arc_beats' ? $chain['arcDefinitions'] : ($stage === 'beat_detail' ? $chain['beatDefinitions'] : []);

        $definition = collect($definitions)->firstWhere('key', $key);

        return is_array($definition) ? $definition['label'] : $key;
    }

    private function canResume(Novel $novel, GenerationRun $batch, Collection $children, bool $hasOtherActiveBatch): bool
    {
        if ($batch->status !== RunStatus::Failed
            || $batch->stage !== GenerationStage::ChapterPlanning
            || ! in_array($batch->prompt_version, [NovelOutlinePipeline::SINGLE_ROUTE_BATCH_PROMPT_VERSION, NovelOutlinePipeline::BATCH_PROMPT_VERSION], true)
            || ! in_array($novel->status, [NovelStatus::Draft, NovelStatus::Planning], true)
            || $novel->current_outline_id !== null
            || $novel->chapters_count > 0
            || $novel->story_events_count > 0
            || $hasOtherActiveBatch
            || $children->contains(fn (GenerationRun $run): bool => in_array($run->status, [RunStatus::Queued, RunStatus::Running], true))) {
            return false;
        }
        if (! $this->failurePolicy->allowsFrozenResume($batch)) {
            return false;
        }
        if ($batch->prompt_version === NovelOutlinePipeline::BATCH_PROMPT_VERSION) {
            if (! $this->hasCompleteFrozenRoutes($batch)) {
                return false;
            }
        } elseif (blank($batch->provider) || blank($batch->model_policy)) {
            return false;
        }
        $promptVersions = data_get($batch->context_snapshot, 'generation_preferences.prompt_versions');
        $volumeCount = data_get($batch->context_snapshot, 'requested_volume_count');
        $targetPlatform = data_get($batch->context_snapshot, 'target_platform.code');

        return $promptVersions === $this->currentPromptVersions()
            && is_int($volumeCount) && $volumeCount >= 1 && $volumeCount <= 12
            && is_string($targetPlatform) && array_key_exists($targetPlatform, config('narrative.platforms', []));
    }

    /** @return array{provider: string|null, model: string|null, reasoning_effort: string|null} */
    private function displayRoute(GenerationRun $batch, ?GenerationRun $currentRun, ?string $currentStage): array
    {
        if ($currentRun !== null && filled($currentRun->provider) && filled($currentRun->model_policy)) {
            return [
                'provider' => $currentRun->provider,
                'model' => $currentRun->model_policy,
                'reasoning_effort' => data_get($currentRun->context_snapshot, 'reasoning_effort'),
            ];
        }

        if ($batch->prompt_version === NovelOutlinePipeline::BATCH_PROMPT_VERSION) {
            $routeKey = match ($currentStage) {
                'foundation' => 'outline_foundation',
                'structure' => 'outline_structure',
                'arc_beats' => 'outline_arc_beats',
                'beat_detail' => 'outline_beat_detail',
                default => null,
            };
            $route = $routeKey === null
                ? null
                : data_get($batch->context_snapshot, "generation_preferences.outline_routes.{$routeKey}");

            return [
                'provider' => is_array($route) && filled($route['provider'] ?? null) ? (string) $route['provider'] : null,
                'model' => is_array($route) && filled($route['model'] ?? null) ? (string) $route['model'] : null,
                'reasoning_effort' => is_array($route) && filled($route['reasoning_effort'] ?? null) ? (string) $route['reasoning_effort'] : null,
            ];
        }

        return [
            'provider' => $batch->provider,
            'model' => $batch->model_policy,
            'reasoning_effort' => data_get($batch->context_snapshot, 'generation_preferences.reasoning_effort'),
        ];
    }

    private function hasCompleteFrozenRoutes(GenerationRun $batch): bool
    {
        foreach (['outline_foundation', 'outline_structure', 'outline_arc_beats', 'outline_beat_detail'] as $routeKey) {
            $route = data_get($batch->context_snapshot, "generation_preferences.outline_routes.{$routeKey}");
            $capacity = is_array($route) ? ($route['model_capacity'] ?? null) : null;
            $requestBudget = is_array($route) ? ($route['request_budget'] ?? null) : null;
            if (! is_array($route)
                || blank($route['provider'] ?? null)
                || blank($route['model'] ?? null)
                || blank($route['prompt_version'] ?? null)
                || ! is_array($requestBudget)
                || (int) ($requestBudget['output_tokens'] ?? 0) < 1
                || (int) ($requestBudget['reasoning_reserve_tokens'] ?? -1) < 0
                || (int) ($requestBudget['max_completion_tokens'] ?? 0) !== (int) ($requestBudget['output_tokens'] ?? 0) + (int) ($requestBudget['reasoning_reserve_tokens'] ?? 0)
                || ! is_array($capacity)
                || ($capacity['provider'] ?? null) !== $route['provider']
                || ($capacity['model'] ?? null) !== $route['model']
                || (int) ($capacity['context_window_tokens'] ?? 0) < 1
                || (int) ($capacity['max_output_tokens'] ?? 0) < 1) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function currentPromptVersions(): array
    {
        return [
            'foundation' => NovelOutlinePipeline::FOUNDATION_PROMPT_VERSION,
            'structure' => NovelOutlineStageContract::STRUCTURE_PROMPT_VERSION,
            'arc_beats' => NovelOutlineStageContract::ARC_BEATS_PROMPT_VERSION,
            'skeleton_assembly' => NovelOutlinePipeline::SKELETON_ASSEMBLY_VERSION,
            'beat_detail' => NovelOutlinePipeline::BEAT_DETAIL_PROMPT_VERSION,
            'finalize' => NovelOutlinePipeline::FINALIZE_PROMPT_VERSION,
        ];
    }

    private function userErrorMessage(string $code, array $metadata): string
    {
        if (str_contains($code, 'reasoning_budget_exhausted')) {
            return 'AI 在产生可见大纲前已耗尽推理预算，未保存结果。请增加推理预留或降低推理程度。';
        }

        if (str_contains($code, 'output_truncated')) {
            return 'AI 已开始返回大纲，但可见输出在完成前耗尽请求预算；未保存不完整结果。';
        }

        if (str_contains($code, 'completion_budget_exhausted')) {
            return 'AI 未返回完整大纲，且响应信息不足以判断预算耗在推理还是可见输出。请检查 Usage。';
        }

        return match ($code) {
            'queue_dispatch_failed' => '生成任务未能加入队列，请稍后继续。',
            'outline_worker_contract_mismatch' => 'Horizon Worker 尚未加载当前 Outline 合同，请重启 Horizon 后继续原批次。',
            'provider_timeout' => 'AI 服务响应超时，系统未收到完整结果。',
            'provider_connection_failed' => '暂时无法连接 AI 服务。',
            'provider_rate_limited' => 'AI 服务当前请求过多，请稍后继续。',
            'provider_authentication_failed', 'provider_not_configured', 'provider_disabled', 'provider_unsupported', 'provider_run_mismatch', 'provider_run_missing', 'provider_run_route_missing', 'outline_route_not_configured', 'model_run_mismatch' => 'Outline 独立路由配置不完整，请检查对应 Stage 的 Provider、Model 与推理程度。',
            'outline_stage_structured_output_invalid', 'outline_stage_schema_invalid' => 'AI 返回的大纲格式不符合要求，未保存该结果。',
            'outline_stage_domain_validation_failed' => 'AI 返回的大纲内容未通过业务校验。',
            'outline_stage_result_uncertain' => 'AI 结果保存状态无法确认，需要检查运行详情后继续。',
            'worker_interrupted', StalledRunRecoveryService::ERROR_CODE => '生成 Worker 已中断，可从最近成功阶段继续。',
            default => match ($metadata['category'] ?? null) {
                'external_temporary' => 'AI 服务暂时不可用，请稍后继续。',
                'infrastructure_temporary' => '生成基础设施暂时不可用，请稍后继续。',
                'structured_output' => 'AI 返回内容不符合结构要求，未保存该结果。',
                'reasoning_budget_exhausted' => 'AI 在产生可见结果前已耗尽推理预算。',
                'visible_output_truncated' => 'AI 的可见输出在完成前被截断。',
                'completion_budget_exhausted' => 'AI 已耗尽完成预算，但响应未提供足够分类信息。',
                'provider_configuration' => 'AI 服务配置不可用，请检查设置。',
                'worker_version_mismatch' => 'Horizon Worker 代码版本与当前 Outline 合同不一致，请重启后继续。',
                'worker_lost' => '生成 Worker 已中断，可从最近成功阶段继续。',
                default => '大纲生成未完成，请查看运行详情。',
            },
        };
    }

    /** @return array<string, string> */
    private function promptVersions(GenerationRun $batch): array
    {
        return collect(data_get($batch->context_snapshot, 'generation_preferences.prompt_versions', []))
            ->filter(fn (mixed $version, mixed $key): bool => is_string($key) && is_string($version))
            ->all();
    }

    /** @return array<string, mixed> */
    private function displayAttempt(GenerationRun $run, Collection $runs): int
    {
        $discriminator = data_get($run->context_snapshot, 'discriminator');
        if (! is_string($discriminator) || $discriminator === '') {
            return (int) $run->attempt;
        }

        // 旧批次的 attempt 是整个 Scope 的累计序号；展示时按相同 Arc / Beat 的 Run 顺序还原真实任务尝试次数。
        return $runs
            ->where('scope_type', $run->scope_type)
            ->filter(fn (GenerationRun $candidate): bool => data_get($candidate->context_snapshot, 'discriminator') === $discriminator
                && $candidate->getKey() <= $run->getKey())
            ->count();
    }

    private function runReference(GenerationRun $run, int $displayAttempt): array
    {
        $substageRoutes = data_get($run->context_snapshot, 'generation_preferences.substage_routes');
        $outlineRoutes = data_get($run->context_snapshot, 'generation_preferences.outline_routes');
        $frozenRoute = is_array($substageRoutes) && $substageRoutes !== []
            ? $substageRoutes
            : (is_array($outlineRoutes) && $outlineRoutes !== [] ? $outlineRoutes : null);
        $singleRoute = is_array($substageRoutes) && count($substageRoutes) === 1
            ? reset($substageRoutes)
            : null;
        $providerCalls = $run->usageRecords->map(fn ($usage): array => [
            'provider' => $usage->provider,
            'model' => $usage->model,
            'request_id' => $usage->request_id,
            'reasoning_tokens' => $usage->reasoning_tokens,
            'finish_reason' => data_get($usage->request_metadata, 'finish_reason'),
            'completion_limit_reason' => data_get($usage->request_metadata, 'completion_limit_reason'),
            'sent_parameters' => data_get($usage->request_metadata, 'sent_parameters'),
        ])->values()->all();

        return [
            'id' => $run->getKey(),
            'scope' => $run->scope_type,
            'discriminator' => data_get($run->context_snapshot, 'discriminator'),
            'status' => $run->status->value,
            'attempt' => $displayAttempt,
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'duration_ms' => $run->durationMilliseconds(),
            'provider' => $run->provider,
            'model' => $run->model_policy,
            'prompt_version' => $run->prompt_version,
            'usage' => [
                'input_tokens' => $run->usageRecords->sum('input_tokens'),
                'output_tokens' => $run->usageRecords->sum('output_tokens'),
                'reasoning_tokens' => $run->usageRecords->sum('reasoning_tokens'),
                'cached_tokens' => $run->usageRecords->sum('cached_tokens'),
                'estimated_cost' => (float) $run->usageRecords->sum('estimated_cost'),
            ],
            // 路由、静态容量、门禁快照和真实发送参数必须分开显示，避免把计划值误认为请求事实。
            'frozen_route' => $frozenRoute,
            'model_capacity' => is_array($singleRoute) ? ($singleRoute['model_capacity'] ?? null) : null,
            'request_budget' => data_get($run->context_snapshot, 'request_budget'),
            'capacity_snapshot' => data_get($run->context_snapshot, 'input.capacity_snapshot'),
            'provider_calls' => $providerCalls,
            'artifact_ids' => $run->artifacts->pluck('id')->all(),
            'error_code' => $run->error_code,
        ];
    }

    /** @return array<string, mixed> */
    private function artifactReference(GenerationArtifact $artifact): array
    {
        return [
            'id' => $artifact->getKey(),
            'generation_run_id' => $artifact->generation_run_id,
            'type' => $artifact->type->value,
            'version' => $artifact->version,
            'checksum' => $artifact->checksum,
        ];
    }

    private function payload(GenerationArtifact $artifact): array
    {
        $payload = data_get($artifact->data, 'payload');

        return is_array($payload) ? $payload : [];
    }

    /** @return array<int, string> */
    private function currentStageKeys(): array
    {
        return ['foundation', 'structure', 'arc_beats', 'skeleton_assembly', 'beat_detail', 'finalize'];
    }

    private function immutable(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::instance($value);
    }
}
