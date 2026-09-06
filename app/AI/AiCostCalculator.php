<?php

namespace App\AI;

use App\AI\Data\AiResponse;

class AiCostCalculator
{
    public function estimate(AiResponse $response): float
    {
        $uncachedInputTokens = max(0, $response->inputTokens - $response->cachedTokens);
        $inputCost = $uncachedInputTokens * (float) config('ai.cost.input_per_million', 0);
        $cachedCost = $response->cachedTokens * (float) config('ai.cost.cached_input_per_million', 0);
        $outputCost = $response->outputTokens * (float) config('ai.cost.output_per_million', 0);

        return round(($inputCost + $cachedCost + $outputCost) / 1_000_000, 6);
    }
}
