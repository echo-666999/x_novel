<?php

namespace App\Jobs\Concerns;

use App\AI\Exceptions\AiProviderException;
use App\Services\GenerationFailurePolicy;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 统一区分 Outline 阶段的可重试技术故障与不可重试契约错误。 */
trait HandlesNovelOutlineStageFailures
{
    /** 让 Queue 只重试临时故障，避免对无效结构重复计费。 */
    private function handleOutlineStageFailure(Throwable $exception): void
    {
        if ($exception instanceof ValidationException
            || ($exception instanceof AiProviderException && (! $exception->retryable || str_contains($exception->errorCode, 'truncated')))) {
            $this->fail($exception);

            return;
        }

        $failure = app(GenerationFailurePolicy::class)->fromException($exception, 'outline_stage_code_failure');
        if ($failure->retryable) {
            throw $exception;
        }

        $this->fail($exception);
    }
}
