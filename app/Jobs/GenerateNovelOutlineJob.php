<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderException;
use App\Enums\RunStatus;
use App\Jobs\Concerns\PreventsDuplicateGeneration;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Services\GenerationFailurePolicy;
use App\Services\NovelOutlinePipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateNovelOutlineJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PreventsDuplicateGeneration, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 330;

    /** @var array<int> */
    public array $backoff = [10, 30];

    public ?int $batchRunId = null;

    public function __construct(
        public readonly int $novelId,
        public readonly int $volumeCount,
    ) {
        $this->onQueue('generation');
    }

    public function uniqueId(): string
    {
        return 'novel-outline:'.$this->novelId;
    }

    public function handle(NovelOutlinePipeline $pipeline): void
    {
        $novel = Novel::query()->find($this->novelId);

        if ($novel === null) {
            return;
        }

        try {
            // 旧 Payload 仍只携带 Novel 与分卷数；Worker 由此定位页面已准备的 queued Batch。
            $batch = $pipeline->prepareBatch($novel, $this->volumeCount);
            $this->batchRunId = $batch->getKey();
            $batch = $pipeline->activateBatch($batch);
            $pipeline->dispatchNext($batch);
        } catch (AiProviderException $exception) {
            // 相同 Token 上限下重试截断响应只会重复产生费用，必须先调整请求预算。
            if (! $exception->retryable || str_contains($exception->errorCode, 'truncated')) {
                $this->closeBatch($pipeline, $exception, false);
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (ValidationException $exception) {
            // Schema 或领域校验失败需要修正输入，重复调用模型不会自行恢复。
            $this->closeBatch($pipeline, $exception, false);
            $this->fail($exception);
        } catch (Throwable $exception) {
            $failure = app(GenerationFailurePolicy::class)
                ->fromException($exception, 'novel_planning_code_failure');

            if ($failure->retryable) {
                throw $exception;
            }

            $this->closeBatch($pipeline, $exception, false);
            $this->fail($exception);
        } finally {
            $this->releaseGenerationDispatch();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseGenerationDispatch();
        if ($exception === null) {
            return;
        }

        $failure = app(GenerationFailurePolicy::class)->fromException($exception, 'novel_planning_code_failure');
        $this->closeBatch(app(NovelOutlinePipeline::class), $exception, $failure->retryable);
    }

    private function closeBatch(NovelOutlinePipeline $pipeline, Throwable $exception, bool $autoRetryExhausted): void
    {
        $batchRunId = $this->batchRunId ?? GenerationRun::query()
            ->where('novel_id', $this->novelId)
            ->where('scope_type', NovelOutlinePipeline::BATCH_SCOPE)
            ->where('scope_id', $this->novelId)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->latest('id')
            ->get()
            ->first(fn (GenerationRun $run): bool => (int) data_get($run->context_snapshot, 'requested_volume_count') === $this->volumeCount)
            ?->getKey();
        if ($batchRunId === null) {
            return;
        }

        $pipeline->markBatchFailed(
            $batchRunId,
            $exception,
            NovelOutlinePipeline::BATCH_SCOPE,
            null,
            $autoRetryExhausted,
        );
    }
}
