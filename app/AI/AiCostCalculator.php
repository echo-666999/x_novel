<?php

namespace App\AI;

use App\AI\Data\AiResponse;

class AiCostCalculator
{
    public function __construct(private readonly AiSettingsService $settings) {}

    public function estimate(AiResponse $response): float
    {
        $cost = $this->settings->costSettings();
        $uncachedInputTokens = max(0, $response->inputTokens - $response->cachedTokens);
        $inputCost = $uncachedInputTokens * (float) ($cost['input_per_million'] ?? 0);
        $cachedCost = $response->cachedTokens * (float) ($cost['cached_input_per_million'] ?? 0);
        $outputCost = $response->outputTokens * (float) ($cost['output_per_million'] ?? 0);

        return round(($inputCost + $cachedCost + $outputCost) / 1_000_000, 6);
    }

    public function estimateInputOnly(int $inputTokens): float
    {
        $cost = $this->settings->costSettings();

        return round($inputTokens * (float) ($cost['input_per_million'] ?? 0) / 1_000_000, 6);
    }
}
