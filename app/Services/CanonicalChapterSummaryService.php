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
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CanonicalChapterSummaryService
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly GenerationFailurePolicy $failurePolicy,
        private readonly PlanAdmissionService $planAdmission,
        private readonly GenerationRequestBudget $requestBudget,
        private readonly GenerationOutputCapacityGuard $outputCapacity,
    ) {}

    /** @return array{novel_id: int, novel_title: string, chapters: array<int, array<string, mixed>>, plan_hash: string} */
    public function preview(Novel $novel): array
    {
        $chapters = $novel->chapters()
            ->where('status', ChapterStatus::Canonical)
            ->whereNotNull('canonical_artifact_id')
            ->whereNull('summary')
            ->with('canonicalArtifact.generationRun')
            ->orderBy('sequence')
            ->get()
            ->map(function (Chapter $chapter): array {
                $artifact = $this->canonicalArtifact($chapter);

                return [
                    'chapter_id' => $chapter->getKey(),
                    'sequence' => $chapter->sequence,
                    'title' => $chapter->title,
                    'canonical_artifact_id' => $artifact->getKey(),
                    'canonical_artifact_checksum' => $artifact->checksum,
                ];
            })
            ->values()
            ->all();

        $plan = [
            'novel_id' => $novel->getKey(),
            'novel_title' => $novel->title,
            'chapters' => $chapters,
        ];

        return [
            ...$plan,
            'plan_hash' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /** @return array{generated: int, reused: int, stale: int, artifact_ids: array<int, int>} */
    public function execute(Novel $novel, string $expectedPlanHash): array
    {
        $plan = $this->preview($novel->fresh());

        if (! hash_equals($plan['plan_hash'], $expectedPlanHash)) {
            throw ValidationException::withMessages([
                'plan_hash' => '摘要补写计划已变化，请重新执行 dry-run 并审核新的 plan_hash。',
            ]);
        }

        $result = ['generated' => 0, 'reused' => 0, 'stale' => 0, 'artifact_ids' => []];

        foreach ($plan['chapters'] as $item) {
            $outcome = $this->generate((int) $item['chapter_id']);
            $result[$outcome['reused'] ? 'reused' : 'generated']++;
            $result['stale'] += $outcome['applied'] ? 0 : 1;
            $result['artifact_ids'][] = $outcome['artifact']->getKey();
        }

        return $result;
    }

    /** @return array{artifact: GenerationArtifact, reused: bool, applied: bool} */
    public function generate(int $chapterId): array
    {
        $chapter = Chapter::query()->with(['novel', 'canonicalArtifact.generationRun'])->findOrFail($chapterId);
        $source = $this->canonicalArtifact($chapter);
        $plan = $chapter->latestPlan;
        if ($plan === null || ! is_array($plan->admission_snapshot)) {
            throw new AiProviderException('summary_capacity_contract_missing', 'Canonical Chapter Summary 缺少已冻结的 Plan Admission 合同。', false);
        }
        $settings = $this->planAdmission->historicalRouteFor($plan, AiStage::Summary);
        $promptVersion = $this->planAdmission->historicalPromptVersionFor($plan, AiStage::Summary);
        $summaryRoute = $this->outputCapacity->frozenRoute($chapter, AiStage::Summary);
        $requestBudgets = $summaryRoute['request_budgets'];
        $inputHash = app(GenerationStageFingerprint::class)->make(
            GenerationStage::MemorySummary,
            ['chapter_id' => $chapter->getKey(), 'request_budgets' => $requestBudgets, 'model_capacity' => $summaryRoute['model_capacity']],
            upstreamChecksums: [$source->checksum],
            frozen: ['provider' => $settings->provider, 'model' => $settings->model, 'reasoning_effort' => $settings->reasoningEffort],
            contractVersion: $promptVersion,
        );

        [$run, $reusable] = $this->startRun($chapter, $source, $inputHash, $promptVersion, $settings->provider, $settings->model);

        if ($reusable) {
            $artifact = $run->artifacts()->where('type', ArtifactType::Summary)->latest('id')->first();
            if (! $artifact instanceof GenerationArtifact) {
                throw ValidationException::withMessages(['summary' => '成功的摘要 Run 缺少 Summary Artifact，不能复用。']);
            }
            $summary = $this->validate($artifact->data ?? []);

            return [
                'artifact' => $artifact,
                'reused' => true,
                'applied' => $this->applyIfCurrent($chapter->getKey(), $source->getKey(), $summary['summary']),
            ];
        }

        $snapshot = $run->context_snapshot ?? [];
        data_set($snapshot, 'generation_preferences.request_budgets', $requestBudgets);
        data_set($snapshot, 'generation_preferences.model_capacity', $summaryRoute['model_capacity']);
        // Post-Commit 摘要同样冻结并展示 Route，避免恢复时受后台配置变化影响。
        data_set($snapshot, 'generation_preferences.frozen_route', [
            'provider' => $settings->provider,
            'model' => $settings->model,
            'reasoning_effort' => $settings->reasoningEffort,
            'source' => $settings->source,
            'prompt_version' => $promptVersion,
        ]);
        $run->update(['context_snapshot' => $snapshot]);

        $budget = null;
        try {
            $budget = $this->resolveRequestBudget($run);
            $request = new AiRequest(
                model: $settings->model,
                provider: $settings->provider,
                reasoningEffort: $settings->reasoningEffort,
                systemPrompt: '你是 XNovel 正式章节摘要器。只总结提供的 Canonical Chapter，不得补写正文中未发生的事实。只返回符合 Schema 的 JSON；摘要必须简短、明确，并保留影响后续章节的事件、人物变化和未决线索。'.NarrativeProsePolicy::summary(),
                prompt: json_encode([
                    'chapter' => [
                        'id' => $chapter->getKey(),
                        'sequence' => $chapter->sequence,
                        'title' => $chapter->title,
                    ],
                    'canonical_content' => $source->content,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: $budget['max_completion_tokens'],
                responseSchema: $this->schema(),
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Summary->value,
                    'provider' => $settings->provider,
                ],
            );
            // Canonical 摘要虽在 Commit 后执行，仍必须沿用该 Plan 冻结的 Summary 容量合同。
            $this->outputCapacity->assertRequestWithinFrozenRoute(
                $chapter,
                $run,
                AiStage::Summary,
                $request,
                'canonical_summary',
                $budget,
            );
            $response = $this->provider->generate($request);
            $summary = $this->validate(StructuredOutput::require($response, 'summary', 'Canonical Chapter 摘要'));

            [$artifact, $applied] = DB::transaction(function () use ($chapter, $source, $run, $summary, $response): array {
                $locked = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
                $applied = $this->isCurrent($locked, $source->getKey());
                $artifactData = [
                    ...$summary,
                    'source_canonical_artifact_id' => $source->getKey(),
                    'source_canonical_artifact_checksum' => $source->checksum,
                ];
                $artifact = $run->artifacts()->create([
                    'type' => ArtifactType::Summary,
                    'version' => 1,
                    'content' => $response->content,
                    'data' => $artifactData,
                    'checksum' => hash('sha256', json_encode($artifactData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                ]);
                $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

                if ($applied) {
                    $locked->update(['summary' => $summary['summary']]);
                }

                return [$artifact, $applied];
            });

            return ['artifact' => $artifact, 'reused' => false, 'applied' => $applied];
        } catch (Throwable $exception) {
            if ($exception instanceof AiProviderException && is_array($budget)) {
                // 摘要 Job 只有在冻结预算确实升级时才可重试，防止相同请求重复消费。
                $exception = $this->requestBudget->classifyRetry(
                    $exception,
                    $requestBudgets,
                    AiStage::Summary,
                    $budget,
                    'summary',
                    'Canonical Chapter Summary',
                    data_get($run->fresh()->context_snapshot, 'generation_preferences.request_budget_tier'),
                );
            }
            if ($run->fresh()->status !== RunStatus::Succeeded) {
                $this->failurePolicy->record(
                    $run,
                    $exception,
                    $exception instanceof ValidationException ? 'summary_validation_failed' : 'summary_generation_failed',
                );
            }

            throw $exception;
        }
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Chapter $chapter, GenerationArtifact $source, string $inputHash, string $promptVersion, string $provider, string $model): array
    {
        return DB::transaction(function () use ($chapter, $source, $inputHash, $promptVersion, $provider, $model): array {
            $locked = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            if (! $this->isCurrent($locked, $source->getKey())) {
                throw ValidationException::withMessages(['summary' => '章节的 Canonical Artifact 已变化，请重新生成摘要。']);
            }

            $runs = GenerationRun::query()
                ->where('chapter_id', $chapter->getKey())
                ->where('stage', GenerationStage::MemorySummary)
                ->where('prompt_version', $promptVersion)
                ->where('input_hash', $inputHash);
            $succeeded = $runs->clone()->where('status', RunStatus::Succeeded)->latest('id')->first();

            if ($succeeded !== null && $succeeded->artifacts()->where('type', ArtifactType::Summary)->exists()) {
                return [$succeeded, true];
            }

            if ($runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
                throw ValidationException::withMessages(['summary' => '相同 Canonical Artifact 的摘要正在生成。']);
            }

            $attempt = ((int) GenerationRun::query()
                ->where('chapter_id', $chapter->getKey())
                ->where('stage', GenerationStage::MemorySummary)
                ->where('prompt_version', $promptVersion)
                ->max('attempt')) + 1;

            return [GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scope_type' => 'chapter_summary',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::MemorySummary,
                'status' => RunStatus::Running,
                'attempt' => $attempt,
                'idempotency_key' => "chapter-summary:{$chapter->getKey()}:{$inputHash}:{$attempt}",
                'input_hash' => $inputHash,
                'state_version' => $chapter->novel->canonicalStateVersion?->version,
                'prompt_version' => $promptVersion,
                'provider' => $provider,
                'model_policy' => $model,
                'context_snapshot' => [
                    'canonical_artifact_id' => $source->getKey(),
                    'canonical_artifact_checksum' => $source->checksum,
                    'prompt_version' => $promptVersion,
                    'provider' => $provider,
                    'model' => $model,
                ],
                'started_at' => now(),
            ]), false];
        });
    }

    /** @return array{output_tokens: int, reasoning_reserve_tokens: int, max_completion_tokens: int} */
    private function resolveRequestBudget(GenerationRun $run): array
    {
        $priorRuns = GenerationRun::query()
            ->where('chapter_id', $run->chapter_id)
            ->where('stage', GenerationStage::MemorySummary)
            ->where('provider', $run->provider)
            ->where('model_policy', $run->model_policy)
            ->where('prompt_version', $run->prompt_version)
            ->where('input_hash', $run->input_hash)
            ->where('id', '<', $run->getKey())
            ->whereNotNull('error_code')
            ->orderBy('id')
            ->get(['error_code', 'context_snapshot']);
        $budgets = (array) data_get($run->context_snapshot, 'generation_preferences.request_budgets', []);
        $selection = $this->requestBudget->resolveForAttempt($budgets, AiStage::Summary, $priorRuns, 'summary', 'Canonical Chapter Summary');
        $budget = $selection['budget'];
        $snapshot = $run->context_snapshot ?? [];
        data_set($snapshot, 'generation_preferences.summary_retry_ordinal', $selection['ordinal']);
        data_set($snapshot, 'generation_preferences.request_budget_tier', $selection['tier']);
        data_set($snapshot, 'generation_preferences.request_budget_trigger', $selection['trigger']);
        data_set($snapshot, 'generation_preferences.selected_request_budget', $budget);
        data_set($snapshot, 'generation_preferences.max_completion_tokens', $budget['max_completion_tokens']);
        $run->update(['context_snapshot' => $snapshot]);

        return $budget;
    }

    private function canonicalArtifact(Chapter $chapter): GenerationArtifact
    {
        $artifact = $chapter->canonicalArtifact;
        if ($chapter->status !== ChapterStatus::Canonical
            || ! $artifact instanceof GenerationArtifact
            || blank($artifact->content)
            || $artifact->generationRun?->chapter_id !== $chapter->getKey()
            || $artifact->generationRun?->novel_id !== $chapter->novel_id) {
            throw ValidationException::withMessages([
                'summary' => '摘要只能从当前章节所属的非空 Canonical Artifact 生成。',
            ]);
        }

        return $artifact;
    }

    private function applyIfCurrent(int $chapterId, int $sourceArtifactId, string $summary): bool
    {
        return DB::transaction(function () use ($chapterId, $sourceArtifactId, $summary): bool {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapterId);
            if (! $this->isCurrent($chapter, $sourceArtifactId)) {
                return false;
            }

            $chapter->update(['summary' => $summary]);

            return true;
        });
    }

    private function isCurrent(Chapter $chapter, int $sourceArtifactId): bool
    {
        return $chapter->status === ChapterStatus::Canonical
            && $chapter->canonical_artifact_id === $sourceArtifactId;
    }

    /** @param array<string, mixed> $data @return array{summary: string, key_events: array<int, string>, character_changes: array<int, string>, unresolved_threads: array<int, string>} */
    private function validate(array $data): array
    {
        return Validator::make($data, [
            'summary' => ['required', 'string', 'max:1200'],
            'key_events' => ['present', 'array', 'max:12'],
            'key_events.*' => ['required', 'string', 'max:300'],
            'character_changes' => ['present', 'array', 'max:12'],
            'character_changes.*' => ['required', 'string', 'max:300'],
            'unresolved_threads' => ['present', 'array', 'max:12'],
            'unresolved_threads.*' => ['required', 'string', 'max:300'],
        ])->validate();
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'key_events', 'character_changes', 'unresolved_threads'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'key_events' => ['type' => 'array', 'items' => ['type' => 'string']],
                'character_changes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'unresolved_threads' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
