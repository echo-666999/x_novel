<?php

namespace App\Jobs;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Services\AutoStopService;
use App\Services\GenerationFailurePolicy;
use App\Services\PlanCoverageJudgmentRepairer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * 每次投递只复核一个 Scene Coverage，保证单个 Job 最多发送一次 Provider 请求。
 */
class AdjudicatePlanCoverageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $timeout = 330;

    /** 从统一阶段策略读取最大尝试次数。 */
    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::CoverageJudgment);
    }

    /** @return array<int, int> 返回统一阶段策略定义的退避秒数。 */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::CoverageJudgment);
    }

    /** 冻结本次复核的 Chapter、Draft 和 Scene 身份。 */
    public function __construct(
        public readonly int $chapterId,
        public readonly int $draftArtifactId,
        public readonly int $sceneId,
    ) {
        $this->onQueue('generation');
    }

    /** 同一 Draft/Scene 只允许一个待执行复核任务。 */
    public function uniqueId(): string
    {
        return "chapter:{$this->chapterId}:draft:{$this->draftArtifactId}:scene:{$this->sceneId}";
    }

    /** 执行独立复核，并由唯一流程推进器选择下一 Scene 或 Narrative Review。 */
    public function handle(PlanCoverageJudgmentRepairer $repairer, ?AdvanceChapterPipelineAction $advance = null): void
    {
        if ($this->stopWhenChapterWasDeleted($this->chapterId)) {
            return;
        }

        $advance ??= app(AdvanceChapterPipelineAction::class);

        try {
            $artifact = $repairer->judge($this->chapterId, $this->draftArtifactId, $this->sceneId);
            if ($artifact !== null && ! $this->advanceAfterSuccessfulStage(
                $advance,
                GenerationStage::CoverageJudgment,
                $this->chapterId,
                $this->sceneId,
                $artifact->generation_run_id,
            )) {
                return;
            }

            $this->releaseGenerationDispatch();
        } catch (AiProviderException $exception) {
            if (app(GenerationFailurePolicy::class)->shouldQueueRetry($exception, GenerationStage::CoverageJudgment)) {
                throw $exception;
            }

            $this->releaseGenerationDispatch();
            $this->fail($exception);
        } catch (Throwable $exception) {
            $this->handleUnexpectedGenerationFailure($exception);
        }
    }

    /** 技术失败只停止自动推进，不创建 Review 或 Rewrite 决策。 */
    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
    }
}
