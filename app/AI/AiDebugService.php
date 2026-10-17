<?php

namespace App\AI;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiDebugResult;
use App\AI\Data\AiRequest;
use App\Enums\AiStage;

final class AiDebugService
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly PromptVersionResolver $promptVersionResolver,
        private readonly AiCostCalculator $costCalculator,
    ) {}

    public function run(AiStage $stage, string $input): AiDebugResult
    {
        $settings = $this->settingsResolver->resolve($stage);
        // Debug 请求不注入 NarrativeProsePolicy，因此记录基础版本，不能伪装成生产有效版本。
        $promptVersion = $this->promptVersionResolver->resolveBase($stage);

        $response = $this->provider->generate(new AiRequest(
            model: $settings->model,
            provider: $settings->provider,
            reasoningEffort: $settings->reasoningEffort,
            prompt: $input,
            promptVersion: $promptVersion,
            metadata: [
                'purpose' => 'ai_debug_test',
                'task_type' => $stage->value,
            ],
        ));

        return new AiDebugResult(
            response: $response,
            estimatedCost: $this->costCalculator->estimate($response, $settings->provider),
        );
    }
}
