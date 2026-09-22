<?php

namespace App\AI;

use App\AI\Data\ResolvedAiSettings;
use App\Enums\AiStage;
use App\Models\Novel;
use InvalidArgumentException;

class AiSettingsResolver
{
    public function __construct(
        private readonly AiSettingsService $settingsService,
        private readonly AiModelRouteService $modelRoutes,
    ) {}

    public function resolve(AiStage|string $stage, ?Novel $novel = null): ResolvedAiSettings
    {
        $stage = $stage instanceof AiStage ? $stage : AiStage::tryFrom($stage);

        if ($stage === null) {
            throw new InvalidArgumentException('Unsupported AI stage.');
        }

        $route = $this->modelRoutes->find($stage);
        if ($stage === AiStage::Embedding) {
            if ($route === null) {
                return $this->environmentSettings($stage);
            }

            $this->assertProviderIsUsable($route->provider);

            return new ResolvedAiSettings(
                stage: $stage,
                provider: $route->provider,
                model: $route->model,
                source: 'database',
            );
        }

        $current = $this->settingsService->current();
        $databaseStage = data_get($current['settings'], "stages.{$stage->value}");
        $baseProvider = $route?->provider ?? (is_array($databaseStage)
            ? trim((string) ($databaseStage['provider'] ?? ''))
            : trim((string) data_get($current['settings'], 'default_provider')));
        $baseModel = $route?->model ?? (is_array($databaseStage) ? trim((string) ($databaseStage['model'] ?? '')) : '');
        $baseSource = $route !== null || str_starts_with($current['source'], 'database') ? 'database' : 'environment';

        $novelStage = data_get($novel?->settings, "ai.stages.{$stage->value}");
        $providerOverride = is_array($novelStage) ? ($novelStage['provider'] ?? null) : null;
        $modelOverride = is_array($novelStage)
            ? ($novelStage['model'] ?? null)
            : data_get($novel?->settings, "ai.models.{$stage->value}");

        $hasProviderOverride = is_string($providerOverride) && trim($providerOverride) !== '';
        $hasModelOverride = is_string($modelOverride) && trim($modelOverride) !== '';

        if ($hasProviderOverride || $hasModelOverride) {
            $provider = $hasProviderOverride ? strtolower(trim($providerOverride)) : $baseProvider;
            $model = $hasModelOverride ? trim($modelOverride) : $baseModel;
            $this->assertProviderIsUsable(
                $provider,
                requireCredential: $baseSource === 'database' || $hasProviderOverride,
            );

            return new ResolvedAiSettings(
                stage: $stage,
                provider: $provider,
                model: $model,
                source: 'novel',
            );
        }

        $this->assertProviderIsUsable($baseProvider, requireCredential: $baseSource === 'database');

        return new ResolvedAiSettings(
            stage: $stage,
            provider: $baseProvider,
            model: $baseModel,
            source: $baseSource,
        );
    }

    public function modelFor(AiStage|string $stage, ?Novel $novel = null): string
    {
        return $this->resolve($stage, $novel)->model;
    }

    private function environmentSettings(AiStage $stage): ResolvedAiSettings
    {
        $stageModel = $stage === AiStage::Embedding
            ? config('ai.embedding.model')
            : config("ai.models.{$stage->value}");
        $model = is_string($stageModel) && trim($stageModel) !== ''
            ? trim($stageModel)
            : trim((string) config('ai.model'));

        if ($model === '') {
            throw new InvalidArgumentException("AI model is not configured for stage [{$stage->value}].");
        }

        return new ResolvedAiSettings(
            stage: $stage,
            provider: (string) ($stage === AiStage::Embedding ? config('ai.embedding.provider', 'openai') : config('ai.provider')),
            model: $model,
            source: 'environment',
        );
    }

    private function assertProviderIsUsable(string $provider, bool $requireCredential = true): void
    {
        if (! in_array($provider, $this->settingsService->registeredProviders(), true)) {
            throw new InvalidArgumentException("AI provider [{$provider}] is not registered.");
        }

        if (data_get($this->settingsService->providerSettings($provider), 'enabled') !== true) {
            throw new InvalidArgumentException("AI provider [{$provider}] is disabled.");
        }

        if ($requireCredential && ! $this->settingsService->isCredentialConfigured($provider)) {
            throw new InvalidArgumentException("AI provider [{$provider}] has no configured API key.");
        }
    }
}
