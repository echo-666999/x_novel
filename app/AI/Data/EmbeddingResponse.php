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
    ) {}
}
