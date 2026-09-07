<?php

namespace App\AI;

use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;
use App\Models\UsageRecord;

class UsageRecorder
{
    public function __construct(private readonly AiCostCalculator $costCalculator) {}

    public function record(AiRequest $request, AiResponse $response): UsageRecord
    {
        $generationRunId = $request->metadata['generation_run_id'] ?? null;
        $novelId = $request->metadata['novel_id'] ?? null;
        $chapterId = $request->metadata['chapter_id'] ?? null;

        return UsageRecord::query()->create([
            'generation_run_id' => is_numeric($generationRunId) ? (int) $generationRunId : null,
            'novel_id' => is_numeric($novelId) ? (int) $novelId : null,
            'chapter_id' => is_numeric($chapterId) ? (int) $chapterId : null,
            'provider' => (string) config('ai.provider'),
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cached_tokens' => $response->cachedTokens,
            'latency_ms' => $response->latencyMs,
            'estimated_cost' => $this->costCalculator->estimate($response),
            'request_id' => $response->providerRequestId,
        ]);
    }

    public function recordEmbedding(EmbeddingRequest $request, EmbeddingResponse $response): UsageRecord
    {
        $generationRunId = $request->metadata['generation_run_id'] ?? null;
        $novelId = $request->metadata['novel_id'] ?? null;
        $chapterId = $request->metadata['chapter_id'] ?? null;
        $estimatedCost = round(
            $response->inputTokens * (float) config('ai.cost.input_per_million', 0) / 1_000_000,
            6,
        );

        return UsageRecord::query()->create([
            'generation_run_id' => is_numeric($generationRunId) ? (int) $generationRunId : null,
            'novel_id' => is_numeric($novelId) ? (int) $novelId : null,
            'chapter_id' => is_numeric($chapterId) ? (int) $chapterId : null,
            'provider' => (string) config('ai.provider'),
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => 0,
            'cached_tokens' => 0,
            'latency_ms' => $response->latencyMs,
            'estimated_cost' => $estimatedCost,
            'request_id' => $response->providerRequestId,
        ]);
    }
}
