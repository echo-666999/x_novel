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

        // 管理后台维护的模型路由是唯一全局配置源；缺失时直接阻断新请求。
        $route = $this->modelRoutes->find($stage);
        if ($route === null) {
            $errorCode = $this->isOutlineStage($stage)
                ? 'outline_route_not_configured'
                : 'model_route_not_configured';
            throw new AiProviderException(
                $errorCode,
                "AI 任务 {$stage->value} 尚未在管理后台配置模型路由。",
                false,
            );
        }

        $routeProvider = strtolower(trim($route->provider));
        $routeModel = trim($route->model);
        $routeReasoningEffort = $route?->reasoning_effort?->value;

        // Embedding 不读取小说级生成策略，仍必须有独立数据库路由。
        if ($stage === AiStage::Embedding) {
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

        $baseProvider = $routeProvider;
        $baseModel = $routeModel;
        $baseReasoningEffort = $routeReasoningEffort;

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
            // 只覆盖模型时沿用基础服务商；显式覆盖服务商时必须验证该服务商凭据。
            $this->assertProviderIsUsable(
                $provider,
                requireCredential: true,
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
        $this->assertProviderIsUsable($baseProvider);
        $this->assertModelIsConfigured($stage, $baseModel);

        return new ResolvedAiSettings(
            stage: $stage,
            provider: $baseProvider,
            model: $baseModel,
            reasoningEffort: $baseReasoningEffort,
            source: 'database',
        );
    }

    public function modelFor(AiStage|string $stage, ?Novel $novel = null): string
    {
        return $this->resolve($stage, $novel)->model;
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
        if ($requireCredential) {
            $this->settingsService->assertProviderAvailable($provider);

            return;
        }

        $this->settingsService->assertProviderAvailable($provider, requireEnabled: true);
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
