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
    private const DIMENSIONS = ['continuity', 'plan', 'character', 'progress', 'repetition', 'pacing', 'style'];

    private const WEIGHTS = ['continuity' => .25, 'plan' => .15, 'character' => .15, 'progress' => .15, 'repetition' => .10, 'pacing' => .10, 'style' => .10];

    private const NARRATIVE_FINDING_CODES = [
        'CONTINUITY_BREAK' => 'continuity',
        'PLAN_DEVIATION' => 'plan',
        'CHARACTER_INCONSISTENCY' => 'character',
        'INSUFFICIENT_PROGRESS' => 'progress',
        'EXCESSIVE_REPETITION' => 'repetition',
        'PACING_ISSUE' => 'pacing',
        'STYLE_MISMATCH' => 'style',
    ];

    private const FINDING_SCOPES = ['paragraph', 'scene', 'chapter'];

    public function __construct(private readonly AiProvider $provider, private readonly AiSettingsResolver $settingsResolver, private readonly PromptVersionResolver $promptVersionResolver, private readonly StateValidator $stateValidator, private readonly AutoStopService $autoStop, private readonly DraftLengthPolicy $lengthPolicy, private readonly PreviousChapterEnding $previousChapterEnding, private readonly ContextBuilder $contextBuilder, private readonly GenerationRunLease $runLease, private readonly AutomaticRewriteCounter $rewriteCounter) {}

    public function review(int $chapterId, bool $regenerate = false, ?string $operationId = null): ?Review
    {
        $chapter = Chapter::query()->with(['novel.canonicalStateVersion', 'latestPlan', 'scenes'])->findOrFail($chapterId);
        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Narrative Review。', false);
        }

        $draft = $this->latestDraft($chapter);
        $stateValidation = $this->stateValidator->validate($chapterId);
        $missingPrerequisite = collect($stateValidation->findings)->first(
            fn ($finding): bool => in_array($finding->code, ['INVALID_STATE_PATCH', 'INVALID_EVENT_REFERENCE'], true),
        );
        if ($missingPrerequisite !== null) {
            throw new AiProviderException(
                'review_prerequisite_missing',
                '当前章节缺少与最新事件候选对应的有效 State Patch，请先补建状态补丁后重新审校。',
                false,
            );
        }
        $lengthCheck = $this->lengthCheck($draft->content, (int) $chapter->latestPlan?->target_words);
        $styleContract = $this->contextBuilder->styleContractForChapter($chapter);
        $context = [
            'chapter_id' => $chapter->getKey(),
            'bible_version' => $styleContract['bible_version'],
            'style_contract_checksum' => $styleContract['checksum'],
            'l4' => $styleContract,
            'draft' => ['artifact_id' => $draft->getKey(), 'checksum' => $draft->checksum, 'content' => $draft->content],
            'chapter_plan' => $chapter->latestPlan?->only(['id', 'version', 'chapter_function', 'arc_contribution', 'reader_promise', 'target_words', 'must_reveal', 'may_hint', 'must_not_reveal', 'scene_plans']),
            'scenes' => $chapter->scenes->sortBy('sequence')->map->only(['id', 'sequence', 'goal', 'conflict', 'turn', 'outcome'])->values()->all(),
            'previous_chapter_ending' => $this->previousChapterEnding->for($chapter),
            'length_check' => $lengthCheck,
            'state_version' => $chapter->novel->canonicalStateVersion?->version,
            'state_findings' => array_map(fn ($finding) => $finding->toArray(), $stateValidation->findings),
        ];
        if ($context['chapter_plan'] === null || $context['state_version'] === null) {
            throw new AiProviderException('review_context_incomplete', 'Narrative Review 缺少 Chapter Plan 或 Story State。', false);
        }

        $settings = $this->settingsResolver->resolve(AiStage::Reviewer, $chapter->novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Reviewer);
        $reviewOperationId = $regenerate ? $operationId : null;
        $input = [
            'context' => $context,
            'model' => $settings->model,
            'prompt_version' => $promptVersion,
            'pass_score' => config('generation.review_pass_score', 80),
            ...($reviewOperationId === null ? [] : ['operation_id' => $reviewOperationId]),
        ];
        $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        [$run, $reused] = $this->startRun($chapter, "review:{$draft->checksum}:{$context['state_version']}:{$promptVersion}", $inputHash, $context, $settings->model, $promptVersion, $regenerate, $reviewOperationId);
        if ($reused) {
            return $run->review;
        }

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                systemPrompt: '你是 XNovel 叙事审校器。严格按照七个维度对草稿进行 0 到 100 分评分，并返回符合 Schema 的 JSON。连续性审校必须对照 previous_chapter_ending 检查本章开头；时间、地点或行动发生跳跃却没有在正文中交代时，必须给出 continuity finding。每条 finding 必须使用 Schema 中固定的 code，并确保 code 对应正确 dimension。scope=scene 时 scene_id 必须引用 scenes 中属于本章的 ID；scope=chapter 时 scene_id 必须为 null。auto_fixable 只表示正文可在不需要用户选择的情况下修复；requires_human_decision 只用于 Canonical 数据无法确定答案、必须由用户选择的重大歧义，两者不得同时为 true。文风审校必须逐项对照 l4 的主文风、辅助文风和可执行参数；style finding 的 evidence 必须引用草稿中的具体短句，message 必须说明该证据违反了哪项目标文风。所有 finding 的 message 与 evidence 必须使用简体中文。只报告有明确文本证据且实际影响连续性、计划遵循、人物一致性、剧情推进、重复度、节奏或文风的问题；需要修复的问题必须通过 auto_fixable 或 requires_human_decision 明确分流。length_check 由 Laravel 确定性计算，不要重复报告其中的字数问题；state_findings 为空表示确定性检查未发现问题，不得因此产生警告；may_hint 是可选提示，未采用不得视为问题；不得用“可以更丰富、可以更深入”等泛化建议凑数。recommended_decision 只是审校证据，最终流程决策由 Laravel 根据结构化 finding、确定性规则和分数作出。',
                prompt: '请根据章节计划和确定性状态检查结果审校以下章节草稿，并确保所有面向用户的说明均使用简体中文：'.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: .2, maxTokens: (int) config('generation.review_max_output_tokens', 4000), responseSchema: $this->schema(), promptVersion: $promptVersion,
                metadata: ['generation_run_id' => $run->getKey(), 'novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'stage' => AiStage::Reviewer->value],
            ));
            if ($response->structuredData === null) {
                throw new AiProviderException('review_schema_invalid', 'AI 未返回合法的结构化 Narrative Review。', false);
            }
            $payload = $this->validate(
                $response->structuredData,
                $chapter,
                $lengthCheck['status'] !== 'within_range'
                    || $stateValidation->isBlocked()
                    || collect($stateValidation->findings)->contains(fn ($finding): bool => $finding->severity->value === 'ambiguous'),
            );

            $review = $this->complete($run, $chapter, $draft, $payload, $context['state_findings'], $lengthCheck, $stateValidation->isBlocked(), $context['state_version']);
            $this->autoStop->stopForReview($chapter, $review->decision, $stateValidation->isBlocked());

            return $review;
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
        $artifact = GenerationArtifact::query()->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapter->getKey())->whereNull('scene_id'))->latest('id')->first();
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
            'findings' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'dimension', 'severity', 'scene_id', 'scope', 'auto_fixable', 'requires_human_decision', 'message', 'evidence'], 'properties' => [
                'code' => ['type' => 'string', 'enum' => array_keys(self::NARRATIVE_FINDING_CODES)],
                'dimension' => ['type' => 'string', 'enum' => self::DIMENSIONS],
                'severity' => ['type' => 'string', 'enum' => ['warning', 'error']],
                'scene_id' => ['type' => ['integer', 'null']],
                'scope' => ['type' => 'string', 'enum' => self::FINDING_SCOPES],
                'auto_fixable' => ['type' => 'boolean'],
                'requires_human_decision' => ['type' => 'boolean'],
                'message' => ['type' => 'string', 'minLength' => 1],
                'evidence' => ['type' => 'string', 'minLength' => 1],
            ]]],
        ]];
    }

    private function validate(array $payload, Chapter $chapter, bool $hasDeterministicRoute): array
    {
        if (! $this->hasExactKeys($payload, ['recommended_decision', 'scores', 'findings']) || ! is_array($payload['scores']) || ! $this->hasExactKeys($payload['scores'], self::DIMENSIONS) || ! is_array($payload['findings']) || ! ReviewDecision::tryFrom((string) $payload['recommended_decision'])) {
            throw ValidationException::withMessages(['review' => 'Narrative Review 返回结构无效。']);
        }
        foreach ($payload['scores'] as $score) {
            if (! is_numeric($score) || $score < 0 || $score > 100) {
                throw ValidationException::withMessages(['scores' => '七维评分必须在 0 到 100 之间。']);
            }
        }
        $sceneIds = $chapter->scenes->modelKeys();
        foreach ($payload['findings'] as $finding) {
            if (! is_array($finding)
                || ! $this->hasExactKeys($finding, ['code', 'dimension', 'severity', 'scene_id', 'scope', 'auto_fixable', 'requires_human_decision', 'message', 'evidence'])
                || ! isset(self::NARRATIVE_FINDING_CODES[$finding['code']])
                || self::NARRATIVE_FINDING_CODES[$finding['code']] !== $finding['dimension']
                || ! in_array($finding['severity'], ['warning', 'error'], true)
                || ! in_array($finding['scope'], self::FINDING_SCOPES, true)
                || ! is_bool($finding['auto_fixable'])
                || ! is_bool($finding['requires_human_decision'])
                || ($finding['auto_fixable'] && $finding['requires_human_decision'])
                || ($finding['severity'] === 'error' && ! $finding['auto_fixable'] && ! $finding['requires_human_decision'])
                || ($finding['scene_id'] !== null && (! is_int($finding['scene_id']) || ! in_array($finding['scene_id'], $sceneIds, true)))
                || ($finding['scope'] === 'scene' && $finding['scene_id'] === null)
                || ($finding['scope'] === 'chapter' && $finding['scene_id'] !== null)
                || ! is_string($finding['message']) || blank($finding['message'])
                || ! is_string($finding['evidence']) || blank($finding['evidence'])) {
                throw ValidationException::withMessages(['findings' => 'Narrative Review Findings 结构无效。']);
            }
        }

        if (! $hasDeterministicRoute
            && $this->weightedScore($payload['scores']) < (float) config('generation.review_pass_score', 80)
            && ! collect($payload['findings'])->contains(fn (array $finding): bool => $finding['auto_fixable'] || $finding['requires_human_decision'])) {
            throw ValidationException::withMessages(['findings' => 'Narrative Review 评分未达标，但没有提供可执行或需要人工决策的 Finding。']);
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

    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $model, string $promptVersion, bool $regenerate, ?string $operationId): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $model, $promptVersion, $regenerate, $operationId) {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = $chapter->generationRuns()->where('stage', GenerationStage::Review);
            $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();
            if ($this->runLease->isFresh($active)) {
                return [$active, true];
            }
            if ($active) {
                $active->update(['status' => RunStatus::Failed, 'error_code' => 'worker_interrupted', 'error_message' => 'Review Run 超时未完成，已由后续投递恢复。', 'finished_at' => now()]);
            }
            if ((! $regenerate || $operationId !== null)
                && ($done = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$done, true];
            }
            $attempt = (int) $runs->clone()->max('attempt') + 1;
            $run = GenerationRun::query()->create(['novel_id' => $chapter->novel_id, 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter', 'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Running, 'attempt' => $attempt, 'idempotency_key' => $attempt === 1 ? $baseKey : "$baseKey:attempt:$attempt", 'input_hash' => $inputHash, 'state_version' => $context['state_version'], 'bible_version' => $context['bible_version'], 'prompt_version' => $promptVersion, 'model_policy' => $model, 'context_snapshot' => [...$context, 'draft' => collect($context['draft'])->except('content')->all(), ...($operationId === null ? [] : ['operation_id' => $operationId])], 'started_at' => now()]);

            return [$run, false];
        });
    }

    private function complete(GenerationRun $run, Chapter $chapter, GenerationArtifact $draft, array $payload, array $stateFindings, array $lengthCheck, bool $blocked, int $expectedVersion): Review
    {
        return DB::transaction(function () use ($run, $chapter, $draft, $payload, $stateFindings, $lengthCheck, $blocked, $expectedVersion) {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());
            if ($chapter->novel->canonicalStateVersion?->version !== $expectedVersion) {
                throw new AiProviderException('state_version_conflict', 'Review 期间 Canonical Story State 已变化。', false);
            }
            $scores = $payload['scores'];
            $total = $this->weightedScore($scores);
            $recommended = ReviewDecision::from($payload['recommended_decision']);
            $lengthFinding = $this->lengthFinding($lengthCheck);
            $findings = [
                ...array_map(fn (array $finding): array => $this->normalizeStateFinding($finding), $stateFindings),
                ...($lengthFinding === null ? [] : [$lengthFinding]),
                ...array_map(fn (array $finding): array => [...$finding, 'source' => 'narrative_review'], $payload['findings']),
            ];
            [$decision, $decisionBasis] = $this->decide($findings, $total, $blocked);
            $rewriteAttempts = $this->rewriteCounter->countFor($chapter);
            $rewriteExhausted = $decision === ReviewDecision::Rewrite
                && $rewriteAttempts >= (int) config('generation.max_rewrite_attempts', 2);
            if ($rewriteExhausted) {
                $findings[] = [
                    'code' => 'REWRITE_EXHAUSTED',
                    'dimension' => 'workflow',
                    'severity' => 'ambiguous',
                    'scene_id' => null,
                    'scope' => 'chapter',
                    'auto_fixable' => false,
                    'requires_human_decision' => true,
                    'message' => '自动 Rewrite 已达到最大 '.config('generation.max_rewrite_attempts', 2).' 次，需要人工处理。',
                    'evidence' => '已完成 '.$rewriteAttempts.' 次自动 Rewrite。',
                    'source' => 'rewrite_loop',
                ];
                [$decision, $decisionBasis] = $this->decide($findings, $total, $blocked);
            }
            $data = ['decision' => $decision->value, 'decision_basis' => $decisionBasis, 'recommended_decision' => $recommended->value, 'score' => $total, 'scores' => $scores, 'findings' => $findings, 'source_artifact_id' => $draft->getKey()];
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

    /** @return array{target_words: int, actual_words: int, minimum_words: int, maximum_words: int, completion_percentage: float, status: string} */
    private function lengthCheck(string $content, int $targetWords): array
    {
        $actualWords = $this->lengthPolicy->count($content);
        $minimumWords = $this->lengthPolicy->chapterMinimum($targetWords);
        $maximumWords = $this->lengthPolicy->chapterMaximum($targetWords);

        return [
            'target_words' => $targetWords,
            'actual_words' => $actualWords,
            'minimum_words' => $minimumWords,
            'maximum_words' => $maximumWords,
            'completion_percentage' => $this->lengthPolicy->completionPercentage($actualWords, $targetWords),
            'status' => $actualWords < $minimumWords ? 'too_short' : ($actualWords > $maximumWords ? 'too_long' : 'within_range'),
        ];
    }

    /** @param array<string, mixed> $lengthCheck
     * @return array<string, mixed>|null
     */
    private function lengthFinding(array $lengthCheck): ?array
    {
        if ($lengthCheck['status'] === 'within_range') {
            return null;
        }

        $tooShort = $lengthCheck['status'] === 'too_short';
        $limit = $tooShort ? $lengthCheck['minimum_words'] : $lengthCheck['maximum_words'];

        return [
            'code' => $tooShort ? 'CHAPTER_LENGTH_TOO_SHORT' : 'CHAPTER_LENGTH_TOO_LONG',
            'dimension' => 'pacing',
            'severity' => 'error',
            'scene_id' => null,
            'scope' => 'chapter',
            'auto_fixable' => true,
            'requires_human_decision' => false,
            'message' => $tooShort
                ? "当前草稿 {$lengthCheck['actual_words']} 字，目标 {$lengthCheck['target_words']} 字，至少需要达到 {$limit} 字。"
                : "当前草稿 {$lengthCheck['actual_words']} 字，目标 {$lengthCheck['target_words']} 字，最多建议控制在 {$limit} 字。",
            'evidence' => "当前稿完成度为 {$lengthCheck['completion_percentage']}%。",
            'source' => 'length_check',
        ];
    }

    /** @param array<string, int|float> $scores */
    private function weightedScore(array $scores): float
    {
        return round(collect(self::WEIGHTS)->sum(fn (float $weight, string $dimension): float => (float) $scores[$dimension] * $weight), 2);
    }

    /** @param array<string, mixed> $finding @return array<string, mixed> */
    private function normalizeStateFinding(array $finding): array
    {
        $sceneId = collect($finding['evidence'] ?? [])->pluck('scene_id')->first(fn (mixed $id): bool => is_int($id));
        $severity = (string) ($finding['severity'] ?? 'hard');

        return [
            ...$finding,
            'dimension' => 'state',
            'scene_id' => $sceneId,
            'scope' => $sceneId === null ? 'chapter' : 'scene',
            'auto_fixable' => false,
            'requires_human_decision' => $severity === 'ambiguous',
            'source' => 'state_validation',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array{ReviewDecision, array{rule: string, finding_codes: array<int, string>, score: float, pass_score: float}}
     */
    private function decide(array $findings, float $score, bool $stateBlocked): array
    {
        $hardCodes = collect($findings)->filter(fn (array $finding): bool => ($finding['severity'] ?? null) === 'hard')->pluck('code')->unique()->values()->all();
        if ($stateBlocked || $hardCodes !== []) {
            return [ReviewDecision::Block, $this->decisionBasis('hard_finding', $hardCodes, $score)];
        }

        $humanCodes = collect($findings)->filter(fn (array $finding): bool => (bool) ($finding['requires_human_decision'] ?? false))->pluck('code')->unique()->values()->all();
        if ($humanCodes !== []) {
            return [ReviewDecision::NeedsAttention, $this->decisionBasis('human_decision_required', $humanCodes, $score)];
        }

        $rewriteCodes = collect($findings)->filter(fn (array $finding): bool => (bool) ($finding['auto_fixable'] ?? false))->pluck('code')->unique()->values()->all();
        if ($rewriteCodes !== []) {
            return [ReviewDecision::Rewrite, $this->decisionBasis('auto_fixable_finding', $rewriteCodes, $score)];
        }

        $nonBlockingCodes = collect($findings)->pluck('code')->unique()->values()->all();

        return [ReviewDecision::Pass, $this->decisionBasis('score_and_non_blocking_findings', $nonBlockingCodes, $score)];
    }

    /** @param array<int, string> $findingCodes @return array{rule: string, finding_codes: array<int, string>, score: float, pass_score: float} */
    private function decisionBasis(string $rule, array $findingCodes, float $score): array
    {
        return [
            'rule' => $rule,
            'finding_codes' => $findingCodes,
            'score' => $score,
            'pass_score' => (float) config('generation.review_pass_score', 80),
        ];
    }

    private function failRun(GenerationRun $run, Throwable $e): void
    {
        $run->update(['status' => RunStatus::Failed, 'error_code' => $e instanceof AiProviderException ? $e->errorCode : ($e instanceof ValidationException ? 'review_validation_failed' : 'review_failed'), 'error_message' => $e->getMessage(), 'finished_at' => now()]);
    }
}
