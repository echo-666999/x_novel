<?php

namespace App\AI\Providers;

use App\AI\BudgetService;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;

class BudgetGuardAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly BudgetService $budgetService,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $this->budgetService->assertCanRequest($request);

        return $this->provider->generate($request);
    }
}
