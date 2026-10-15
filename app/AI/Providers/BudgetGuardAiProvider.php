<?php

namespace App\AI\Providers;

use App\AI\BudgetService;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\Services\ContentSelfHealingService;

class BudgetGuardAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly BudgetService $budgetService,
        private readonly ?ContentSelfHealingService $contentSelfHealing = null,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $reservation = $this->budgetService->reserve($request);

        try {
            $this->contentSelfHealing?->assertCanRequest($request);

            return $this->provider->generate($request);
        } finally {
            $this->budgetService->release($reservation);
        }
    }
}
