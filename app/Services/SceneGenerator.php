<?php

namespace App\Services;

use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\PromptVersionResolver;
use App\Data\ContextRequest;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SceneGenerator
{
    private const STALE_RUN_SECONDS = 120;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly PromptVersionResolver $promptVersionResolver,
        private readonly ContextBuilder $contextBuilder,
        private readonly NarrativeStyleProfile $narrativeStyleProfile,
        private readonly DraftLengthPolicy $lengthPolicy,
    ) {}

    public function generate(int $sceneId, bool $regenerate = false, ?string $regenerationBatchId = null): ?GenerationArtifact
    {
        $scene = Scene::query()->with([
            'chapter.novel.canonicalStateVersion',
            'chapter.latestPlan',
            'chapter.scenes.currentArtifact',
        ])->findOrFail($sceneId);
        $chapter = $scene->chapter;
        $novel = $chapter->novel;
        $plan = $chapter->latestPlan;

        if ($chapter->status === ChapterStatus::Canonical) {
            throw new AiProviderException('canonical_scene_immutable', '正式章节不能直接重新生成场景，请先回滚该章节。', false);
        }

        if ($novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始新的 Scene 生成阶段。', false);
        }

        if ($plan === null || $novel->canonicalStateVersion === null) {
            throw new AiProviderException('scene_context_incomplete', 'Scene 生成缺少 Chapter Plan 或 Story State。', false);
        }

        $previousArtifacts = $this->previousArtifacts($scene);
        $settings = $this->settingsResolver->resolve(AiStage::Writer, $novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Writer);
        $previousArtifact = $previousArtifacts->last();
        $snapshot = $this->contextBuilder->build(new ContextRequest(
            novelId: $novel->getKey(),
            chapterId: $chapter->getKey(),
            sceneId: $scene->getKey(),
            taskType: GenerationStage::SceneGeneration->value,
            stateVersion: $novel->canonicalStateVersion->version,
            chapterPlanId: $plan->getKey(),
            tokenBudget: (int) config('generation.scene_context_token_budget', 12_000),
            promptVersion: $promptVersion,
            model: $settings->model,
            previousArtifactId: $previousArtifact?->getKey(),
        ));
        $context = $snapshot->toArray();
        $context['temporary_state'] = $this->temporaryState($previousArtifacts->all());
        $context['previous_scene_tail'] = $this->previousSceneTail($previousArtifact);
        $context['scene_task'] = $scene->only([
            'id', 'sequence', 'pov_character_id', 'location', 'time_anchor', 'goal', 'conflict', 'turn', 'outcome',
        ]);
        $scenePlan = data_get($plan->scene_plans, $scene->sequence - 1, []);
        $context['scene_task']['transition_from_previous'] = data_get($scenePlan, 'transition_from_previous');
        $context['writing_constraints'] = [
            ...$this->sceneAllocation($scene, (int) $plan->target_words),
            'style_profile' => $this->narrativeStyleProfile->forNovel($novel),
        ];
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'model' => $settings->model,
            'prompt_version' => $promptVersion,
            'regeneration_batch_id' => $regenerationBatchId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $baseKey = "scene:{$scene->getKey()}:{$inputHash}:{$promptVersion}:{$settings->model}";

        [$run, $reused] = $this->startRun($scene, $baseKey, $inputHash, $context, $settings->model, $promptVersion, $regenerate, $regenerationBatchId);

        if ($reused) {
            $artifact = $run->artifacts()->where('type', ArtifactType::SceneDraft)->latest('id')->first();

            if ($artifact !== null && $scene->current_artifact_id !== $artifact->getKey()) {
                $scene->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);
            }

            return $artifact;
        }

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                systemPrompt: '你是 XNovel 场景写作器。只写当前场景，严格遵守指定文风。第一场景必须从 previous_chapter_ending 连续展开，并把 scene_task.transition_from_previous 指定的时间、地点与行动过渡写进正文；不得从上一章结尾直接跳到次日或新地点而省略关键过程。scene_target_words 是当前场景目标字数，maximum_scene_words 是不可超过的硬上限；字数统计排除空白和换行。当 required_scene_words 大于 0 时，正文还必须至少达到该字数，使各场景总量达到章节下限。场景可以短于目标，未使用的字数由后续场景承接。通过完整的动作、对话、环境、感官和人物反应展开既定场景，不得用提纲、摘要、无意义重复或新增重大事实凑字。返回符合 Schema 的 JSON；除固定字段和枚举值外，正文及所有自然语言内容必须使用简体中文。草稿不得修改正式故事状态。',
                prompt: '请根据以下权威上下文生成当前场景：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.7,
                maxTokens: (int) config('generation.scene_max_output_tokens', 4_000),
                responseSchema: SceneDraftPayload::schema(),
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $novel->getKey(),
                    'chapter_id' => $chapter->getKey(),
                    'scene_id' => $scene->getKey(),
                    'stage' => AiStage::Writer->value,
                ],
            ));

            if ($response->structuredData === null) {
                throw new AiProviderException('scene_schema_invalid', 'AI 未返回合法的结构化 Scene Draft。', false);
            }

            $payload = SceneDraftPayload::validate($response->structuredData);
            $this->validatePlanConstraints($payload['content'], $plan->must_not_reveal ?? []);
            $payload = $this->repairLengthIfNeeded(
                payload: $payload,
                context: $context,
                model: $settings->model,
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $novel->getKey(),
                    'chapter_id' => $chapter->getKey(),
                    'scene_id' => $scene->getKey(),
                    'stage' => AiStage::Writer->value,
                ],
            );
            $this->validatePlanConstraints($payload['content'], $plan->must_not_reveal ?? []);
            $this->validateLength($payload['content'], $context['writing_constraints']);

            return $this->complete($run, $scene, $payload, $snapshot->stateVersion, $context['writing_constraints']);
        } catch (Throwable $exception) {
            $this->failRun($run, $exception);
            throw $exception;
        }
    }

    public function markTerminalFailure(int $sceneId, Throwable $exception): void
    {
        DB::transaction(function () use ($sceneId): void {
            $scene = Scene::query()->lockForUpdate()->with('chapter')->findOrFail($sceneId);
            $scene->update(['status' => SceneStatus::Failed]);
            $scene->chapter->update(['status' => ChapterStatus::Blocked]);
        });
    }

    private function previousArtifacts(Scene $scene)
    {
        $previousScenes = $scene->chapter->scenes()
            ->where('sequence', '<', $scene->sequence)
            ->reorder('sequence')
            ->get();
        $incomplete = $previousScenes->first(fn (Scene $previous): bool => $previous->current_artifact_id === null
            || ! in_array($previous->status, [SceneStatus::Draft, SceneStatus::Accepted], true));

        if ($incomplete !== null) {
            throw new AiProviderException(
                'previous_scene_incomplete',
                "Scene {$scene->sequence} 必须等待 Scene {$incomplete->sequence} 成功后才能执行。",
                false,
            );
        }

        return GenerationArtifact::query()
            ->whereIn('id', $previousScenes->pluck('current_artifact_id'))
            ->get()
            ->sortBy(fn (GenerationArtifact $artifact): int => (int) $previousScenes
                ->firstWhere('current_artifact_id', $artifact->getKey())?->sequence)
            ->values();
    }

    /** @param array<int, GenerationArtifact> $artifacts
     * @return array<string, mixed>
     */
    private function temporaryState(array $artifacts): array
    {
        $state = [];

        foreach ($artifacts as $artifact) {
            $state = array_replace_recursive($state, data_get($artifact->data, 'temporary_state_delta', []));
        }

        return $state;
    }

    private function previousSceneTail(?GenerationArtifact $artifact): ?string
    {
        if (blank($artifact?->content)) {
            return null;
        }

        return mb_substr($artifact->content, -(int) config('generation.previous_scene_tail_characters', 1_000));
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Scene $scene, string $baseKey, string $inputHash, array $context, string $model, string $promptVersion, bool $regenerate, ?string $regenerationBatchId): array
    {
        return DB::transaction(function () use ($scene, $baseKey, $inputHash, $context, $model, $promptVersion, $regenerate, $regenerationBatchId): array {
            $scene = Scene::query()->lockForUpdate()->findOrFail($scene->getKey());
            $runs = $scene->generationRuns()->where('stage', GenerationStage::SceneGeneration);
            $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();

            if ($active !== null && $active->updated_at->gt(now()->subSeconds(self::STALE_RUN_SECONDS))) {
                return [$active, true];
            }

            if ($active !== null) {
                $active->update([
                    'status' => RunStatus::Failed,
                    'error_code' => 'worker_interrupted',
                    'error_message' => 'Scene Run 超时未完成，已由后续投递恢复。',
                    'finished_at' => now(),
                ]);
            }

            if (! $regenerate && ($succeeded = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$succeeded, true];
            }

            $attempt = ((int) $runs->clone()->max('attempt')) + 1;
            $key = $attempt === 1 ? $baseKey : $baseKey.':attempt:'.$attempt;
            $run = GenerationRun::query()->create([
                'novel_id' => $scene->chapter->novel_id,
                'chapter_id' => $scene->chapter_id,
                'scene_id' => $scene->getKey(),
                'scope_type' => 'scene',
                'scope_id' => $scene->getKey(),
                'stage' => GenerationStage::SceneGeneration,
                'status' => RunStatus::Running,
                'attempt' => $attempt,
                'idempotency_key' => $key,
                'input_hash' => $inputHash,
                'state_version' => data_get($context, 'state_version'),
                'bible_version' => data_get($context, 'bible_version'),
                'prompt_version' => $promptVersion,
                'model_policy' => $model,
                'context_snapshot' => [
                    ...$context,
                    'regeneration_batch_id' => $regenerationBatchId,
                ],
                'started_at' => now(),
            ]);
            $scene->update(['status' => SceneStatus::Generating]);
            $scene->chapter()->update(['status' => ChapterStatus::Generating]);

            return [$run, false];
        });
    }

    /** @param array<string, mixed> $payload */
    private function complete(GenerationRun $run, Scene $scene, array $payload, int $expectedStateVersion, array $writingConstraints): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $scene, $payload, $expectedStateVersion, $writingConstraints): GenerationArtifact {
            $scene = Scene::query()->lockForUpdate()->with('chapter.novel')->findOrFail($scene->getKey());
            $currentVersion = $scene->chapter->novel->canonicalStateVersion()->value('version');

            if ($currentVersion !== $expectedStateVersion) {
                throw new AiProviderException('state_version_conflict', 'Scene 生成期间 Canonical Story State 已变化。', false);
            }

            $version = GenerationArtifact::query()
                ->where('type', ArtifactType::SceneDraft)
                ->whereHas('generationRun', fn ($query) => $query
                    ->where('scene_id', $scene->getKey())
                    ->where('stage', GenerationStage::SceneGeneration))
                ->max('version');

            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::SceneDraft,
                'version' => ((int) $version) + 1,
                'content' => $payload['content'],
                'data' => [
                    ...collect($payload)->except('content')->all(),
                    'word_count' => $this->lengthPolicy->count($payload['content']),
                    'target_words' => $writingConstraints['scene_target_words'],
                    'required_words' => $writingConstraints['required_scene_words'],
                    'allocated_scene_words' => $writingConstraints['allocated_scene_words'],
                    'remaining_scene_count' => $writingConstraints['remaining_scene_count'],
                ],
                'checksum' => hash('sha256', $payload['content']),
            ]);
            $scene->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    /** @param array<int, string> $mustNotReveal */
    private function validatePlanConstraints(string $content, array $mustNotReveal): void
    {
        foreach ($mustNotReveal as $forbidden) {
            if (filled($forbidden) && mb_stripos($content, (string) $forbidden) !== false) {
                throw new AiProviderException('scene_plan_violation', "Scene 正文包含禁止揭示内容：{$forbidden}", false);
            }
        }
    }

    /** @param array<string, mixed> $writingConstraints */
    private function validateLength(string $content, array $writingConstraints): void
    {
        $actual = $this->lengthPolicy->count($content);
        $required = (int) $writingConstraints['required_scene_words'];

        $maximum = (int) $writingConstraints['maximum_scene_words'];

        if ($actual > $maximum) {
            throw new AiProviderException(
                'scene_budget_exceeded',
                "当前场景 {$actual} 字，最多允许 {$maximum} 字。",
                false,
            );
        }

        if ($required > 0 && $actual < $required) {
            $chapterTotal = (int) $writingConstraints['allocated_scene_words'] + $actual;

            throw new AiProviderException(
                'scene_budget_shortfall',
                "当前已是最后一个待生成场景；生成后本章场景合计 {$chapterTotal} 字，章节目标 {$writingConstraints['chapter_target_words']} 字，至少需要达到 {$writingConstraints['chapter_minimum_words']} 字。当前场景至少需要 {$required} 字。",
                false,
            );
        }
    }

    /** @param array<string, mixed> $payload
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function repairLengthIfNeeded(array $payload, array $context, string $model, string $promptVersion, array $metadata): array
    {
        $constraints = $context['writing_constraints'];
        $required = (int) $constraints['required_scene_words'];
        $maximum = (int) $constraints['maximum_scene_words'];

        for ($attempt = 1; $attempt <= (int) config('generation.max_length_repair_attempts', 1); $attempt++) {
            $actual = $this->lengthPolicy->count($payload['content']);
            $tooShort = $required > 0 && $actual < $required;
            $tooLong = $actual > $maximum;

            if (! $tooShort && ! $tooLong) {
                break;
            }

            $response = $this->provider->generate(new AiRequest(
                model: $model,
                systemPrompt: $tooLong
                    ? '你是 XNovel 场景压缩器。输入包含一份字数超限的场景草稿。请在不改变场景目标、冲突、转折、结果和既定事实的前提下，删除重复解释、重复感受和不推动情节的细节，返回完整替换稿。最终正文不得超过 maximum_scene_words；字数统计排除空白和换行。不得截断句子，不得输出摘要或解释，不得新增重大事实。返回符合 Schema 的 JSON，所有自然语言使用简体中文。'
                    : '你是 XNovel 场景扩写器。输入包含一份字数不足的场景草稿。请在不改变场景目标、冲突、转折、结果和既定事实的前提下，将它扩写为完整替换稿。必须保留原有有效内容，通过动作过程、对话反应、环境感官、人物心理和自然过渡补足细节。完整正文至少达到 required_scene_words，并尽量接近 scene_target_words，且不得超过 maximum_scene_words；字数统计排除空白和换行。不得输出提纲、摘要、解释或无意义重复，不得新增重大事实、能力、世界规则或角色知识。返回符合 Schema 的 JSON，所有自然语言使用简体中文。',
                prompt: ($tooLong ? '请压缩以下超限场景：' : '请扩写以下短稿：').json_encode([
                    'scene_task' => $context['scene_task'],
                    'writing_constraints' => $constraints,
                    'must_not_reveal' => data_get($context, 'l0.plan_constraints.must_not_reveal', []),
                    'repair_attempt' => $attempt,
                    'current_words' => $actual,
                    'required_reduction_words' => $tooLong ? $actual - $maximum : 0,
                    'draft' => $payload,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.4,
                maxTokens: (int) config('generation.scene_max_output_tokens', 4_000),
                responseSchema: SceneDraftPayload::schema(),
                promptVersion: $promptVersion,
                metadata: [...$metadata, 'length_repair_attempt' => $attempt, 'length_repair_mode' => $tooLong ? 'compress' : 'expand'],
            ));

            if ($response->structuredData === null) {
                throw new AiProviderException('scene_schema_invalid', 'AI 场景扩写未返回合法的结构化 Scene Draft。', false);
            }

            $payload = SceneDraftPayload::validate($response->structuredData);
        }

        return $payload;
    }

    /** @return array<string, int> */
    private function sceneAllocation(Scene $scene, int $chapterTarget): array
    {
        $otherScenes = $scene->chapter->scenes->where('id', '!=', $scene->getKey());
        $allocatedWords = $otherScenes->sum(fn (Scene $other): int => $this->lengthPolicy->count($other->currentArtifact?->content));
        $remainingSceneCount = 1 + $otherScenes->whereNull('current_artifact_id')->count();

        return $this->lengthPolicy->sceneAllocation($chapterTarget, $allocatedWords, $remainingSceneCount);
    }

    private function failRun(GenerationRun $run, Throwable $exception): void
    {
        $code = $exception instanceof AiProviderException
            ? $exception->errorCode
            : ($exception instanceof ValidationException ? 'scene_validation_failed' : 'scene_generation_failed');
        $run->update([
            'status' => RunStatus::Failed,
            'error_code' => $code,
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
