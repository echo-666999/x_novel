<?php

namespace App\AI\Data;

final readonly class EmbeddingResponse
{
    /** @param array<int, float> $embedding */
    public function __construct(
        public array $embedding,
        public int $inputTokens,
        public int $latencyMs,
        public ?string $providerRequestId,
        public string $model,
        // 供应商未返回用量时不能把DTO的数值兼容默认值记作免费。
        public bool $usageAvailable = true,
    ) {}
}
