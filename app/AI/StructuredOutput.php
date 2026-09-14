<?php

namespace App\AI;

use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;

final class StructuredOutput
{
    public static function requireContent(AiResponse $response, string $errorPrefix, string $label): string
    {
        if (filled(trim($response->content))) {
            return $response->content;
        }

        if (data_get($response->metadata, 'finish_reason') === 'length') {
            throw new AiProviderException(
                $errorPrefix.'_output_truncated',
                "AI 返回的 {$label} 因输出 Token 用尽而被截断，将按技术故障重试。",
                true,
            );
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
            throw new AiProviderException(
                $errorPrefix.'_output_truncated',
                "AI 返回的 {$label} 因输出 Token 用尽而被截断，将按技术故障重试。",
                true,
            );
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
}
