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
            $run = GenerationRun::query()->find((int) $runId, ['id', 'provider', 'context_snapshot']);
            $routeKey = trim((string) ($request->metadata['route_key'] ?? ''));

            if ($run !== null && $routeKey !== '') {
                $route = data_get($run->context_snapshot, "generation_preferences.substage_routes.{$routeKey}");

                if (! is_array($route) || blank($route['provider'] ?? null)) {
                    throw new AiProviderException(
                        'provider_run_route_missing',
                        "AI request route [{$routeKey}] is not frozen on Generation Run [{$run->getKey()}].",
                        false,
                    );
                }

                $routeProvider = strtolower(trim((string) $route['provider']));
                $routeModel = trim((string) ($route['model'] ?? ''));

                if ($provider !== '' && $provider !== $routeProvider) {
                    throw new AiProviderException(
                        'provider_run_mismatch',
                        "AI request provider [{$provider}] does not match frozen provider [{$routeProvider}] for route [{$routeKey}] on Generation Run [{$run->getKey()}].",
                        false,
                    );
                }

                if ($routeModel !== '' && $request->model !== $routeModel) {
                    throw new AiProviderException(
                        'model_run_mismatch',
                        "AI request model [{$request->model}] does not match frozen model [{$routeModel}] for route [{$routeKey}] on Generation Run [{$run->getKey()}].",
                        false,
                    );
                }

                return [$routeProvider, true];
            }

            $runProvider = strtolower(trim((string) $run?->provider));

            if ($runProvider !== '') {
                if ($provider !== '' && $provider !== $runProvider) {
                    throw new AiProviderException(
                        'provider_run_mismatch',
                        "AI request provider [{$provider}] does not match frozen provider [{$runProvider}] on Generation Run [{$run->getKey()}].",
                        false,
                    );
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
