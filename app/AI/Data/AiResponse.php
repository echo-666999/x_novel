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
    ) {}
}
