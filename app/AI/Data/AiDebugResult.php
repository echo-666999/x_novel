<?php

namespace App\AI\Data;

final readonly class AiDebugResult
{
    public function __construct(
        public AiResponse $response,
        public float $estimatedCost,
    ) {}
}
