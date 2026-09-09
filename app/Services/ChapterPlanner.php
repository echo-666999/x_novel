<?php

namespace App\Services;

use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\PromptVersionResolver;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChapterPlanner
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly PromptVersionResolver $promptVersionResolver,
        private readonly PlanValidator $planValidator,
        private readonly ClosureDebtService $closureDebt,
        private readonly SyncScenesFromChapterPlanAction $syncScenes,
        private readonly NarrativeStyleProfile $narrativeStyleProfile,
        private readonly PreviousChapterEnding $previousChapterEnding,
        private readonly GenerationRunLease $runLease,
    ) {}

    public function generate(int $chapterId, bool $regenerate = false): ?ChapterPlan
    {
        $chapter = Chapter::query()->with(['novel.canonicalStateVersion', 'volume'])->findOrFail($chapterId);
        $novel = $chapter->novel;

        if ($novel->status->value === 'paused') {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始新的规划阶段。', false);
        }

        $settings = $this->settingsResolver->resolve(AiStage::Planner, $novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Planner);
        $context = $this->context($chapter);
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'model' => $settings->model,
            'prompt_version' => $promptVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $baseKey = "plan:{$chapter->getKey()}:{$context['state_version']}:{$context['bible_version']}:{$promptVersion}:".
            hash('sha256', $settings->provider.'|'.$settings->model.'|'.$settings->source);

        [$run, $reused] = $this->startRun($chapter, $baseKey, $inputHash, $context, $settings->model, $promptVersion, $regenerate);

        if ($reused) {
            $planId = data_get($run->context_snapshot, 'chapter_plan_id');

            return is_numeric($planId) ? ChapterPlan::query()->find((int) $planId) : null;
        }

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                systemPrompt: $this->systemPrompt($novel),
                prompt: '请根据以下权威上下文创建下一章可执行计划。除固定 JSON 字段和枚举值外，所有自然语言内容必须使用简体中文。'
                    .'引用规则：pov_character_id 只能使用 characters[].id；required_facts 只能使用 active_facts[].id，active_facts 为空时必须返回 []；'
                    .'due_foreshadowings 只能使用 due_foreshadowings[].id，due_foreshadowings 为空时必须返回 []。'
                    .'每个 Scene Plan 都必须返回 transition_from_previous；第一场景应说明如何承接 previous_chapter_ending，若没有上一章则返回 null，后续场景说明如何承接前一场景。上下文：'
                    .json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.4,
                maxTokens: 4_000,
                responseSchema: ChapterPlanPayload::schema(),
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $novel->getKey(),
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Planner->value,
                ],
            ));

            if ($response->structuredData === null) {
                throw new AiProviderException('plan_schema_invalid', 'AI 未返回合法的结构化 Chapter Plan。', false);
            }

            $payload = ChapterPlanPayload::validate($response->structuredData);
            $payload['target_words'] = (int) data_get($novel->settings, 'generation.chapter_target_words', $payload['target_words']);
            $candidate = new ChapterPlan($payload);
            $candidate->setRelation('chapter', $chapter);
            $this->planValidator->validate($candidate)->assertCanGenerate();

            return $this->complete($run, $chapter, $payload, $response->content, $context['state_version'], $regenerate);
        } catch (Throwable $exception) {
            $this->fail($run, $exception);
            throw $exception;
        }
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $model, string $promptVersion, bool $regenerate): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $model, $promptVersion, $regenerate): array {
            Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = GenerationRun::query()->where('chapter_id', $chapter->getKey())->where('stage', GenerationStage::ChapterPlanning);

            $activeRun = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();

            if ($this->runLease->isFresh($activeRun)) {
                return [$activeRun, true];
            }

            if ($activeRun !== null) {
                $activeRun->update([
                    'status' => RunStatus::Failed,
                    'error_code' => 'worker_interrupted',
                    'error_message' => '规划 Run 超时未完成，已由后续投递恢复。',
                    'finished_at' => now(),
                ]);
            }

            if (! $regenerate && ($succeeded = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$succeeded, true];
            }

            $attempt = ((int) $runs->clone()->max('attempt')) + 1;
            $key = $attempt === 1 ? $baseKey : $baseKey.":attempt:{$attempt}";

            return [GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::ChapterPlanning,
                'status' => RunStatus::Running,
                'attempt' => $attempt,
                'idempotency_key' => $key,
                'input_hash' => $inputHash,
                'state_version' => $context['state_version'],
                'bible_version' => $context['bible_version'],
                'prompt_version' => $promptVersion,
                'model_policy' => $model,
                'context_snapshot' => $context,
                'started_at' => now(),
            ]), false];
        });
    }

    private function complete(GenerationRun $run, Chapter $chapter, array $payload, string $content, int $expectedStateVersion, bool $regenerate): ChapterPlan
    {
        return DB::transaction(function () use ($run, $chapter, $payload, $content, $expectedStateVersion, $regenerate): ChapterPlan {
            $novel = Novel::query()->lockForUpdate()->findOrFail($chapter->novel_id);
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());

            if ($novel->canonicalStateVersion()->value('version') !== $expectedStateVersion) {
                throw new AiProviderException(
                    'state_version_conflict',
                    '生成期间 Canonical Story State 已变化，请基于最新状态重新规划。',
                    false,
                );
            }

            $version = ((int) $chapter->plans()->max('version')) + 1;
            $chapter->plans()->where('status', PlanStatus::Ready)->update(['status' => PlanStatus::Superseded]);
            $plan = $chapter->plans()->create(['version' => $version, 'status' => PlanStatus::Ready, ...$payload]);
            $checksum = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $run->artifacts()->create(['type' => ArtifactType::ChapterPlan, 'version' => 1, 'content' => $content, 'data' => $payload, 'checksum' => $checksum]);
            $run->update([
                'status' => RunStatus::Succeeded,
                'context_snapshot' => [...($run->context_snapshot ?? []), 'chapter_plan_id' => $plan->getKey()],
                'finished_at' => now(),
            ]);
            $this->syncScenes->execute(
                $chapter,
                replaceGenerated: $regenerate && $chapter->status === ChapterStatus::Void,
            );
            $chapter->update(['status' => ChapterStatus::Generating]);

            return $plan;
        });
    }

    private function fail(GenerationRun $run, Throwable $exception): void
    {
        $code = $exception instanceof AiProviderException ? $exception->errorCode : ($exception instanceof ValidationException ? 'plan_validation_failed' : 'plan_generation_failed');
        $run->update(['status' => RunStatus::Failed, 'error_code' => $code, 'error_message' => $exception->getMessage(), 'finished_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function context(Chapter $chapter): array
    {
        $novel = $chapter->novel;
        $bible = $novel->currentBible()->first();

        if ($bible === null || $novel->canonicalStateVersion === null || $chapter->volume === null) {
            throw new AiProviderException('planner_context_incomplete', 'Chapter Planner 缺少 Bible、Story State 或 Current Volume。', false);
        }

        $context = [
            'novel' => ['id' => $novel->getKey(), 'title' => $novel->title, 'status' => $novel->status->value],
            'generation_preferences' => [
                'chapter_target_words' => (int) data_get($novel->settings, 'generation.chapter_target_words', 3_000),
                'style_profile' => $this->narrativeStyleProfile->forNovel($novel),
            ],
            'chapter' => ['id' => $chapter->getKey(), 'sequence' => $chapter->sequence],
            'bible_version' => $bible->version,
            'bible' => $bible->only(['logline', 'themes', 'tone', 'pov', 'tense', 'taboos', 'hard_constraints', 'ending_contract']),
            'state_version' => $novel->canonicalStateVersion->version,
            'story_state' => $novel->canonicalStateVersion->state,
            'volume' => $chapter->volume->only(['id', 'sequence', 'title', 'goal', 'climax', 'target_words']),
            'active_arcs' => $novel->storyArcs()->where('status', 'active')->get()->map->only(['id', 'title', 'goal', 'stakes', 'beats', 'completion_conditions', 'progress'])->all(),
            'characters' => $novel->characters()->get()->map->only(['id', 'name', 'role', 'status', 'goals', 'knowledge'])->all(),
            'active_facts' => $novel->facts()->where('status', 'active')->get()->map->only(['id', 'subject_type', 'subject_id', 'predicate', 'value', 'locked'])->all(),
            'due_foreshadowings' => $novel->foreshadowings()->whereNotIn('status', ['paid_off', 'abandoned'])->where('due_from_chapter', '<=', $chapter->sequence)->get()->map->only(['id', 'title', 'description', 'promised_payoff', 'due_from_chapter', 'due_to_chapter', 'importance', 'status'])->all(),
            'recent_summaries' => $novel->chapters()->where('status', ChapterStatus::Canonical)->whereNotNull('summary')->latest('sequence')->limit(10)->get(['sequence', 'summary'])->reverse()->values()->all(),
            'previous_chapter_ending' => $this->previousChapterEnding->for($chapter),
        ];

        if ($novel->status->value === 'completing') {
            $debt = $this->closureDebt->calculate($novel);
            $context['closing_restrictions'] = [
                'active' => true,
                'forbidden_new_elements' => [
                    'core_character',
                    'main_story_arc',
                    'hard_world_rule',
                    'high_importance_foreshadowing',
                ],
                'instruction' => '推进 Ending Contract 或降低 Closure Debt，不得开启新的核心故事义务。',
            ];
            $context['closure_debt'] = [
                'total' => $debt->total(),
                'critical' => $debt->critical(),
                'items' => $debt->toArray(),
            ];
        }

        return $context;
    }

    private function systemPrompt(Novel $novel): string
    {
        $prompt = '你是 XNovel 章节规划器。只返回符合指定 Schema 的 JSON，不得编造任何实体 ID；所有自然语言内容必须使用简体中文。计划必须连续承接上一章正式结尾。若时间、地点或行动发生跳跃，必须在第一场景的 transition_from_previous 中写明正文要呈现的过渡过程，不得静默跳过。';

        if ($novel->status->value === 'completing') {
            $prompt .= ' 当前处于收束阶段：不得新增核心人物、主线、硬世界规则或高重要度伏笔；计划必须推进结局契约或降低收束债务。';
        }

        return $prompt;
    }
}
