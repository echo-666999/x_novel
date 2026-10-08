<?php

namespace App\Actions\Chapters;

use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Jobs\ExtractStoryEventsJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Services\ContextBuilder;
use App\Services\GenerationJobDispatcher;
use App\Services\GenerationOutputCapacityGuard;
use App\Services\GenerationStageFingerprint;
use App\Services\PlanAdmissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RecoverLegacyEventExtractionAction
{
    public function __construct(
        private readonly PlanAdmissionService $planAdmission,
        private readonly ContextBuilder $contextBuilder,
        private readonly GenerationStageFingerprint $fingerprint,
        private readonly GenerationJobDispatcher $dispatcher,
    ) {}

    /**
     * 只为 Admission v1 的既有正文创建事件提取恢复 Run；Plan、Scene、Draft 与历史 Run 均保持不可变。
     */
    public function handle(Chapter $chapter): GenerationRun
    {
        [$run, $shouldDispatch] = DB::transaction(function () use ($chapter): array {
            $locked = Chapter::query()->lockForUpdate()->with([
                'novel.canonicalStateVersion',
                'latestPlan',
                'scenes:id,chapter_id,sequence,current_artifact_id',
            ])->findOrFail($chapter->getKey());
            $novel = $locked->novel;
            $plan = $locked->latestPlan;

            if ($novel->status === NovelStatus::Paused) {
                throw ValidationException::withMessages(['chapter' => '小说已暂停，不能创建事件提取恢复合同。']);
            }
            if ($locked->status === ChapterStatus::Canonical) {
                throw ValidationException::withMessages(['chapter' => '正式章节不能重新创建事件候选。']);
            }
            if ($plan === null || (int) data_get($plan->admission_snapshot, 'schema_version') !== 1) {
                throw ValidationException::withMessages([
                    'plan' => '[LEGACY_EVENT_RECOVERY_NOT_APPLICABLE] 只有 Admission v1 Chapter Plan 可以使用此恢复动作。',
                ]);
            }
            if ($locked->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
                throw ValidationException::withMessages(['generation_runs' => '当前章节仍有排队中或运行中的 Generation Run，请等待结束后再恢复。']);
            }

            $draft = $this->latestChapterDraft($locked);
            $stateVersion = $novel->canonicalStateVersion?->version;
            if ($stateVersion === null) {
                throw ValidationException::withMessages(['state' => '小说缺少 Canonical State Version，不能冻结恢复来源。']);
            }
            $bibleVersion = $this->contextBuilder->bibleVersionForChapter($locked);
            $resolved = $this->planAdmission->currentStageContract($novel, AiStage::Extractor);
            $route = $resolved['route'];
            $source = [
                'chapter_id' => $locked->getKey(),
                'plan_id' => $plan->getKey(),
                'plan_version' => $plan->version,
                'plan_checksum' => $plan->checksum ?: $plan->semanticChecksum(),
                'admission_schema_version' => 1,
                'admission_input_hash' => data_get($plan->admission_snapshot, 'input_hash'),
                'admission_snapshot_checksum' => hash('sha256', json_encode(
                    $this->fingerprint->normalize($plan->admission_snapshot),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                )),
                'draft_artifact_id' => $draft->getKey(),
                'draft_checksum' => $draft->checksum,
                'state_version' => $stateVersion,
                'bible_version' => $bibleVersion,
                'scene_artifacts' => $locked->scenes->map(fn ($scene): array => [
                    'scene_id' => $scene->getKey(),
                    'sequence' => $scene->sequence,
                    'current_artifact_id' => $scene->current_artifact_id,
                ])->values()->all(),
            ];
            $contractChecksum = $this->fingerprint->make(
                GenerationStage::EventExtraction,
                $source,
                upstreamChecksums: [$draft->checksum],
                frozen: $route,
                contractVersion: GenerationOutputCapacityGuard::LEGACY_EVENT_RECOVERY_CONTRACT,
            );
            $baseKey = "events:{$draft->checksum}:{$stateVersion}:{$route['prompt_version']}:recovery:{$contractChecksum}";

            $existing = GenerationRun::query()->where('idempotency_key', $baseKey)->first();
            if ($existing !== null) {
                if (in_array($existing->status, [RunStatus::Queued, RunStatus::Running, RunStatus::Succeeded], true)) {
                    return [$existing, false];
                }

                throw ValidationException::withMessages([
                    'recovery' => '[LEGACY_EVENT_RECOVERY_CONTRACT_ALREADY_FAILED] 相同来源与 Extractor 合同已执行失败；请先修复路由或预算后重新创建恢复合同。',
                ]);
            }

            $attempt = ((int) $locked->generationRuns()->where('stage', GenerationStage::EventExtraction)->max('attempt')) + 1;
            $run = GenerationRun::query()->create([
                'novel_id' => $novel->getKey(),
                'chapter_id' => $locked->getKey(),
                'scene_id' => null,
                'scope_type' => 'chapter',
                'scope_id' => $locked->getKey(),
                'stage' => GenerationStage::EventExtraction,
                'status' => RunStatus::Queued,
                'attempt' => $attempt,
                'idempotency_key' => $baseKey,
                'input_hash' => $contractChecksum,
                'state_version' => $stateVersion,
                'bible_version' => $bibleVersion,
                'prompt_version' => $route['prompt_version'],
                'provider' => $route['provider'],
                'model_policy' => $route['model'],
                'context_snapshot' => [
                    'recovery' => [
                        'contract_version' => GenerationOutputCapacityGuard::LEGACY_EVENT_RECOVERY_CONTRACT,
                        'contract_checksum' => $contractChecksum,
                        'base_key' => $baseKey,
                        'source' => $source,
                    ],
                    'generation_preferences' => [
                        'route_contract_source' => GenerationOutputCapacityGuard::LEGACY_EVENT_RECOVERY_CONTRACT,
                        'model_capacity' => $route['model_capacity'],
                        'request_budgets' => $route['request_budgets'],
                        'frozen_route' => [
                            'provider' => $route['provider'],
                            'model' => $route['model'],
                            'reasoning_effort' => $route['reasoning_effort'],
                            'source' => $resolved['source'],
                            'prompt_version' => $route['prompt_version'],
                        ],
                        'repair_budgets' => [
                            'event_evidence' => [
                                'initial' => (int) config('generation.event_evidence_repair_max_output_tokens', 1_000),
                                'retry' => (int) config('generation.event_evidence_repair_retry_max_output_tokens', 4_000),
                            ],
                        ],
                    ],
                ],
            ]);

            return [$run, true];
        }, 3);

        if (! $shouldDispatch) {
            return $run->refresh();
        }

        try {
            $dispatched = $this->dispatcher->dispatch(new ExtractStoryEventsJob(
                chapterId: $run->chapter_id,
                regenerate: true,
                continueRewrite: true,
                recoveryRunId: $run->getKey(),
            ));
            if (! $dispatched) {
                throw ValidationException::withMessages(['queue' => '事件提取任务已经在队列中，未重复派发恢复 Run。']);
            }
        } catch (Throwable $exception) {
            // 合同已经持久化但 Job 未入队时必须留下明确失败证据，不能制造永久 queued 假象。
            $run->update([
                'status' => RunStatus::Failed,
                'error_code' => 'event_recovery_queue_dispatch_failed',
                'error_message' => $exception->getMessage(),
                'error_retryable' => true,
                'error_metadata' => [
                    'category' => 'queue_dispatch_failed',
                    'stage' => GenerationStage::EventExtraction->value,
                    'next_action' => '重新创建事件提取恢复合同',
                ],
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        return $run->refresh();
    }

    private function latestChapterDraft(Chapter $chapter): GenerationArtifact
    {
        $draft = GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $chapter->getKey())
                ->whereNull('scene_id'))
            ->latest('id')
            ->first();

        if ($draft === null) {
            throw ValidationException::withMessages(['draft' => '当前章节缺少可复用的 Chapter Draft，不能只恢复事件提取。']);
        }

        return $draft;
    }
}
