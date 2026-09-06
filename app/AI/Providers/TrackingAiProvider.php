<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\UsageRecorder;

class TrackingAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly UsageRecorder $usageRecorder,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $response = $this->provider->generate($request);
        $this->usageRecorder->record($request, $response);

        return $response;
    }
}
