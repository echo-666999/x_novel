<?php

namespace App\Services;

use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\PromptVersionResolver;
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
use Throwable;

class ChapterAssembler
{
    private const STALE_RUN_SECONDS = 120;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly PromptVersionResolver $promptVersionResolver,
    ) {}

    public function assemble(int $chapterId, bool $regenerate = false): ?GenerationArtifact
    {
        $chapter = Chapter::query()->with([
            'novel.canonicalStateVersion',
            'novel.currentBible',
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
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'model' => $settings->model,
            'prompt_version' => $promptVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $checksumHash = hash('sha256', implode('|', $context['ordered_scene_checksums']));
        $baseKey = "assemble:{$chapter->getKey()}:{$checksumHash}:{$promptVersion}";
        [$run, $reused] = $this->startRun(
            $chapter,
            $baseKey,
            $inputHash,
            $context,
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
                systemPrompt: 'You are XNovel ChapterAssembler. Assemble the supplied scenes into one polished chapter in the required narrative style and keep the result close to chapter_target_words. Preserve scene order and outcomes. Improve transitions, consistency, and repetition only. Do not introduce major facts, abilities, world rules, or knowledge.',
                prompt: 'Return only the complete chapter prose assembled from this input: '.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.3,
                maxTokens: (int) config('generation.assembly_max_output_tokens', 12_000),
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Assembler->value,
                ],
            ));
            $content = trim($response->content);

            if ($content === '') {
                throw new AiProviderException('assembly_empty_draft', 'Chapter Assembly 返回了空正文。', false);
            }

            return $this->complete(
                $run,
                $chapter,
                $content,
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

        return [
            'chapter_id' => $chapter->getKey(),
            'state_version' => $stateVersion,
            'chapter_plan' => $chapter->latestPlan->only([
                'id', 'version', 'chapter_function', 'arc_contribution', 'reader_promise', 'tone', 'hook_type',
                'must_reveal', 'may_hint', 'must_not_reveal', 'forbidden_conflicts',
            ]),
            'style_constraints' => $chapter->novel->currentBible?->only(['tone', 'pov', 'tense', 'taboos', 'hard_constraints']) ?? [],
            'writing_constraints' => [
                'chapter_target_words' => $chapter->latestPlan->target_words,
                'narrative_style' => (string) data_get($chapter->novel->settings, 'generation.narrative_style', $chapter->novel->currentBible?->tone),
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
    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $model, string $promptVersion, bool $regenerate): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $model, $promptVersion, $regenerate): array {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = $chapter->generationRuns()->where('stage', GenerationStage::ChapterAssembly);
            $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();

            if ($active !== null && $active->updated_at->gt(now()->subSeconds(self::STALE_RUN_SECONDS))) {
                return [$active, true];
            }

            if ($active !== null) {
                $active->update([
                    'status' => RunStatus::Failed,
                    'error_code' => 'worker_interrupted',
                    'error_message' => 'Assembly Run 超时未完成，已由后续投递恢复。',
                    'finished_at' => now(),
                ]);
            }

            if (! $regenerate && ($succeeded = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$succeeded, true];
            }

            $attempt = ((int) $runs->clone()->max('attempt')) + 1;
            $key = $attempt === 1 ? $baseKey : $baseKey.':attempt:'.$attempt;

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
                'bible_version' => $chapter->novel->currentBible?->version,
                'prompt_version' => $promptVersion,
                'model_policy' => $model,
                'context_snapshot' => $context,
                'started_at' => now(),
            ]), false];
        });
    }

    /** @param array<int, string> $expectedChecksums */
    private function complete(GenerationRun $run, Chapter $chapter, string $content, int $expectedStateVersion, array $expectedChecksums): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $chapter, $content, $expectedStateVersion, $expectedChecksums): GenerationArtifact {
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
                'content' => $content,
                'data' => ['ordered_scene_checksums' => $expectedChecksums],
                'checksum' => hash('sha256', $content),
            ]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    private function failRun(GenerationRun $run, Throwable $exception): void
    {
        $run->update([
            'status' => RunStatus::Failed,
            'error_code' => $exception instanceof AiProviderException ? $exception->errorCode : 'chapter_assembly_failed',
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
