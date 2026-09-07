<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\Services\EmergencyStopService;

class EmergencyStopAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly EmergencyStopService $emergencyStop,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $this->emergencyStop->assertProviderRequestsAllowed();

        return $this->provider->generate($request);
    }
}
