<?php

namespace App\AI\Providers;

use App\AI\AiSettingsService;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\Models\GenerationRun;

class RoutingAiProvider implements AiProvider
{
    /** @param array<string, AiProvider> $providers */
    public function __construct(
        private readonly array $providers,
        private readonly AiSettingsService $settings,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        [$provider, $frozenOnRun] = $this->resolveProvider($request);
        $adapter = $this->providers[$provider] ?? null;

        if (! $adapter instanceof AiProvider) {
            throw new AiProviderException('provider_unsupported', "AI provider [{$provider}] is not registered.", false);
        }

        $this->settings->assertProviderAvailable($provider, requireEnabled: ! $frozenOnRun);

        return $adapter->generate($request->withProvider($provider));
    }

    /** @return array{0: string, 1: bool} */
    private function resolveProvider(AiRequest $request): array
    {
        $provider = strtolower(trim((string) $request->provider));
        $runId = $request->metadata['generation_run_id'] ?? null;

        if (is_numeric($runId)) {
            $runProvider = strtolower(trim((string) GenerationRun::query()->whereKey((int) $runId)->value('provider')));

            if ($runProvider !== '') {
                if ($provider !== '' && $provider !== $runProvider) {
                    throw new AiProviderException('provider_run_mismatch', 'AI request provider does not match the frozen Generation Run provider.', false);
                }

                return [$runProvider, true];
            }
        }

        if ($provider !== '') {
            return [$provider, false];
        }

        throw new AiProviderException(
            'provider_not_resolved',
            'AI request provider was not frozen on the request or Generation Run.',
            false,
        );
    }
}
