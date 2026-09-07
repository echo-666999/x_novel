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
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChapterReviewer
{
    private const STALE_RUN_SECONDS = 120;

    private const DIMENSIONS = ['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'];

    private const WEIGHTS = ['continuity' => .25, 'plan' => .15, 'character' => .15, 'progress' => .15, 'repetition' => .10, 'pacing' => .10, 'style' => .10];

    public function __construct(private readonly AiProvider $provider, private readonly AiSettingsResolver $settingsResolver, private readonly PromptVersionResolver $promptVersionResolver, private readonly StateValidator $stateValidator) {}

    public function review(int $chapterId, bool $regenerate = false): ?Review
    {
        $chapter = Chapter::query()->with(['novel.canonicalStateVersion', 'latestPlan'])->findOrFail($chapterId);
        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Narrative Review。', false);
        }

        $draft = $this->latestDraft($chapter);
        $stateValidation = $this->stateValidator->validate($chapterId);
        $context = [
            'chapter_id' => $chapter->getKey(),
            'draft' => ['artifact_id' => $draft->getKey(), 'checksum' => $draft->checksum, 'content' => $draft->content],
            'chapter_plan' => $chapter->latestPlan?->only(['id', 'version', 'chapter_function', 'arc_contribution', 'reader_promise', 'must_reveal', 'may_hint', 'must_not_reveal']),
            'state_version' => $chapter->novel->canonicalStateVersion?->version,
            'state_findings' => array_map(fn ($finding) => $finding->toArray(), $stateValidation->findings),
        ];
        if ($context['chapter_plan'] === null || $context['state_version'] === null) {
            throw new AiProviderException('review_context_incomplete', 'Narrative Review 缺少 Chapter Plan 或 Story State。', false);
        }

        $settings = $this->settingsResolver->resolve(AiStage::Reviewer, $chapter->novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Reviewer);
        $inputHash = hash('sha256', json_encode(['context' => $context, 'model' => $settings->model, 'prompt_version' => $promptVersion, 'pass_score' => config('generation.review_pass_score', 80)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        [$run, $reused] = $this->startRun($chapter, "review:{$draft->checksum}:{$context['state_version']}:{$promptVersion}", $inputHash, $context, $settings->model, $promptVersion, $regenerate);
        if ($reused) {
            return $run->review;
        }

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                systemPrompt: 'You are XNovel Narrative Reviewer. Score the draft from 0 to 100 on exactly seven dimensions. Findings must be concrete and traceable. Recommend PASS, REWRITE, NEEDS_ATTENTION, or BLOCK, but Laravel makes the final workflow decision. Return only structured JSON matching the schema.',
                prompt: 'Review this chapter draft against its plan and deterministic state findings: '.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .2, maxTokens: (int) config('generation.review_max_output_tokens', 4000), responseSchema: $this->schema(), promptVersion: $promptVersion,
                metadata: ['generation_run_id' => $run->getKey(), 'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'stage' => AiStage::Reviewer->value],
            ));
            if ($response->structuredData === null) {
                throw new AiProviderException('review_schema_invalid', 'AI 未返回合法的结构化 Narrative Review。', false);
            }
            $payload = $this->validate($response->structuredData);

            return $this->complete($run, $chapter, $draft, $payload, $context['state_findings'], $stateValidation->isBlocked(), $context['state_version']);
        } catch (Throwable $e) {
            $this->failRun($run, $e);
            throw $e;
        }
    }

    public function markTerminalFailure(int $chapterId): void
    {
        Chapter::query()->whereKey($chapterId)->update(['status' => ChapterStatus::Blocked]);
    }

    private function latestDraft(Chapter $chapter): GenerationArtifact
    {
        $artifact = GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapter->getKey()))->latest('version')->latest('id')->first();
        if ($artifact === null) {
            throw new AiProviderException('review_input_incomplete', 'Narrative Review 缺少 Chapter Draft。', false);
        }

        return $artifact;
    }

    private function schema(): array
    {
        $scores = [];
        foreach (self::DIMENSIONS as $dimension) {
            $scores[$dimension] = ['type' => 'number', 'minimum' => 0, 'maximum' => 100];
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['recommended_decision', 'scores', 'findings'], 'properties' => [
            'recommended_decision' => ['type' => 'string', 'enum' => array_column(ReviewDecision::cases(), 'value')],
            'scores' => ['type' => 'object', 'additionalProperties' => false, 'required' => self::DIMENSIONS, 'properties' => $scores],
            'findings' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['dimension', 'severity', 'message', 'evidence'], 'properties' => [
                'dimension' => ['type' => 'string', 'enum' => self::DIMENSIONS], 'severity' => ['type' => 'string', 'enum' => ['warning', 'error']], 'message' => ['type' => 'string'], 'evidence' => ['type' => 'string'],
            ]]],
        ]];
    }

    private function validate(array $payload): array
    {
        if (! $this->hasExactKeys($payload, ['recommended_decision', 'scores', 'findings']) || ! is_array($payload['scores']) || ! $this->hasExactKeys($payload['scores'], self::DIMENSIONS) || ! is_array($payload['findings']) || ! ReviewDecision::tryFrom((string) $payload['recommended_decision'])) {
            throw ValidationException::withMessages(['review' => 'Narrative Review 返回结构无效。']);
        }
        foreach ($payload['scores'] as $score) {
            if (! is_numeric($score) || $score < 0 || $score > 100) {
                throw ValidationException::withMessages(['scores' => '七维评分必须在 0 到 100 之间。']);
            }
        }
        foreach ($payload['findings'] as $finding) {
            if (! is_array($finding) || ! $this->hasExactKeys($finding, ['dimension', 'severity', 'message', 'evidence']) || ! in_array($finding['dimension'], self::DIMENSIONS, true) || ! in_array($finding['severity'], ['warning', 'error'], true) || ! is_string($finding['message']) || ! is_string($finding['evidence'])) {
                throw ValidationException::withMessages(['findings' => 'Narrative Review Findings 结构无效。']);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $value @param array<int, string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $model, string $promptVersion, bool $regenerate): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $model, $promptVersion, $regenerate) {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = $chapter->generationRuns()->where('stage', GenerationStage::Review);
            $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
            if ($active && $active->updated_at->gt(now()->subSeconds(self::STALE_RUN_SECONDS))) {
                return [$active, true];
            }
            if ($active) {
                $active->update(['status' => RunStatus::Failed, 'error_code' => 'worker_interrupted', 'error_message' => 'Review Run 超时未完成，已由后续投递恢复。', 'finished_at' => now()]);
            }
            if (! $regenerate && ($done = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$done, true];
            }
            $attempt = (int) $runs->clone()->max('attempt') + 1;
            $run = GenerationRun::query()->create(['novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Running, 'attempt' => $attempt, 'idempotency_key' => $attempt === 1 ? $baseKey : "$baseKey:attempt:$attempt", 'input_hash' => $inputHash, 'state_version' => $context['state_version'], 'prompt_version' => $promptVersion, 'model_policy' => $model, 'context_snapshot' => [...$context, 'draft' => collect($context['draft'])->except('content')->all()], 'started_at' => now()]);

            return [$run, false];
        });
    }

    private function complete(GenerationRun $run, Chapter $chapter, GenerationArtifact $draft, array $payload, array $stateFindings, bool $blocked, int $expectedVersion): Review
    {
        return DB::transaction(function () use ($run, $chapter, $draft, $payload, $stateFindings, $blocked, $expectedVersion) {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());
            if ($chapter->novel->canonicalStateVersion?->version !== $expectedVersion) {
                throw new AiProviderException('state_version_conflict', 'Review 期间 Canonical Story State 已变化。', false);
            }
            $scores = $payload['scores'];
            $total = round(collect(self::WEIGHTS)->sum(fn ($weight, $dimension) => $scores[$dimension] * $weight), 2);
            $recommended = ReviewDecision::from($payload['recommended_decision']);
            $decision = $blocked ? ReviewDecision::Block : (($recommended === ReviewDecision::NeedsAttention || $recommended === ReviewDecision::Block) ? ReviewDecision::NeedsAttention : (($recommended === ReviewDecision::Rewrite || $total < config('generation.review_pass_score', 80)) ? ReviewDecision::Rewrite : ReviewDecision::Pass));
            $findings = [...$stateFindings, ...array_map(fn ($f) => [...$f, 'source' => 'narrative_review'], $payload['findings'])];
            $data = ['decision' => $decision->value, 'recommended_decision' => $recommended->value, 'score' => $total, 'scores' => $scores, 'findings' => $findings, 'source_artifact_id' => $draft->getKey()];
            $version = GenerationArtifact::query()->where('type', ArtifactType::ReviewResult)->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapter->getKey()))->max('version');
            $artifact = $run->artifacts()->create(['type' => ArtifactType::ReviewResult, 'version' => (int) $version + 1, 'content' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'data' => $data, 'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]);
            $review = $run->review()->create(['artifact_id' => $artifact->getKey(), 'decision' => $decision, 'score' => $total, ...collect($scores)->mapWithKeys(fn ($v, $k) => ["{$k}_score" => $v])->all(), 'findings' => $findings]);
            $chapter->update(['status' => match ($decision) {
                ReviewDecision::Rewrite => ChapterStatus::Rewrite, ReviewDecision::Block => ChapterStatus::Blocked, default => ChapterStatus::Review
            }]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $review;
        });
    }

    private function failRun(GenerationRun $run, Throwable $e): void
    {
        $run->update(['status' => RunStatus::Failed, 'error_code' => $e instanceof AiProviderException ? $e->errorCode : ($e instanceof ValidationException ? 'review_validation_failed' : 'review_failed'), 'error_message' => $e->getMessage(), 'finished_at' => now()]);
    }
}
