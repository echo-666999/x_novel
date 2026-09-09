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
        private readonly NarrativeStyleProfile $narrativeStyleProfile,
        private readonly DraftLengthPolicy $lengthPolicy,
        private readonly PreviousChapterEnding $previousChapterEnding,
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
                systemPrompt: '你是 XNovel 章节组装器。将给定场景组装成一章完整、流畅的简体中文正文，遵守指定文风。开头必须与 previous_chapter_ending 连续，并保留 chapter_plan.scene_plans[0].transition_from_previous 对时间、地点和行动过渡的交代。必须保留各场景的目标、冲突、转折和结果；对重复动作、重复解释和重复感受应主动合并。成稿必须达到 chapter_minimum_words，并尽量接近 chapter_target_words，chapter_maximum_words 是不可超过的硬上限；字数统计排除空白和换行。可以补足必要的场景衔接，但不得用无意义重复凑字，不得把正文压缩成摘要，也不得新增重大事实、能力、世界规则或角色知识。保持场景顺序和结果，只返回完整章节正文。',
                prompt: '请组装以下场景并只返回完整的简体中文章节正文：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
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

            $content = $this->repairLengthIfNeeded(
                content: $content,
                context: $context,
                model: $settings->model,
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Assembler->value,
                ],
            );
            $this->validateLength($content, $context['writing_constraints']);

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

        $targetWords = (int) $chapter->latestPlan->target_words;

        return [
            'chapter_id' => $chapter->getKey(),
            'state_version' => $stateVersion,
            'chapter_plan' => $chapter->latestPlan->only([
                'id', 'version', 'chapter_function', 'arc_contribution', 'reader_promise', 'tone', 'hook_type',
                'must_reveal', 'may_hint', 'must_not_reveal', 'forbidden_conflicts', 'scene_plans',
            ]),
            'previous_chapter_ending' => $this->previousChapterEnding->for($chapter),
            'style_constraints' => $chapter->novel->currentBible?->only(['tone', 'pov', 'tense', 'taboos', 'hard_constraints']) ?? [],
            'writing_constraints' => [
                'chapter_target_words' => $targetWords,
                'chapter_minimum_words' => $this->lengthPolicy->chapterMinimum($targetWords),
                'chapter_maximum_words' => $this->lengthPolicy->chapterMaximum($targetWords),
                'source_scene_words' => $artifacts->sum(fn (GenerationArtifact $artifact): int => $this->lengthPolicy->count($artifact->content)),
                'style_profile' => $this->narrativeStyleProfile->forNovel($chapter->novel),
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
                'data' => [
                    'ordered_scene_checksums' => $expectedChecksums,
                    'word_count' => $this->lengthPolicy->count($content),
                    'target_words' => (int) data_get($run->context_snapshot, 'writing_constraints.chapter_target_words'),
                    'minimum_words' => (int) data_get($run->context_snapshot, 'writing_constraints.chapter_minimum_words'),
                    'maximum_words' => (int) data_get($run->context_snapshot, 'writing_constraints.chapter_maximum_words'),
                ],
                'checksum' => hash('sha256', $content),
            ]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    /** @param array<string, mixed> $context
     * @param  array<string, mixed>  $metadata
     */
    private function repairLengthIfNeeded(string $content, array $context, string $model, string $promptVersion, array $metadata): string
    {
        $constraints = $context['writing_constraints'];

        for ($attempt = 1; $attempt <= (int) config('generation.max_length_repair_attempts', 1); $attempt++) {
            $actual = $this->lengthPolicy->count($content);
            $minimum = (int) $constraints['chapter_minimum_words'];
            $maximum = (int) $constraints['chapter_maximum_words'];
            $tooShort = $actual < $minimum;
            $tooLong = $actual > $maximum;

            if (! $tooShort && ! $tooLong) {
                break;
            }

            $response = $this->provider->generate(new AiRequest(
                model: $model,
                systemPrompt: $tooLong
                    ? '你是 XNovel 章节压缩器。将超限草稿压缩为完整章节，保留计划中的场景目标、冲突、转折、结果、必要连续性和正式事实。删除重复解释、重复感受、重复争论与不推动情节的细节。最终正文应接近 chapter_target_words，且不得超过 chapter_maximum_words；字数统计排除空白和换行。不得截断句子，不得输出摘要或解释，不得新增重大事实。只返回完整简体中文正文。'
                    : '你是 XNovel 章节扩写器。将过短草稿扩写为完整章节，保留计划和既定事实，通过既定场景内的动作、对话、环境、感官、心理和自然过渡补足。最终正文至少达到 chapter_minimum_words，并尽量接近 chapter_target_words，且不得超过 chapter_maximum_words；字数统计排除空白和换行。不得无意义重复，不得新增重大事实。只返回完整简体中文正文。',
                prompt: ($tooLong ? '请压缩以下超限章节：' : '请扩写以下过短章节：').json_encode([
                    'chapter_plan' => $context['chapter_plan'],
                    'writing_constraints' => $constraints,
                    'current_words' => $actual,
                    'required_reduction_words' => $tooLong ? $actual - $maximum : 0,
                    'repair_attempt' => $attempt,
                    'content' => $content,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: (int) config('generation.assembly_max_output_tokens', 12_000),
                promptVersion: $promptVersion,
                metadata: [...$metadata, 'length_repair_attempt' => $attempt, 'length_repair_mode' => $tooLong ? 'compress' : 'expand'],
            ));
            $content = trim($response->content);

            if ($content === '') {
                throw new AiProviderException('assembly_empty_draft', 'Chapter Assembly 字数修复返回了空正文。', false);
            }
        }

        return $content;
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
        $run->update([
            'status' => RunStatus::Failed,
            'error_code' => $exception instanceof AiProviderException ? $exception->errorCode : 'chapter_assembly_failed',
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
