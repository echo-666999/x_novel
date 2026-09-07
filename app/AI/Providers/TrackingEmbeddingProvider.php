<?php

namespace App\AI\Providers;

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;
use App\AI\UsageRecorder;

class TrackingEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly UsageRecorder $usageRecorder,
    ) {}

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $response = $this->provider->embed($request);
        $this->usageRecorder->recordEmbedding($request, $response);

        return $response;
    }
}
