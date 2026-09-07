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
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChapterRewriter
{
    private const STALE_RUN_SECONDS = 120;

    public function __construct(private readonly AiProvider $provider, private readonly AiSettingsResolver $settingsResolver, private readonly PromptVersionResolver $promptVersionResolver) {}

    public function rewrite(int $chapterId, ?int $sceneId = null): ?GenerationArtifact
    {
        $chapter = Chapter::query()->with(['novel.canonicalStateVersion', 'latestPlan'])->findOrFail($chapterId);
        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Rewrite。', false);
        }

        $review = $this->latestReview($chapter);
        $completed = GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)
            ->where('data->source_review_id', $review->getKey())
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
            ->first();
        if ($completed !== null) {
            return $completed;
        }
        $source = $sceneId === null ? $this->latestChapterDraft($chapter) : $this->sceneSource($chapter, $sceneId);
        $attempt = $this->attemptCount($chapter) + 1;
        if ($attempt > (int) config('generation.max_rewrite_attempts', 2)) {
            $this->markExhausted($chapter, $review);
            throw new AiProviderException('rewrite_exhausted', 'Rewrite 已达到最大 2 次，已转为需要人工处理。', false);
        }

        $findingHash = hash('sha256', json_encode($review->findings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $settings = $this->settingsResolver->resolve(AiStage::Rewrite, $chapter->novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Rewrite);
        $brief = [
            'scope' => $sceneId === null ? 'chapter' : 'scene',
            'source_artifact_id' => $source->getKey(),
            'findings' => $review->findings,
            'must_preserve' => $chapter->latestPlan->only(['chapter_function', 'arc_contribution', 'reader_promise', 'must_reveal']),
            'expected_fixes' => collect($review->findings)->pluck('message')->filter()->values()->all(),
            'must_not_change' => $chapter->latestPlan->only(['must_not_reveal', 'forbidden_conflicts']),
            'state_version' => $chapter->novel->canonicalStateVersion->version,
            'current_state' => $chapter->novel->canonicalStateVersion->state,
            'locked_facts' => $chapter->novel->facts()->where('locked', true)->where('status', 'active')
                ->get()->map->only(['id', 'subject_type', 'subject_id', 'predicate', 'value'])->all(),
            'content' => $source->content,
        ];
        $inputHash = hash('sha256', json_encode([$brief, $settings->model, $promptVersion], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        [$run, $reused] = $this->startRun($chapter, $sceneId, $source, $findingHash, $attempt, $inputHash, $brief, $settings->model, $promptVersion);
        if ($reused) {
            return $run->artifacts()->where('type', ArtifactType::RewriteDraft)->first();
        }

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                systemPrompt: 'You are XNovel Rewriter. Fix only the supplied findings. Preserve required plot outcomes and established facts. Do not invent major facts, abilities, world rules, or knowledge. Return only revised prose.',
                prompt: 'Rewrite using this brief: '.json_encode($brief, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .3,
                maxTokens: (int) config('generation.rewrite_max_output_tokens', 12_000),
                promptVersion: $promptVersion,
                metadata: ['generation_run_id' => $run->getKey(), 'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scene_id' => $sceneId, 'stage' => AiStage::Rewrite->value],
            ));
            $content = trim($response->content);
            if ($content === '') {
                throw new AiProviderException('rewrite_empty_draft', 'Rewrite 返回了空正文。', false);
            }

            return $this->complete($run, $chapter, $sceneId, $source, $review, $content, $findingHash, $attempt, $brief['state_version']);
        } catch (Throwable $exception) {
            $run->update(['status' => RunStatus::Failed, 'error_code' => $exception instanceof AiProviderException ? $exception->errorCode : 'rewrite_failed', 'error_message' => $exception->getMessage(), 'finished_at' => now()]);
            throw $exception;
        }
    }

    public function markTerminalFailure(int $chapterId): void
    {
        Chapter::query()->whereKey($chapterId)->update(['status' => ChapterStatus::Blocked]);
    }

    private function latestReview(Chapter $chapter): Review
    {
        $review = Review::query()->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))->latest('id')->first();
        if ($review === null || ! in_array($review->decision, [ReviewDecision::Rewrite, ReviewDecision::NeedsAttention], true)) {
            throw new AiProviderException('rewrite_not_required', '当前 Chapter 没有可执行的 Rewrite Review。', false);
        }
        if ($chapter->latestPlan === null || $chapter->novel->canonicalStateVersion === null) {
            throw new AiProviderException('rewrite_context_incomplete', 'Rewrite 缺少 Chapter Plan 或 Story State。', false);
        }

        return $review;
    }

    private function latestChapterDraft(Chapter $chapter): GenerationArtifact
    {
        $artifact = GenerationArtifact::query()->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey())->whereNull('scene_id'))->latest('id')->first();
        if ($artifact === null) {
            throw new AiProviderException('rewrite_input_incomplete', 'Rewrite 缺少 Chapter Draft。', false);
        }

        return $artifact;
    }

    private function sceneSource(Chapter $chapter, int $sceneId): GenerationArtifact
    {
        $scene = Scene::query()->where('chapter_id', $chapter->getKey())->findOrFail($sceneId);
        if ($scene->currentArtifact === null || ! in_array($scene->currentArtifact->type, [ArtifactType::SceneDraft, ArtifactType::RewriteDraft], true)) {
            throw new AiProviderException('rewrite_input_incomplete', 'Rewrite 缺少 Scene Draft。', false);
        }

        return $scene->currentArtifact;
    }

    private function attemptCount(Chapter $chapter): int
    {
        return GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))->count();
    }

    private function startRun(Chapter $chapter, ?int $sceneId, GenerationArtifact $source, string $findingHash, int $attempt, string $inputHash, array $brief, string $model, string $promptVersion): array
    {
        return DB::transaction(function () use ($chapter, $sceneId, $source, $findingHash, $attempt, $inputHash, $brief, $model, $promptVersion) {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $key = "rewrite:{$source->getKey()}:{$findingHash}:{$attempt}:{$promptVersion}";
            $existing = GenerationRun::query()->where('idempotency_key', $key)->first();
            if ($existing !== null && $existing->status === RunStatus::Succeeded) {
                return [$existing, true];
            }
            if ($existing !== null && $existing->status === RunStatus::Failed) {
                $retry = GenerationRun::query()->where('idempotency_key', 'like', $key.'%')->count() + 1;
                $key .= ':retry:'.$retry;
            }
            $active = $chapter->generationRuns()->where('stage', GenerationStage::Rewrite)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
            if ($active && $active->updated_at->gt(now()->subSeconds(self::STALE_RUN_SECONDS))) {
                return [$active, true];
            }
            if ($active) {
                $active->update(['status' => RunStatus::Failed, 'error_code' => 'worker_interrupted', 'error_message' => 'Rewrite Run 超时未完成，已由后续投递恢复。', 'finished_at' => now()]);
            }

            return [GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scene_id' => $sceneId,
                'scope_type' => $sceneId === null ? 'chapter' : 'scene', 'scope_id' => $sceneId ?? $chapter->getKey(),
                'stage' => GenerationStage::Rewrite, 'status' => RunStatus::Running, 'attempt' => $attempt,
                'idempotency_key' => $key, 'input_hash' => $inputHash,
                'state_version' => $chapter->novel->canonicalStateVersion()->value('version'),
                'prompt_version' => $promptVersion, 'model_policy' => $model,
                'context_snapshot' => [...collect($brief)->except('content')->all(), 'finding_hash' => $findingHash], 'started_at' => now(),
            ]), false];
        });
    }

    private function complete(GenerationRun $run, Chapter $chapter, ?int $sceneId, GenerationArtifact $source, Review $review, string $content, string $findingHash, int $attempt, int $expectedStateVersion): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $chapter, $sceneId, $source, $review, $content, $findingHash, $attempt, $expectedStateVersion) {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());
            if ($chapter->novel->canonicalStateVersion?->version !== $expectedStateVersion) {
                throw new AiProviderException('state_version_conflict', 'Rewrite 期间 Canonical Story State 已变化。', false);
            }
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::RewriteDraft, 'version' => $attempt, 'content' => $content,
                'data' => ['scope' => $sceneId === null ? 'chapter' : 'scene', 'source_artifact_id' => $source->getKey(), 'source_review_id' => $review->getKey(), 'finding_hash' => $findingHash, 'attempt' => $attempt],
                'checksum' => hash('sha256', $content),
            ]);
            if ($sceneId !== null) {
                Scene::query()->whereKey($sceneId)->update(['status' => SceneStatus::Draft, 'current_artifact_id' => $artifact->getKey()]);
            }
            $chapter->update(['status' => ChapterStatus::Rewrite]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    private function markExhausted(Chapter $chapter, Review $source): Review
    {
        return DB::transaction(function () use ($chapter, $source): Review {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $key = 'review:rewrite-exhausted:'.$chapter->getKey().':'.$source->getKey();
            $existing = GenerationRun::query()->where('idempotency_key', $key)->with('review')->first();
            if ($existing?->review !== null) {
                return $existing->review;
            }
            $run = GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded,
                'attempt' => $source->generationRun->attempt + 1, 'idempotency_key' => $key,
                'input_hash' => hash('sha256', $key), 'state_version' => $source->generationRun->state_version,
                'prompt_version' => $source->generationRun->prompt_version, 'model_policy' => 'deterministic',
                'context_snapshot' => ['reason' => 'rewrite_exhausted', 'source_review_id' => $source->getKey()],
                'started_at' => now(), 'finished_at' => now(),
            ]);
            $findings = [...$source->findings, ['code' => 'REWRITE_EXHAUSTED', 'severity' => 'ambiguous', 'message' => '自动 Rewrite 已达到最大 2 次，需要人工处理。', 'source' => 'rewrite_loop']];
            $data = ['decision' => ReviewDecision::NeedsAttention->value, 'score' => (float) $source->score, 'findings' => $findings, 'source_review_id' => $source->getKey()];
            $version = GenerationArtifact::query()->where('type', ArtifactType::ReviewResult)
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))->max('version');
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::ReviewResult, 'version' => (int) $version + 1,
                'content' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'data' => $data, 'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            ]);
            $review = $run->review()->create([
                'artifact_id' => $artifact->getKey(), 'decision' => ReviewDecision::NeedsAttention,
                'score' => $source->score, 'continuity_score' => $source->continuity_score,
                'plan_score' => $source->plan_score, 'character_score' => $source->character_score,
                'progress_score' => $source->progress_score, 'repetition_score' => $source->repetition_score,
                'pacing_score' => $source->pacing_score, 'style_score' => $source->style_score, 'findings' => $findings,
            ]);
            $chapter->update(['status' => ChapterStatus::Review]);

            return $review;
        });
    }
}
