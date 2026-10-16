<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ContentRepairType;
use App\Enums\GenerationStage;
use App\Exceptions\GenerationPreflightException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Services\AutoStopService;
use App\Services\ChapterPlanner;
use App\Services\ContentSelfHealingService;
use App\Services\GenerationFailurePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlanChapterJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $timeout = 330;

    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::ChapterPlanning);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::ChapterPlanning);
    }

    public function __construct(
        public readonly int $chapterId,
        public readonly bool $regenerate = false,
        public readonly ?int $contentRepairRunId = null,
    ) {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    public function handle(
        ChapterPlanner $planner,
        ?AdvanceChapterPipelineAction $advance = null,
        ?ContentSelfHealingService $contentSelfHealing = null,
    ): void {
        if ($this->stopWhenChapterWasDeleted($this->chapterId)) {
            return;
        }

        $advance ??= app(AdvanceChapterPipelineAction::class);
        $contentSelfHealing ??= app(ContentSelfHealingService::class);
        try {
            $plan = $this->contentRepairRunId === null
                ? $planner->generate($this->chapterId, $this->regenerate)
                : $planner->generate($this->chapterId, $this->regenerate, $this->contentRepairRunId);
            if ($plan !== null) {
                if (! $this->advanceAfterSuccessfulStage(
                    $advance,
                    GenerationStage::ChapterPlanning,
                    $this->chapterId,
                )) {
                    return;
                }
            }

            $this->releaseGenerationDispatch();
        } catch (ValidationException $exception) {
            $chapter = Chapter::query()->with('novel.canonicalStateVersion')->findOrFail($this->chapterId);
            $currentRepair = $this->contentRepairRunId === null
                ? $contentSelfHealing->activeForChapter($chapter)
                : GenerationRun::query()->find($this->contentRepairRunId);
            // 审校容量由全局预算和冻结路由决定，重新让 Planner 写计划无法改变这项配置。
            if (str_contains(collect($exception->errors())->flatten()->implode(' '), '[REVIEW_CAPACITY_EXCEEDED]')) {
                if ($currentRepair !== null) {
                    $contentSelfHealing->fail(
                        $currentRepair,
                        'review_capacity_exceeded',
                        $exception->getMessage(),
                        ['validation_errors' => $exception->errors()],
                    );
                }
                $this->releaseGenerationDispatch();
                $this->fail(new AiProviderException(
                    'review_capacity_exceeded',
                    'Reviewer 容量不足；请提高审校上下文预算后重新生成章节计划。',
                    false,
                    previous: $exception,
                ));

                return;
            }
            if ($this->contentRepairRunId !== null && $currentRepair !== null
                && ! $chapter->generationRuns()
                    ->where('stage', GenerationStage::ChapterPlanning)
                    ->where('created_at', '>=', $currentRepair->created_at)
                    ->exists()) {
                // 修复规划尚未创建 Run 就被确定性校验拒绝，重复派发同一输入只会形成无限循环。
                $contentSelfHealing->fail($currentRepair, 'content_repair_preflight_failed', $exception->getMessage(), [
                    'validation_errors' => $exception->errors(),
                ]);
                $this->releaseGenerationDispatch();
                $this->fail(new AiProviderException(
                    'content_repair_preflight_failed',
                    '内容修复在规划请求前被确定性校验拒绝，请检查修复诊断。',
                    false,
                    previous: $exception,
                ));

                return;
            }
            if ($currentRepair !== null && $contentSelfHealing->type($currentRepair) !== ContentRepairType::PlanValidation) {
                $maxNested = (int) config('generation.content_self_healing.plan_validation.max_attempts', 1);
                $nestedFailures = $chapter->generationRuns()
                    ->where('stage', GenerationStage::ChapterPlanning)
                    ->where('error_code', 'plan_validation_failed')
                    ->where('started_at', '>=', $currentRepair->started_at)
                    ->count();
                if ($nestedFailures < $maxNested) {
                    $this->releaseGenerationDispatch();
                    if (! $this->dispatchGenerationJob(new self(
                        chapterId: $this->chapterId,
                        regenerate: true,
                        contentRepairRunId: $currentRepair->getKey(),
                    ))) {
                        $dispatchException = new AiProviderException('content_repair_dispatch_failed', '自动重规划任务未能进入队列。', false);
                        $contentSelfHealing->fail($currentRepair, $dispatchException->errorCode, $dispatchException->getMessage());
                        $this->fail($dispatchException);
                    }

                    return;
                }

                $contentSelfHealing->fail($currentRepair, 'content_repair_plan_validation_exhausted', $exception->getMessage(), [
                    'validation_errors' => $exception->errors(),
                ]);
                $this->releaseGenerationDispatch();
                $this->fail(new AiProviderException(
                    'content_repair_plan_validation_exhausted',
                    '章节重建中的 Plan 连续校验失败，已达到自动重规划上限。',
                    false,
                    previous: $exception,
                ));

                return;
            }

            $failedRun = $chapter->generationRuns()
                ->where('stage', GenerationStage::ChapterPlanning)
                ->where('error_code', 'plan_validation_failed')
                ->latest('id')
                ->first();
            try {
                $repair = $contentSelfHealing->start(
                    $chapter,
                    ContentRepairType::PlanValidation,
                    ['planning_run_id' => $failedRun?->getKey()],
                    [
                        'validation_errors' => $exception->errors(),
                        'failed_candidate' => data_get($failedRun?->error_metadata, 'plan_candidate'),
                    ],
                );
            } catch (AiProviderException $limitException) {
                $this->releaseGenerationDispatch();
                $this->fail($limitException);

                return;
            }
            $this->releaseGenerationDispatch();
            if (! $this->dispatchGenerationJob(new self(
                chapterId: $this->chapterId,
                regenerate: true,
                contentRepairRunId: $repair->getKey(),
            ))) {
                $dispatchException = new AiProviderException('content_repair_dispatch_failed', '自动重规划任务未能进入队列。', false);
                $contentSelfHealing->fail($repair, $dispatchException->errorCode, $dispatchException->getMessage());
                $this->fail($dispatchException);
            }
        } catch (AiProviderException $exception) {
            if (app(GenerationFailurePolicy::class)->shouldQueueRetry($exception, GenerationStage::ChapterPlanning)) {
                throw $exception;
            }

            $this->releaseGenerationDispatch();
            $this->fail($exception);
        } catch (GenerationPreflightException $exception) {
            $this->releaseGenerationDispatch();
            $this->fail($exception);
        } catch (Throwable $exception) {
            $this->handleUnexpectedGenerationFailure($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
    }
}
