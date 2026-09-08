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
    ) {}

    public function generate(int $sceneId, bool $regenerate = false): ?GenerationArtifact
    {
        $scene = Scene::query()->with(['chapter.novel.canonicalStateVersion', 'chapter.latestPlan'])->findOrFail($sceneId);
        $chapter = $scene->chapter;
        $novel = $chapter->novel;
        $plan = $chapter->latestPlan;

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
        $context['writing_constraints'] = [
            'chapter_target_words' => $plan->target_words,
            'scene_target_words' => (int) ceil($plan->target_words / max(1, $chapter->scenes()->count())),
            'style_profile' => $this->narrativeStyleProfile->forNovel($novel),
        ];
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'model' => $settings->model,
            'prompt_version' => $promptVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $baseKey = "scene:{$scene->getKey()}:{$inputHash}:{$promptVersion}:{$settings->model}";

        [$run, $reused] = $this->startRun($scene, $baseKey, $inputHash, $context, $settings->model, $promptVersion, $regenerate);

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
                systemPrompt: 'You are XNovel SceneWriter. Write only this scene in the required narrative style and approximately meet scene_target_words. Return JSON matching the schema. Draft output must never mutate canonical story state.',
                prompt: 'Generate the scene from this authoritative context: '.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
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

            return $this->complete($run, $scene, $payload, $snapshot->stateVersion);
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
    private function startRun(Scene $scene, string $baseKey, string $inputHash, array $context, string $model, string $promptVersion, bool $regenerate): array
    {
        return DB::transaction(function () use ($scene, $baseKey, $inputHash, $context, $model, $promptVersion, $regenerate): array {
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
                'context_snapshot' => $context,
                'started_at' => now(),
            ]);
            $scene->update(['status' => SceneStatus::Generating]);

            return [$run, false];
        });
    }

    /** @param array<string, mixed> $payload */
    private function complete(GenerationRun $run, Scene $scene, array $payload, int $expectedStateVersion): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $scene, $payload, $expectedStateVersion): GenerationArtifact {
            $scene = Scene::query()->lockForUpdate()->with('chapter.novel')->findOrFail($scene->getKey());
            $currentVersion = $scene->chapter->novel->canonicalStateVersion()->value('version');

            if ($currentVersion !== $expectedStateVersion) {
                throw new AiProviderException('state_version_conflict', 'Scene 生成期间 Canonical Story State 已变化。', false);
            }

            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::SceneDraft,
                'version' => 1,
                'content' => $payload['content'],
                'data' => collect($payload)->except('content')->all(),
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
