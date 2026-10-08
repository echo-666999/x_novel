<?php

namespace App\Jobs;

use App\Actions\Chapters\StartChapterRewriteAction;
use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\GenerationStage;
use App\Exceptions\GenerationStageDeferredException;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Models\Chapter;
use App\Services\AutoStopService;
use App\Services\ChapterRewriter;
use App\Services\GenerationFailurePolicy;
use App\Services\GenerationProgressionFailureRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** 执行局部 Rewrite；发现旧 Coverage 误判来源时改由统一推进器先派发 Judgment。 */
class RewriteChapterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $timeout = 330;

    public function tries(): int
    {
        return app(GenerationFailurePolicy::class)->maxAttempts(GenerationStage::Rewrite);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return app(GenerationFailurePolicy::class)->backoff(GenerationStage::Rewrite);
    }

    public function __construct(public readonly int $chapterId, public readonly ?int $sceneId = null)
    {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'chapter:'.$this->chapterId;
    }

    /** 执行一次 Rewrite Provider 调用或把旧 Review 安全退回 Coverage Judgment。 */
    public function handle(ChapterRewriter $rewriter, ?AdvanceChapterPipelineAction $advance = null): void
    {
        if ($this->stopWhenChapterWasDeleted($this->chapterId)) {
            return;
        }

        $advance ??= app(AdvanceChapterPipelineAction::class);

        try {
            $artifact = $rewriter->rewrite($this->chapterId, $this->sceneId, singleProviderCall: true);
            if ($artifact === null) {
                $this->releaseGenerationDispatch();

                return;
            }
            if (! $this->advanceAfterSuccessfulStage(
                $advance,
                GenerationStage::Rewrite,
                $this->chapterId,
                $this->sceneId,
                $artifact->generation_run_id,
            )) {
                return;
            }
            $this->releaseGenerationDispatch();
        } catch (GenerationStageDeferredException) {
            $this->releaseGenerationDispatch();
            $advance->handle($this->chapterId);

            return;
        } catch (AiProviderException $exception) {
            if ($exception->errorCode === 'coverage_judgment_required') {
                // 已经排队的旧 Rewrite Job 也必须走同一领域入口，先解除旧 Coverage Block 并恢复正确流程。
                $this->releaseGenerationDispatch();
                try {
                    app(StartChapterRewriteAction::class)->handle(Chapter::query()->findOrFail($this->chapterId));
                } catch (Throwable $progressionException) {
                    // Rewrite Run 尚未创建时，把失败挂到成功 Review，页面仍能看到真实阻断原因。
                    app(GenerationProgressionFailureRecorder::class)->record(
                        GenerationStage::Review,
                        $this->chapterId,
                        $progressionException,
                    );
                    $this->fail($progressionException);
                }

                return;
            }
            $policy = app(GenerationFailurePolicy::class);
            if ($policy->shouldQueueRetry($exception, GenerationStage::Rewrite)) {
                throw $exception;
            }
            if ($policy->shouldMarkTerminal(GenerationStage::Rewrite, $exception)) {
                $rewriter->markTerminalFailure($this->chapterId);
            }
            $this->releaseGenerationDispatch();
            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            $this->handleUnexpectedGenerationFailure($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        if (app(GenerationFailurePolicy::class)->shouldMarkTerminal(GenerationStage::Rewrite, $exception)) {
            app(AutoStopService::class)->stopForFailure($this->chapterId, $exception);
            app(ChapterRewriter::class)->markTerminalFailure($this->chapterId);
        }
    }
}
