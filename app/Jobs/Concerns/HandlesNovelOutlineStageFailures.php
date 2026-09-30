<?php

namespace App\Jobs\Concerns;

use App\AI\Exceptions\AiProviderException;
use App\Services\GenerationFailurePolicy;
use App\Services\NovelOutlinePipeline;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** 统一区分 Outline 阶段的可重试技术故障与不可重试契约错误。 */
trait HandlesNovelOutlineStageFailures
{
    abstract protected function outlineFailureScope(): string;

    protected function outlineFailureDiscriminator(): ?string
    {
        if (property_exists($this, 'arcKey')) {
            return $this->arcKey;
        }

        return property_exists($this, 'beatKey') ? $this->beatKey : null;
    }

    /** 让 Queue 只重试临时故障，避免对无效结构重复计费。 */
    private function handleOutlineStageFailure(Throwable $exception): void
    {
        if ($exception instanceof ValidationException
            || ($exception instanceof AiProviderException && (! $exception->retryable || str_contains($exception->errorCode, 'truncated')))) {
            $this->closeOutlineBatch($exception, false);
            $this->fail($exception);

            return;
        }

        $failure = app(GenerationFailurePolicy::class)->fromException($exception, 'outline_stage_code_failure');
        if ($failure->retryable) {
            throw $exception;
        }

        $this->closeOutlineBatch($exception, false);
        $this->fail($exception);
    }

    /** Queue 重试耗尽后才关闭主批次；第一次临时失败仍保持 running。 */
    public function failed(?Throwable $exception): void
    {
        $exception ??= new RuntimeException('Outline 阶段 Job 失败。');
        $failure = app(GenerationFailurePolicy::class)->fromException($exception, 'outline_stage_failed');
        $this->closeOutlineBatch($exception, $failure->retryable);
    }

    private function closeOutlineBatch(Throwable $exception, bool $autoRetryExhausted): void
    {
        app(NovelOutlinePipeline::class)->markBatchFailed(
            $this->batchRunId,
            $exception,
            $this->outlineFailureScope(),
            $this->outlineFailureDiscriminator(),
            $autoRetryExhausted,
        );
    }
}
