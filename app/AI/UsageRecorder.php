<?php

namespace App\AI;

use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;
use App\Enums\AiStage;
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
            'provider' => $request->provider ?? throw new \LogicException('AI request provider must be resolved before usage is recorded.'),
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cached_tokens' => $response->cachedTokens,
            'latency_ms' => $response->latencyMs,
            'estimated_cost' => $this->costCalculator->estimate($response, $request->provider),
            'request_id' => $response->providerRequestId,
            'request_metadata' => array_filter([
                'stage' => $request->metadata['stage'] ?? null,
                'substage' => $request->metadata['substage'] ?? null,
                'route_key' => $request->metadata['route_key'] ?? null,
                'prompt_version' => $request->promptVersion,
                'reasoning_effort_sent' => $request->provider === 'deepseek' ? null : $request->reasoningEffort,
                'max_output_tokens' => $request->maxTokens,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
    }

    public function recordEmbedding(EmbeddingRequest $request, EmbeddingResponse $response): UsageRecord
    {
        $generationRunId = $request->metadata['generation_run_id'] ?? null;
        $novelId = $request->metadata['novel_id'] ?? null;
        $chapterId = $request->metadata['chapter_id'] ?? null;
        $provider = (string) ($request->metadata['provider'] ?? config('ai.embedding.provider', 'openai'));
        $estimatedCost = $this->costCalculator->estimateInputOnly($response->inputTokens, $provider, $response->model);

        return UsageRecord::query()->create([
            'generation_run_id' => is_numeric($generationRunId) ? (int) $generationRunId : null,
            'novel_id' => is_numeric($novelId) ? (int) $novelId : null,
            'chapter_id' => is_numeric($chapterId) ? (int) $chapterId : null,
            'provider' => $provider,
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => 0,
            'cached_tokens' => 0,
            'latency_ms' => $response->latencyMs,
            'estimated_cost' => $estimatedCost,
            'request_id' => $response->providerRequestId,
            'request_metadata' => [
                'stage' => AiStage::Embedding->value,
                'embedding_dimensions' => $request->dimensions,
            ],
        ]);
    }
}
