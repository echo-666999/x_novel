<?php

namespace App\AI\Data;

final readonly class EmbeddingRequest
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $model,
        public string $input,
        public int $dimensions,
        public array $metadata = [],
    ) {}
}
