<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\NarrativeProsePolicy;
use App\AI\StructuredOutput;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SceneLengthRepairer
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly PlanAdmissionService $planAdmission,
        private readonly ContextBuilder $contextBuilder,
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly GenerationFailurePolicy $failurePolicy,
        private readonly GenerationOutputCapacityGuard $outputCapacity,
    ) {}

    public function repair(
        int $sceneId,
        int $sourceArtifactId,
        string $mode,
        int $minimumWords,
        int $targetWords,
        int $maximumWords,
        string $assemblyHash,
    ): ?GenerationArtifact {
        if (! in_array($mode, ['expand', 'compress'], true)
            || $minimumWords < 1 || $targetWords < $minimumWords || $maximumWords < $targetWords) {
            throw new AiProviderException('scene_length_repair_invalid', 'Scene 字数修复边界无效。', false);
        }

        $scene = Scene::query()->with([
            'chapter.novel.canonicalStateVersion', 'chapter.latestPlan', 'currentArtifact.generationRun',
        ])->findOrFail($sceneId);
        $chapter = $scene->chapter;
        $source = $scene->currentArtifact;
        if ($source === null || $source->getKey() !== $sourceArtifactId) {
            throw new AiProviderException('scene_artifact_conflict', 'Scene 字数修复来源已不是当前 Artifact。', false);
        }

        $plan = $this->planAdmission->admit($chapter->latestPlan);
        $settings = $this->planAdmission->routeFor($plan, AiStage::Rewrite);
        $promptVersion = $this->planAdmission->promptVersionFor($plan, AiStage::Rewrite);
        $stateVersion = $chapter->novel->canonicalStateVersion?->version;
        if ($stateVersion === null) {
            throw new AiProviderException('scene_length_repair_context_incomplete', 'Scene 字数修复缺少 Canonical Story State。', false);
        }
        $contract = $this->contextBuilder->foreshadowingContractForChapter($chapter);
        $expectations = ForeshadowingCoverage::expectationsForScene($contract, (int) $scene->sequence);
        $rewriteRoute = $this->outputCapacity->frozenRoute($chapter, AiStage::Rewrite);
        $repairMaxTokens = (int) config('generation.scene_length_repair_max_output_tokens', 4_000);
        $repairBudget = [
            'output_tokens' => $repairMaxTokens,
            'reasoning_reserve_tokens' => 0,
            'max_completion_tokens' => $repairMaxTokens,
        ];
        $context = [
            'scope' => 'scene',
            'repair_reason' => 'deterministic_assembly_length',
            'repair_mode' => $mode,
            'scene' => $scene->only(['id', 'sequence', 'goal', 'conflict', 'turn', 'outcome']),
            'scene_plan' => data_get($plan->scene_plans, $scene->sequence - 1, []),
            'source_artifact_id' => $source->getKey(),
            'source_checksum' => $source->checksum,
            'source_content' => $source->content,
            'source_self_check' => data_get($source->data, 'self_check'),
            'source_foreshadowing_coverage' => data_get($source->data, 'foreshadowing_coverage', []),
            'foreshadowing_contract' => $contract,
            'l4' => $this->contextBuilder->styleContractForChapter($chapter),
            'length_requirement' => [
                'minimum_words' => $minimumWords,
                'target_words' => $targetWords,
                'maximum_words' => $maximumWords,
            ],
            'assembly_hash' => $assemblyHash,
            'state_version' => $stateVersion,
            // 独立修复 Run 必须把实际预算和模型容量纳入 input hash，Resume 时不得重新读取新配置。
            'generation_preferences' => [
                'request_budget' => $repairBudget,
                'model_capacity' => $rewriteRoute['model_capacity'],
            ],
        ];
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'prompt_version' => $promptVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        [$run, $reused] = $this->startRun($scene, $context, $inputHash, $settings->provider, $settings->model, $promptVersion);
        if ($reused) {
            return $run->artifacts()->where('type', ArtifactType::RewriteDraft)->latest('version')->first();
        }

        try {
            $request = new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                reasoningEffort: $settings->reasoningEffort,
                systemPrompt: ($mode === 'expand'
                    ? '你是 XNovel Scene 局部扩写器。只扩写当前 Scene 的完整替换稿，通过原有动作、对话、环境、感官、心理和过渡补足字数。'
                    : '你是 XNovel Scene 局部压缩器。只压缩当前 Scene 的完整替换稿，删除重复解释、感受、争论和不推动剧情的细节。')
                    .'不得改变 goal、conflict、turn、outcome、既定事实或伏笔动作，不得加入其他 Scene 内容。必须按冻结身份返回 self_check 和 foreshadowing_coverage，证据逐字来自最终 content。正文必须落在 length_requirement 的硬边界内。'.NarrativeProsePolicy::writing(),
                prompt: '请执行一次有界 Scene 字数修复：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: $repairBudget['max_completion_tokens'],
                responseSchema: SceneLengthRepairPayload::schema(),
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(), 'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(), 'scene_id' => $scene->getKey(),
                    'stage' => AiStage::Rewrite->value, 'substage' => 'assembly_length_repair',
                ],
            );
            $this->outputCapacity->assertRequestWithinFrozenRoute(
                $chapter,
                $run,
                AiStage::Rewrite,
                $request,
                'assembly_length_repair',
                $repairBudget,
            );
            $response = $this->provider->generate($request);
            $payload = SceneLengthRepairPayload::validate(
                StructuredOutput::require($response, 'scene_length_repair', 'Scene Length Repair'),
                $expectations,
            );
            $this->validateLengthAndDirection($payload['content'], (string) $source->content, $mode, $minimumWords, $maximumWords);

            return $this->complete($run, $scene, $source, $payload, $context);
        } catch (Throwable $exception) {
            $this->failurePolicy->record($run, $exception, 'scene_length_repair_failed');
            throw $exception;
        }
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Scene $scene, array $context, string $inputHash, string $provider, string $model, string $promptVersion): array
    {
        return DB::transaction(function () use ($scene, $context, $inputHash, $provider, $model, $promptVersion): array {
            $scene = Scene::query()->lockForUpdate()->with('chapter')->findOrFail($scene->getKey());
            $key = "rewrite:assembly-length:{$scene->getKey()}:{$inputHash}";
            $existing = GenerationRun::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return [$existing, $existing->status === RunStatus::Succeeded];
            }

            return [GenerationRun::query()->create([
                'novel_id' => $scene->chapter->novel_id, 'chapter_id' => $scene->chapter_id,
                'scene_id' => $scene->getKey(), 'scope_type' => 'scene', 'scope_id' => $scene->getKey(),
                'stage' => GenerationStage::Rewrite, 'status' => RunStatus::Running, 'attempt' => 1,
                'idempotency_key' => $key, 'input_hash' => $inputHash,
                'state_version' => $context['state_version'],
                'bible_version' => data_get($context, 'l4.bible_version'),
                'prompt_version' => $promptVersion, 'provider' => $provider, 'model_policy' => $model,
                'context_snapshot' => collect($context)->except('source_content')->all(), 'started_at' => now(),
            ]), false];
        });
    }

    private function complete(GenerationRun $run, Scene $scene, GenerationArtifact $source, array $payload, array $context): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $scene, $source, $payload, $context): GenerationArtifact {
            $scene = Scene::query()->lockForUpdate()->with('chapter.novel.canonicalStateVersion')->findOrFail($scene->getKey());
            if ($scene->current_artifact_id !== $source->getKey()) {
                throw new AiProviderException('scene_artifact_conflict', 'Scene 字数修复期间当前 Artifact 已变化。', false);
            }
            if ($scene->chapter->novel->canonicalStateVersion?->version !== $context['state_version']) {
                throw new AiProviderException('state_version_conflict', 'Scene 字数修复期间 Canonical Story State 已变化。', false);
            }
            $version = (int) GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)
                ->whereHas('generationRun', fn ($query) => $query->where('scene_id', $scene->getKey()))->max('version') + 1;
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::RewriteDraft, 'version' => $version, 'content' => $payload['content'],
                'data' => [
                    'scope' => 'scene', 'source_artifact_id' => $source->getKey(),
                    'assembly_length_repair' => true, 'assembly_hash' => $context['assembly_hash'],
                    'repair_mode' => $context['repair_mode'],
                    'self_check' => $payload['self_check'],
                    'foreshadowing_coverage' => $payload['foreshadowing_coverage'],
                    'temporary_state_delta' => data_get($source->data, 'temporary_state_delta', []),
                    'declared_events' => data_get($source->data, 'declared_events', []),
                    'uncertainties' => data_get($source->data, 'uncertainties', []),
                    'word_count' => $this->lengthPolicy->count($payload['content']),
                    ...$context['length_requirement'],
                ],
                'checksum' => hash('sha256', $payload['content']),
            ]);
            $scene->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);
            $scene->chapter->update(['status' => ChapterStatus::Generating]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    private function validateLengthAndDirection(string $content, string $source, string $mode, int $minimum, int $maximum): void
    {
        $actual = $this->lengthPolicy->count($content);
        $sourceWords = $this->lengthPolicy->count($source);
        $directionValid = $mode === 'expand' ? $actual > $sourceWords : $actual < $sourceWords;
        if (! $directionValid || $actual < $minimum || $actual > $maximum) {
            throw new AiProviderException(
                'scene_length_repair_out_of_range',
                "Scene {$mode} 修复结果 {$actual} 字，来源 {$sourceWords} 字，要求 {$minimum}～{$maximum} 字。",
                false,
            );
        }
    }
}
