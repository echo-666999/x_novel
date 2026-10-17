<?php

namespace App\AI\Providers;

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;
use App\AI\RequestUsageLedger;
use App\AI\UsageRecorder;
use Throwable;

/** 向量请求也先预留与建账，不能绕过已主动启用的金额上限。 */
class TrackingEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private readonly EmbeddingProvider $provider, private readonly UsageRecorder $usageRecorder) {}

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $ledger = app(RequestUsageLedger::class);
        $entry = $ledger->prepare($request);
        $sent = false;
        try {
            $ledger->send($entry);
            $sent = true;
            $response = $this->provider->embed($request);
            $this->usageRecorder->recordEmbedding($request, $response, $entry);

            return $response;
        } catch (Throwable $exception) {
            $ledger->failed($entry, ! $sent);
            throw $exception;
        }
    }
}
