<?php

namespace App\Services;

use App\Actions\Chapters\RegenerateSceneSequenceAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Jobs\RepairSceneLengthJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Scene;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChapterAssembler
{
    public const ALGORITHM_VERSION = 'deterministic-assembly-v1';

    public const SEPARATOR = "\n\n";

    public function __construct(
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly GenerationRunCoordinator $runCoordinator,
        private readonly ContextBuilder $contextBuilder,
        private readonly GenerationFailurePolicy $failurePolicy,
        private readonly RegenerateSceneSequenceAction $regenerateScenes,
    ) {}

    public function assemble(int $chapterId, bool $regenerate = false): ?GenerationArtifact
    {
        $chapter = Chapter::query()->with([
            'novel.canonicalStateVersion', 'latestPlan', 'scenes.currentArtifact.generationRun',
        ])->findOrFail($chapterId);

        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Chapter Assembly。', false);
        }

        $candidate = $this->candidate($chapter, $this->sourceRows($chapter));
        [$run, $reused] = $this->startRun($chapter, $candidate, $regenerate);

        if ($reused) {
            return $run->artifacts()->where('type', ArtifactType::ChapterDraft)->latest('version')->first();
        }
        if (($continuitySceneId = $this->earliestContinuitySceneId($candidate)) !== null) {
            return $this->deferContinuityRecovery($run, $chapter, $continuitySceneId);
        }
        if (! $this->lengthIsValid($candidate)) {
            return $this->deferLengthRepair($run, $candidate);
        }

        try {
            return $this->complete($run, $chapter, $candidate);
        } catch (AiProviderException $exception) {
            $this->failurePolicy->record($run, $exception, 'chapter_assembly_failed');
            if (in_array($exception->errorCode, ['state_version_conflict', 'scene_artifact_conflict'], true)) {
                $this->recoverChangedScenes($chapter, $candidate);

                return null;
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->failurePolicy->record($run, $exception, 'chapter_assembly_failed');
            throw $exception;
        }
    }

    public function markTerminalFailure(int $chapterId): void
    {
        Chapter::query()->whereKey($chapterId)->update(['status' => ChapterStatus::Blocked]);
    }

    /** @return Collection<int, array{scene: Scene, artifact: GenerationArtifact}> */
    private function sourceRows(Chapter $chapter): Collection
    {
        if ($chapter->latestPlan === null || $chapter->scenes->isEmpty()) {
            throw new AiProviderException('assembly_input_incomplete', 'Chapter Assembly 缺少 Chapter Plan 或 Scenes。', false);
        }

        return $chapter->scenes->sortBy('sequence')->values()->map(function (Scene $scene) use ($chapter): array {
            $artifact = $scene->currentArtifact;
            if (! in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true)
                || $artifact === null
                || ! in_array($artifact->type, [ArtifactType::SceneDraft, ArtifactType::RewriteDraft], true)) {
                throw new AiProviderException('assembly_scene_incomplete', "Scene {$scene->sequence} 尚未成功，不能组装 Chapter。", false);
            }
            if ((int) $artifact->generationRun?->scene_id !== $scene->getKey()
                || (int) $artifact->generationRun?->chapter_id !== $chapter->getKey()) {
                throw new AiProviderException('assembly_scene_lineage_invalid', "Scene {$scene->sequence} 的当前 Artifact 不属于该 Scene/Chapter。", false);
            }
            if (! hash_equals($artifact->checksum, hash('sha256', (string) $artifact->content))) {
                throw new AiProviderException('assembly_scene_checksum_invalid', "Scene {$scene->sequence} 的当前 Artifact checksum 与正文不一致。", false);
            }

            return compact('scene', 'artifact');
        });
    }

    /**
     * @param  Collection<int, array{scene: Scene, artifact: GenerationArtifact}>  $sources
     * @return array<string, mixed>
     */
    private function candidate(Chapter $chapter, Collection $sources): array
    {
        $stateVersion = $chapter->novel->canonicalStateVersion?->version;
        if ($stateVersion === null) {
            throw new AiProviderException('assembly_context_incomplete', 'Chapter Assembly 缺少 Story State。', false);
        }

        $contract = $this->contextBuilder->foreshadowingContractForChapter($chapter);
        $contents = [];
        $coverage = [];
        $findings = [];

        foreach ($sources as $index => $source) {
            $scene = $source['scene'];
            $artifact = $source['artifact'];
            $content = trim((string) $artifact->content);
            if ($content === '') {
                throw new AiProviderException('assembly_scene_incomplete', "Scene {$scene->sequence} 正文为空，不能组装 Chapter。", false);
            }
            $selfCheck = PlanCoverage::validate(
                is_array(data_get($artifact->data, 'self_check')) ? data_get($artifact->data, 'self_check') : [],
                $content,
                "scene_coverage.{$index}",
            );
            $expectations = ForeshadowingCoverage::expectationsForScene($contract, (int) $scene->sequence);
            $foreshadowing = ForeshadowingCoverage::validate(
                is_array(data_get($artifact->data, 'foreshadowing_coverage')) ? data_get($artifact->data, 'foreshadowing_coverage') : [],
                $content,
                $expectations,
                "scene_coverage.{$index}.foreshadowing_coverage",
            );
            $scenePlan = data_get($chapter->latestPlan?->scene_plans, $index, []);
            $coverage[] = ['scene_id' => $scene->getKey(), ...$selfCheck, 'foreshadowing_coverage' => $foreshadowing];
            $findings = [
                ...$findings,
                ...PlanCoverage::findings(
                    $scene->getKey(), $selfCheck,
                    PlanCoverage::expectations($scene->only(PlanCoverage::ELEMENTS), is_array($scenePlan) ? $scenePlan : []),
                    'assembly_coverage',
                ),
                ...ForeshadowingCoverage::findings($scene->getKey(), $foreshadowing, $expectations, 'assembly_foreshadowing_coverage'),
                ...$this->continuityFindings($artifact, $scene),
            ];
            $contents[] = $content;
        }

        $content = implode(self::SEPARATOR, $contents);
        $target = (int) $chapter->latestPlan->target_words;
        $sourceData = $sources->map(fn (array $source): array => [
            'scene_id' => $source['scene']->getKey(),
            'sequence' => (int) $source['scene']->sequence,
            'artifact_id' => $source['artifact']->getKey(),
            'checksum' => $source['artifact']->checksum,
            'word_count' => $this->lengthPolicy->count($source['artifact']->content),
        ])->all();
        $assemblyHash = app(GenerationStageFingerprint::class)->make(
            GenerationStage::ChapterAssembly,
            ['separator' => self::SEPARATOR, 'sources' => $sourceData, 'content_checksum' => hash('sha256', $content)],
            upstreamChecksums: array_column($sourceData, 'checksum'),
            contractVersion: self::ALGORITHM_VERSION,
        );

        return [
            'content' => $content,
            'checksum' => hash('sha256', $content),
            'assembly_hash' => $assemblyHash,
            'state_version' => $stateVersion,
            'bible_version' => $this->contextBuilder->bibleVersionForChapter($chapter),
            'plan_id' => $chapter->latestPlan->getKey(),
            'plan_version' => $chapter->latestPlan->version,
            'sources' => $sourceData,
            'scene_coverage' => $coverage,
            'plan_findings' => $findings,
            'word_count' => $this->lengthPolicy->count($content),
            'target_words' => $target,
            'minimum_words' => $this->lengthPolicy->chapterMinimum($target),
            'maximum_words' => $this->lengthPolicy->chapterMaximum($target),
        ];
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Chapter $chapter, array $candidate, bool $regenerate): array
    {
        return DB::transaction(function () use ($chapter, $candidate): array {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = $chapter->generationRuns()->where('stage', GenerationStage::ChapterAssembly);
            $resolution = $this->runCoordinator->resolve($runs->getQuery(), $candidate['assembly_hash'], 'Assembly Run 超时未完成，已由后续投递恢复。');
            if ($resolution['reused']) {
                return [$resolution['run'], true];
            }
            $attempt = $resolution['attempt'];
            $baseKey = "assemble:{$chapter->getKey()}:{$candidate['assembly_hash']}:".self::ALGORITHM_VERSION;
            if ($chapter->status === ChapterStatus::Blocked) {
                $chapter->update(['status' => ChapterStatus::Generating]);
            }

            return [GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scene_id' => null,
                'scope_type' => 'chapter', 'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Running, 'attempt' => $attempt,
                'idempotency_key' => $attempt === 1 ? $baseKey : $baseKey.':attempt:'.$attempt,
                'input_hash' => $candidate['assembly_hash'], 'state_version' => $candidate['state_version'],
                'bible_version' => $candidate['bible_version'], 'prompt_version' => null, 'provider' => null, 'model_policy' => null,
                'context_snapshot' => [
                    'assembly_algorithm_version' => self::ALGORITHM_VERSION,
                    'assembly_hash' => $candidate['assembly_hash'],
                    'state_version' => $candidate['state_version'],
                    'chapter_plan_id' => $candidate['plan_id'],
                    'chapter_plan_version' => $candidate['plan_version'],
                    'ordered_sources' => $candidate['sources'],
                    'writing_constraints' => collect($candidate)->only(['target_words', 'minimum_words', 'maximum_words'])->all(),
                ],
                'started_at' => now(),
            ]), false];
        });
    }

    private function complete(GenerationRun $run, Chapter $chapter, array $candidate): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $chapter, $candidate): GenerationArtifact {
            $chapter = Chapter::query()->lockForUpdate()->with(['novel.canonicalStateVersion', 'latestPlan', 'scenes.currentArtifact.generationRun'])->findOrFail($chapter->getKey());
            if ($chapter->novel->canonicalStateVersion?->version !== $candidate['state_version']) {
                throw new AiProviderException('state_version_conflict', 'Assembly 保存前 Canonical Story State 已变化。', false);
            }
            $current = $this->sourceRows($chapter)->map(fn (array $source): array => [
                'scene_id' => $source['scene']->getKey(), 'sequence' => (int) $source['scene']->sequence,
                'artifact_id' => $source['artifact']->getKey(), 'checksum' => $source['artifact']->checksum,
                'word_count' => $this->lengthPolicy->count($source['artifact']->content),
            ])->all();
            if ($current !== $candidate['sources']) {
                throw new AiProviderException('scene_artifact_conflict', 'Assembly 保存前 Scene Artifact 已变化。', false);
            }

            $version = (int) GenerationArtifact::query()
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->where('type', ArtifactType::ChapterDraft)->max('version') + 1;
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::ChapterDraft, 'version' => $version, 'content' => $candidate['content'],
                'data' => [
                    'scene_coverage' => $candidate['scene_coverage'], 'plan_findings' => $candidate['plan_findings'],
                    'introduced_major_facts' => [],
                    'ordered_scene_ids' => array_column($candidate['sources'], 'scene_id'),
                    'ordered_artifact_ids' => array_column($candidate['sources'], 'artifact_id'),
                    'ordered_scene_checksums' => array_column($candidate['sources'], 'checksum'),
                    'assembly_algorithm_version' => self::ALGORITHM_VERSION,
                    'assembly_hash' => $candidate['assembly_hash'],
                    'word_count' => $candidate['word_count'], 'target_words' => $candidate['target_words'],
                    'minimum_words' => $candidate['minimum_words'], 'maximum_words' => $candidate['maximum_words'],
                ],
                'checksum' => $candidate['checksum'],
            ]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    private function lengthIsValid(array $candidate): bool
    {
        return $candidate['word_count'] >= $candidate['minimum_words']
            && $candidate['word_count'] <= $candidate['maximum_words'];
    }

    /** @return array<int, array<string, mixed>> */
    private function continuityFindings(GenerationArtifact $artifact, Scene $scene): array
    {
        $rows = [
            ...((array) data_get($artifact->data, 'continuity_findings', [])),
            ...((array) data_get($artifact->data, 'plan_findings', [])),
        ];

        return collect($rows)
            ->filter(fn (mixed $finding): bool => is_array($finding)
                && (($finding['dimension'] ?? null) === 'continuity'
                    || str_starts_with((string) ($finding['code'] ?? ''), 'CONTINUITY_')))
            ->map(fn (array $finding): array => [
                ...$finding,
                'scene_id' => $scene->getKey(),
                'source' => 'assembly_scene_continuity',
            ])
            ->values()
            ->all();
    }

    private function earliestContinuitySceneId(array $candidate): ?int
    {
        $finding = collect($candidate['plan_findings'])->first(
            fn (mixed $finding): bool => is_array($finding) && ($finding['source'] ?? null) === 'assembly_scene_continuity',
        );
        $sceneId = is_array($finding) ? ($finding['scene_id'] ?? null) : null;

        return is_int($sceneId) ? $sceneId : null;
    }

    private function deferContinuityRecovery(GenerationRun $run, Chapter $chapter, int $sceneId): ?GenerationArtifact
    {
        $scene = $chapter->scenes->firstWhere('id', $sceneId);
        if ($scene === null) {
            throw new AiProviderException('assembly_continuity_scene_invalid', '连续性 Finding 引用了非当前章节 Scene。', false);
        }
        $run->update([
            'status' => RunStatus::Failed,
            'error_code' => 'assembly_continuity_recovery_scheduled',
            'error_message' => "Scene {$scene->sequence} 存在连续性错误，已从该 Scene 开始级联恢复。",
            'error_retryable' => false,
            'error_metadata' => ['category' => 'targeted_repair', 'scene_id' => $sceneId],
            'finished_at' => now(),
        ]);
        $this->regenerateScenes->handle($scene);

        return null;
    }

    private function deferLengthRepair(GenerationRun $run, array $candidate): ?GenerationArtifact
    {
        if (collect($candidate['sources'])->contains(fn (array $source): bool => (bool) data_get(
            GenerationArtifact::query()->find($source['artifact_id'])?->data, 'assembly_length_repair', false,
        ))) {
            $exception = new AiProviderException(
                'assembly_length_out_of_range',
                "Scene 局部字数修复后章节仍为 {$candidate['word_count']} 字，要求 {$candidate['minimum_words']}～{$candidate['maximum_words']} 字。",
                false,
            );
            $this->failurePolicy->record($run, $exception, 'chapter_assembly_failed');
            throw $exception;
        }

        $mode = $candidate['word_count'] < $candidate['minimum_words'] ? 'expand' : 'compress';
        $source = $this->selectLengthRepairSource($candidate, $mode);
        $current = (int) $source['word_count'];
        if ($mode === 'expand') {
            $minimum = $current + ($candidate['minimum_words'] - $candidate['word_count']);
            $target = $current + max(0, $candidate['target_words'] - $candidate['word_count']);
            $maximum = $current + ($candidate['maximum_words'] - $candidate['word_count']);
        } else {
            $minimum = max(1, $current - ($candidate['word_count'] - $candidate['minimum_words']));
            $target = max($minimum, $current - max(0, $candidate['word_count'] - $candidate['target_words']));
            $maximum = max(1, $current - ($candidate['word_count'] - $candidate['maximum_words']));
        }
        $run->update([
            'status' => RunStatus::Failed,
            'error_code' => 'assembly_scene_length_repair_scheduled',
            'error_message' => "确定性拼章为 {$candidate['word_count']} 字，已选择 Scene {$source['sequence']} 执行{$mode}修复。",
            'error_retryable' => false,
            'error_metadata' => [
                'category' => 'targeted_repair', 'scene_id' => $source['scene_id'], 'mode' => $mode,
                'minimum_words' => $minimum, 'target_words' => $target, 'maximum_words' => $maximum,
            ],
            'finished_at' => now(),
        ]);
        RepairSceneLengthJob::dispatch(
            $source['scene_id'], $source['artifact_id'], $mode, $minimum, $target, $maximum, $candidate['assembly_hash'],
        );

        return null;
    }

    /** @return array<string, int|string> */
    private function selectLengthRepairSource(array $candidate, string $mode): array
    {
        $sceneTarget = max(1, (int) ceil($candidate['target_words'] / count($candidate['sources'])));

        return collect($candidate['sources'])->sortByDesc(fn (array $source): int => $mode === 'expand'
            ? max(0, $sceneTarget - (int) $source['word_count'])
            : max(0, (int) $source['word_count'] - $sceneTarget))->first();
    }

    private function recoverChangedScenes(Chapter $chapter, array $candidate): void
    {
        $chapter->refresh()->load('novel.canonicalStateVersion', 'scenes.currentArtifact');
        $expected = collect($candidate['sources'])->keyBy('scene_id');
        $firstChanged = $chapter->scenes->sortBy('sequence')->first(function (Scene $scene) use ($chapter, $candidate, $expected): bool {
            if ($chapter->novel->canonicalStateVersion?->version !== $candidate['state_version']) {
                return true;
            }
            $source = $expected->get($scene->getKey());

            return $source === null || $scene->current_artifact_id !== $source['artifact_id']
                || $scene->currentArtifact?->checksum !== $source['checksum'];
        });
        if ($firstChanged !== null) {
            $this->regenerateScenes->handle($firstChanged);
        }
    }
}
