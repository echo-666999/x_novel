<?php

namespace App\AI\Data;

final readonly class AiResponse
{
    /**
     * @param  array<string, mixed>|null  $structuredData
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $content,
        public ?array $structuredData,
        public int $inputTokens,
        public int $outputTokens,
        public int $cachedTokens,
        public int $latencyMs,
        public ?string $providerRequestId,
        public string $model,
        public array $metadata = [],
        public int $reasoningTokens = 0,
        // 供应商未返回用量时不能把DTO的数值兼容默认值记作免费。
        public bool $usageAvailable = true,
    ) {}
}
