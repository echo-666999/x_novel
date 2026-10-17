<?php

namespace App\AI;

use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;
use App\Models\UsageRecord;
use App\Services\PromptExecutionContract;
use Illuminate\Support\Arr;

/** 所有调用结算到发送前建立的同一请求，不再事后另建费用行。 */
class UsageRecorder
{
    public function __construct(private readonly RequestUsageLedger $ledger) {}

    public function record(AiRequest $request, AiResponse $response, ?UsageRecord $entry = null): UsageRecord
    {
        $entry ??= $this->ledger->prepare($request);
        if (! $response->usageAvailable) {
            // 正文成功不证明账单完整，保留预留并显示待确认用量。
            return $this->ledger->unknownResponse($entry, $response->providerRequestId, $response->model, $response->latencyMs);
        }

        return $this->ledger->settle($entry, [
            // 用量证据白名单保留实际返回模型与截断原因，不复制完整原始响应。
            'request_metadata' => [...$entry->request_metadata,
                'actual_model' => $response->model, 'prompt_version' => $request->promptVersion,
                'prompt_checksum' => app(PromptExecutionContract::class)->make($request)['checksum'],
                'reasoning_effort_sent' => $request->reasoningEffort, 'max_output_tokens' => $request->maxTokens,
                'finish_reason' => data_get($response->metadata, 'finish_reason'),
                'completion_limit_reason' => data_get($response->metadata, 'completion_limit_reason'),
                'sent_parameters' => Arr::only((array) data_get($response->metadata, 'sent_parameters', []), ['model', 'max_tokens', 'max_completion_tokens', 'reasoning_effort', 'temperature']),
            ],
            'input_tokens' => $response->inputTokens, 'output_tokens' => $response->outputTokens,
            'cached_tokens' => $response->cachedTokens, 'reasoning_tokens' => $response->reasoningTokens,
            'latency_ms' => $response->latencyMs, 'request_id' => $response->providerRequestId,
        ]);
    }

    public function recordEmbedding(EmbeddingRequest $request, EmbeddingResponse $response, ?UsageRecord $entry = null): UsageRecord
    {
        $entry ??= $this->ledger->prepare($request);
        if (! $response->usageAvailable) {
            // 正文成功不证明账单完整，保留预留并显示待确认用量。
            return $this->ledger->unknownResponse($entry, $response->providerRequestId, $response->model, $response->latencyMs);
        }

        return $this->ledger->settle($entry, [
            'request_metadata' => [...$entry->request_metadata, 'actual_model' => $response->model, 'embedding_dimensions' => $request->dimensions],
            'input_tokens' => $response->inputTokens, 'output_tokens' => 0, 'cached_tokens' => 0, 'reasoning_tokens' => 0,
            'latency_ms' => $response->latencyMs, 'request_id' => $response->providerRequestId,
        ]);
    }
}
