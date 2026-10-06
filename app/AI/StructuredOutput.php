<?php

namespace App\AI;

use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;

final class StructuredOutput
{
    public static function requireContent(AiResponse $response, string $errorPrefix, string $label): string
    {
        if (data_get($response->metadata, 'finish_reason') === 'length') {
            self::throwCompletionLimit($response, $errorPrefix, $label);
        }

        if (filled(trim($response->content))) {
            return $response->content;
        }

        if (filled(data_get($response->metadata, 'refusal'))) {
            throw new AiProviderException(
                $errorPrefix.'_refused',
                "AI 拒绝生成 {$label}。",
                false,
            );
        }

        throw new AiProviderException(
            $errorPrefix.'_empty',
            "AI 返回的 {$label} 为空。",
            false,
        );
    }

    /** @return array<string, mixed> */
    public static function require(AiResponse $response, string $errorPrefix, string $label): array
    {
        if ($response->structuredData !== null) {
            return $response->structuredData;
        }

        if (data_get($response->metadata, 'finish_reason') === 'length') {
            self::throwCompletionLimit($response, $errorPrefix, $label);
        }

        if (filled(data_get($response->metadata, 'refusal'))) {
            throw new AiProviderException(
                $errorPrefix.'_refused',
                "AI 拒绝生成 {$label}，无法作为合法结构化结果处理。",
                false,
            );
        }

        throw new AiProviderException(
            $errorPrefix.'_schema_invalid',
            "AI 未返回合法的结构化 {$label}。",
            false,
        );
    }

    private static function throwCompletionLimit(AiResponse $response, string $errorPrefix, string $label): never
    {
        $reason = data_get($response->metadata, 'completion_limit_reason');
        if (! is_string($reason) || $reason === '') {
            // 兼容旧 Provider/Fake 响应，但不能在缺少证据时猜测预算耗在推理或可见输出。
            $reason = CompletionLimitClassifier::classify(
                data_get($response->metadata, 'finish_reason'),
                $response->content,
                $response->reasoningTokens,
            );
        }

        if ($reason === CompletionLimitClassifier::REASONING_BUDGET_EXHAUSTED) {
            throw new AiProviderException(
                $errorPrefix.'_reasoning_budget_exhausted',
                "AI 在生成可见的 {$label} 前已耗尽推理预算，未产生可保存结果。请增加推理预留或降低推理程度。",
                false,
            );
        }

        if ($reason === CompletionLimitClassifier::VISIBLE_OUTPUT_TRUNCATED) {
            throw new AiProviderException(
                $errorPrefix.'_output_truncated',
                "AI 已开始返回 {$label}，但可见输出在完成前耗尽请求预算；未保存不完整结果。",
                true,
            );
        }

        throw new AiProviderException(
            $errorPrefix.'_completion_budget_exhausted',
            "AI 未产生完整的 {$label}，且 Provider 未提供足够信息判断预算耗在推理还是可见输出。请检查 Usage 后调整请求预算。",
            false,
        );
    }
}
