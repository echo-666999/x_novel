<?php

namespace App\AI;

final class CompletionLimitClassifier
{
    public const REASONING_BUDGET_EXHAUSTED = 'reasoning_budget_exhausted';

    public const VISIBLE_OUTPUT_TRUNCATED = 'visible_output_truncated';

    public const COMPLETION_BUDGET_EXHAUSTED = 'completion_budget_exhausted';

    /**
     * finish_reason=length 只表示完成预算用尽；必须结合可见内容与 reasoning_tokens 才能判断消耗位置。
     */
    public static function classify(mixed $finishReason, string $content, int $reasoningTokens): ?string
    {
        if ($finishReason !== 'length') {
            return null;
        }

        if (trim($content) !== '') {
            return self::VISIBLE_OUTPUT_TRUNCATED;
        }

        if ($reasoningTokens > 0) {
            return self::REASONING_BUDGET_EXHAUSTED;
        }

        return self::COMPLETION_BUDGET_EXHAUSTED;
    }
}
