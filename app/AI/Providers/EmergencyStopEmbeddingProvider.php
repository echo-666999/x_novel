<?php

namespace App\AI\Providers;

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;
use App\Services\EmergencyStopService;

class EmergencyStopEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly EmergencyStopService $emergencyStop,
    ) {}

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->emergencyStop->assertProviderRequestsAllowed();

        return $this->provider->embed($request);
    }
}
