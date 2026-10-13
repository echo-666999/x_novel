<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\UsageRecorder;

class TrackingAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly UsageRecorder $usageRecorder,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        try {
            $response = $this->provider->generate($request);
        } catch (AiProviderException $exception) {
            // HTTP 请求已经产生真实 Usage 时，即使结构化内容无法采用，也必须保留费用与诊断记录。
            if ($exception->usageResponse !== null) {
                $this->usageRecorder->record($request, $exception->usageResponse);
            }

            throw $exception;
        }
        $this->usageRecorder->record($request, $response);

        return $response;
    }
}
