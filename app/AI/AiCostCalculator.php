<?php

namespace App\AI;

use App\AI\Data\AiResponse;
use App\Models\AIModelPrice;
use Illuminate\Support\Facades\Schema;

class AiCostCalculator
{
    public function __construct(private readonly AiSettingsService $settings) {}

    public function estimate(AiResponse $response, ?string $provider = null): float
    {
        $price = $this->modelPrice($provider, $response->model);
        if ($price !== null) {
            $uncachedInputTokens = max(0, $response->inputTokens - $response->cachedTokens);
            $billingUnit = max(1, $price->billing_unit);

            return round((
                $uncachedInputTokens * (float) ($price->input_price ?? 0)
                + $response->cachedTokens * (float) ($price->cached_input_price ?? 0)
                + $response->outputTokens * (float) ($price->output_price ?? 0)
            ) / $billingUnit, 6);
        }

        $cost = $this->settings->costSettings();
        $uncachedInputTokens = max(0, $response->inputTokens - $response->cachedTokens);
        $inputCost = $uncachedInputTokens * (float) ($cost['input_per_million'] ?? 0);
        $cachedCost = $response->cachedTokens * (float) ($cost['cached_input_per_million'] ?? 0);
        $outputCost = $response->outputTokens * (float) ($cost['output_per_million'] ?? 0);

        return round(($inputCost + $cachedCost + $outputCost) / 1_000_000, 6);
    }

    public function estimateInputOnly(int $inputTokens, ?string $provider = null, ?string $model = null): float
    {
        $price = $this->modelPrice($provider, $model);
        if ($price !== null) {
            return round($inputTokens * (float) ($price->input_price ?? 0) / max(1, $price->billing_unit), 6);
        }

        $cost = $this->settings->costSettings();

        return round($inputTokens * (float) ($cost['input_per_million'] ?? 0) / 1_000_000, 6);
    }

    private function modelPrice(?string $provider, ?string $model): ?AIModelPrice
    {
        if (blank($provider) || blank($model) || ! Schema::hasTable('ai_model_prices')) {
            return null;
        }

        return AIModelPrice::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('currency', strtoupper((string) config('ai.cost.currency', 'USD')))
            ->where('is_enabled', true)
            ->first();
    }
}
