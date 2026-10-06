<?php

namespace App\AI;

use App\AI\Data\ResolvedAiSettings;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiReasoningEffort;
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
        // 所有调用方统一使用 AiStage，避免字符串拼写错误把请求路由到未知阶段。
        $stage = $stage instanceof AiStage ? $stage : AiStage::tryFrom($stage);

        if ($stage === null) {
            throw new InvalidArgumentException('Unsupported AI stage.');
        }

        // 管理后台维护的“模型路由”是全局首选配置；config/ai.php 只承担兼容回退。
        $route = $this->modelRoutes->find($stage);
        $routeProvider = $route === null ? null : strtolower(trim($route->provider));
        $routeModel = $route === null ? null : trim($route->model);
        $routeReasoningEffort = $route?->reasoning_effort?->value;

        // Embedding 不读取小说级生成策略。未维护数据库路由时，直接使用环境配置，
        // 避免把文本生成模型的默认供应商错误应用到向量模型。
        if ($stage === AiStage::Embedding) {
            if ($route === null) {
                return $this->environmentSettings($stage);
            }

            $this->assertProviderIsUsable($routeProvider);
            $this->assertModelIsConfigured($stage, $routeModel);

            return new ResolvedAiSettings(
                stage: $stage,
                provider: $routeProvider,
                model: $routeModel,
                reasoningEffort: $routeReasoningEffort,
                source: 'database',
            );
        }

        // 先建立全局基础配置：模型路由优先，其次是当前数据库 AI 设置及其环境回退。
        $current = $this->settingsService->current();
        $databaseStage = data_get($current['settings'], "stages.{$stage->value}");
        $baseProvider = $routeProvider ?? (is_array($databaseStage)
            ? trim((string) ($databaseStage['provider'] ?? ''))
            : trim((string) data_get($current['settings'], 'default_provider')));
        $baseModel = $routeModel ?? (is_array($databaseStage) ? trim((string) ($databaseStage['model'] ?? '')) : '');
        $baseSource = $route !== null || str_starts_with($current['source'], 'database') ? 'database' : 'environment';
        $baseReasoningEffort = $route !== null
            ? $routeReasoningEffort
            : ($baseSource === 'environment' ? $this->environmentReasoningEffort($stage) : null);

        // 小说级配置只覆盖当前小说，是最终优先级；旧 ai.models.* 结构保留兼容读取。
        $novelStage = $this->novelStageSettings($novel, $stage);
        $providerOverride = is_array($novelStage) ? ($novelStage['provider'] ?? null) : null;
        $modelOverride = is_array($novelStage)
            ? ($novelStage['model'] ?? null)
            : $this->novelModelOverride($novel, $stage);

        $hasProviderOverride = is_string($providerOverride) && trim($providerOverride) !== '';
        $hasModelOverride = is_string($modelOverride) && trim($modelOverride) !== '';

        if ($this->isOutlineStage($stage)
            && is_array($novelStage)
            && (! $hasProviderOverride || ! $hasModelOverride)) {
            throw new AiProviderException(
                'outline_route_not_configured',
                "Outline 任务 {$stage->value} 的小说级路由必须同时配置 Provider 和 Model。",
                false,
            );
        }

        if ($this->isOutlineStage($stage)
            && ! is_array($novelStage)
            && $hasModelOverride) {
            throw new AiProviderException(
                'outline_route_not_configured',
                "Outline 任务 {$stage->value} 的旧小说级 Model Override 缺少同来源 Provider，请重新保存该 Stage 的完整路由。",
                false,
            );
        }

        if ($hasProviderOverride || $hasModelOverride) {
            $provider = $hasProviderOverride ? strtolower(trim($providerOverride)) : $baseProvider;
            $model = $hasModelOverride ? trim($modelOverride) : $baseModel;
            $this->assertOutlineRouteConfigured($stage, $provider, $model);
            // 只覆盖模型时沿用基础供应商；显式覆盖供应商时必须验证该供应商凭据。
            $this->assertProviderIsUsable(
                $provider,
                requireCredential: $baseSource === 'database' || $hasProviderOverride,
            );
            $this->assertModelIsConfigured($stage, $model);

            return new ResolvedAiSettings(
                stage: $stage,
                provider: $provider,
                model: $model,
                reasoningEffort: $this->isOutlineStage($stage)
                    ? $this->novelReasoningEffort($novelStage, $stage)
                    : $baseReasoningEffort,
                source: 'novel',
            );
        }

        $this->assertOutlineRouteConfigured($stage, $baseProvider, $baseModel);
        $this->assertProviderIsUsable($baseProvider, requireCredential: $baseSource === 'database');
        $this->assertModelIsConfigured($stage, $baseModel);

        return new ResolvedAiSettings(
            stage: $stage,
            provider: $baseProvider,
            model: $baseModel,
            reasoningEffort: $baseReasoningEffort,
            source: $baseSource,
        );
    }

    public function modelFor(AiStage|string $stage, ?Novel $novel = null): string
    {
        return $this->resolve($stage, $novel)->model;
    }

    private function environmentSettings(AiStage $stage): ResolvedAiSettings
    {
        // Outline 环境路由必须完整且精确；其他 Stage 保留通用 AI_MODEL 兼容回退。
        $stageModel = $stage === AiStage::Embedding
            ? config('ai.embedding.model')
            : config("ai.models.{$stage->value}");
        $model = is_string($stageModel) && trim($stageModel) !== ''
            ? trim($stageModel)
            : ($this->isOutlineStage($stage) ? '' : trim((string) config('ai.model')));
        $provider = $stage === AiStage::Embedding
            ? (string) config('ai.embedding.provider', 'openai')
            : (string) config("ai.stage_providers.{$stage->value}", config('ai.provider'));

        $this->assertOutlineRouteConfigured($stage, $provider, $model);
        $this->assertModelIsConfigured($stage, $model);

        return new ResolvedAiSettings(
            stage: $stage,
            provider: $provider,
            model: $model,
            reasoningEffort: $this->environmentReasoningEffort($stage),
            source: 'environment',
        );
    }

    /** @return array<string, mixed>|null */
    private function novelStageSettings(?Novel $novel, AiStage $stage): ?array
    {
        $settings = data_get($novel?->settings, "ai.stages.{$stage->value}");
        if (is_array($settings)) {
            return $settings;
        }

        return null;
    }

    private function novelModelOverride(?Novel $novel, AiStage $stage): mixed
    {
        return data_get($novel?->settings, "ai.models.{$stage->value}");
    }

    private function assertProviderIsUsable(string $provider, bool $requireCredential = true): void
    {
        // 在发出请求前完成确定性检查，让配置错误不会被误报为 Provider 网络故障。
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

    private function assertModelIsConfigured(AiStage $stage, string $model): void
    {
        if ($model === '') {
            throw new InvalidArgumentException("AI model is not configured for stage [{$stage->value}].");
        }
    }

    private function assertOutlineRouteConfigured(AiStage $stage, string $provider, string $model): void
    {
        if ($this->isOutlineStage($stage) && (trim($provider) === '' || trim($model) === '')) {
            throw new AiProviderException(
                'outline_route_not_configured',
                "Outline 任务 {$stage->value} 未配置独立的 Provider 和 Model 路由。",
                false,
            );
        }
    }

    /** @param array<string, mixed>|null $settings */
    private function novelReasoningEffort(?array $settings, AiStage $stage): ?string
    {
        if (! is_array($settings) || ! array_key_exists('reasoning_effort', $settings)) {
            return null;
        }

        return $this->normalizedReasoningEffort($settings['reasoning_effort'], $stage);
    }

    private function environmentReasoningEffort(AiStage $stage): ?string
    {
        return $this->normalizedReasoningEffort(config("ai.reasoning_efforts.{$stage->value}"), $stage);
    }

    private function normalizedReasoningEffort(mixed $value, AiStage $stage): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $reasoningEffort = is_string($value) ? trim($value) : '';
        if (AiReasoningEffort::tryFrom($reasoningEffort) === null) {
            throw new AiProviderException(
                'outline_route_not_configured',
                "AI 任务 {$stage->value} 的推理程度必须是 low、medium、high 或留空。",
                false,
            );
        }

        return $reasoningEffort;
    }

    private function isOutlineStage(AiStage $stage): bool
    {
        return in_array($stage, [
            AiStage::OutlineFoundation,
            AiStage::OutlineStructure,
            AiStage::OutlineArcBeats,
            AiStage::OutlineBeatDetail,
        ], true);
    }
}
