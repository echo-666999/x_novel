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
        $promptVersion = $this->promptVersionResolver->resolve($stage);

        $response = $this->provider->generate(new AiRequest(
            model: $settings->model,
            prompt: $input,
            promptVersion: $promptVersion,
            metadata: [
                'purpose' => 'ai_debug_test',
                'task_type' => $stage->value,
            ],
        ));

        return new AiDebugResult(
            response: $response,
            estimatedCost: $this->costCalculator->estimate($response),
        );
    }
}
