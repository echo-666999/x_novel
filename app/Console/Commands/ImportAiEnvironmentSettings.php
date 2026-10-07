<?php

namespace App\Console\Commands;

use App\AI\AiModelRouteService;
use App\AI\AiSettingsService;
use App\Enums\AiReasoningEffort;
use App\Enums\AiStage;
use App\Models\AIModelPrice;
use App\Models\AIModelRoute;
use App\Models\AIProviderConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportAiEnvironmentSettings extends Command
{
    protected $signature = 'ai:import-environment-settings
        {--force : Update existing provider connections, model prices, and model routes with current environment values}';

    protected $description = 'Import current AI environment settings into provider connections, model prices, and model routes';

    public function handle(AiSettingsService $settingsService, AiModelRouteService $routeService): int
    {
        try {
            $hasData = AIProviderConnection::query()->exists()
                || AIModelPrice::query()->exists()
                || AIModelRoute::query()->exists();
            if ($hasData && ! $this->option('force')) {
                $this->error('供应商连接、模型价格或模型路由已存在；为避免覆盖后台配置，本次未导入。确认更新时使用 --force。');

                return self::FAILURE;
            }

            DB::transaction(function () use ($settingsService, $routeService): void {
                foreach ($settingsService->registeredProviders() as $provider) {
                    $apiKey = config("ai.providers.{$provider}.api_key");
                    if (! is_string($apiKey) || trim($apiKey) === '') {
                        continue;
                    }

                    $connection = AIProviderConnection::query()->firstOrNew(['provider' => $provider]);
                    $connection->fill([
                        'name' => $this->providerLabel($provider),
                        'base_url' => rtrim((string) config("ai.providers.{$provider}.base_url"), '/'),
                        'api_key' => trim($apiKey),
                        'connect_timeout' => (int) config("ai.providers.{$provider}.connect_timeout", 10),
                        'timeout' => (int) config("ai.providers.{$provider}.timeout", 150),
                        'is_enabled' => true,
                    ])->save();
                }

                $currency = strtoupper(trim((string) config('ai.cost.currency', 'USD')));
                $routes = $this->stageRoutes();
                $defaultRoute = [
                    'provider' => strtolower(trim((string) config('ai.provider', 'openai'))),
                    'model' => trim((string) config('ai.model')),
                ];

                // AI_MODEL 也是旧环境配置的独立价格对象；即使每个角色都有覆盖值，导入时也不应丢失它。
                collect([...array_values($routes), $defaultRoute])
                    ->filter(fn (array $route): bool => $route['provider'] !== '' && $route['model'] !== '')
                    ->unique(fn (array $route): string => $route['provider'].'|'.$route['model'])
                    ->each(function (array $route) use ($currency): void {
                        ['provider' => $provider, 'model' => $model] = $route;

                        AIModelPrice::query()->updateOrCreate(
                            compact('provider', 'model', 'currency'),
                            [
                                'billing_unit' => 1_000_000,
                                'input_price' => (float) config('ai.cost.input_per_million', 0),
                                'cached_input_price' => (float) config('ai.cost.cached_input_per_million', 0),
                                'output_price' => (float) config('ai.cost.output_per_million', 0),
                                'is_enabled' => true,
                            ],
                        );
                    });

                $routeSelections = collect($routes)->mapWithKeys(function (array $route, string $role) use ($currency): array {
                    $price = AIModelPrice::query()
                        ->where('provider', $route['provider'])
                        ->where('model', $route['model'])
                        ->where('currency', $currency)
                        ->where('is_enabled', true)
                        ->first();

                    return [$role => [
                        'model_price_id' => $price?->getKey(),
                        'reasoning_effort' => $route['reasoning_effort'],
                    ]];
                })->all();

                // 环境导入与后台共用路由保存边界，避免导入出缺少容量或能力的文本生成路由。
                $routeService->save($routeSelections, null);
            });
        } catch (ValidationException $exception) {
            $fields = implode(', ', array_keys($exception->errors()));
            $this->error('AI 环境配置未通过校验。请检查字段：'.$fields);

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('AI 环境配置写入失败。请检查数据库连接后重试。');

            return self::FAILURE;
        }

        $this->info('AI 环境配置已写入供应商连接、模型价格和模型路由；API Key 已加密且未输出。');

        return self::SUCCESS;
    }

    /** @return array<string, array{provider: string, model: string, reasoning_effort: string|null}> */
    private function stageRoutes(): array
    {
        $defaultProvider = strtolower(trim((string) config('ai.provider', 'openai')));
        $defaultModel = trim((string) config('ai.model'));

        return collect(AiStage::cases())->mapWithKeys(function (AiStage $stage) use ($defaultProvider, $defaultModel): array {
            $provider = $stage === AiStage::Embedding
                ? strtolower(trim((string) config('ai.embedding.provider', 'openai')))
                : strtolower(trim((string) config("ai.stage_providers.{$stage->value}", $defaultProvider)));
            $configuredModel = $stage === AiStage::Embedding
                ? config('ai.embedding.model')
                : config("ai.models.{$stage->value}");
            $isOutlineStage = in_array($stage, [
                AiStage::OutlineFoundation,
                AiStage::OutlineStructure,
                AiStage::OutlineArcBeats,
                AiStage::OutlineBeatDetail,
            ], true);
            $model = is_string($configuredModel) && trim($configuredModel) !== ''
                ? trim($configuredModel)
                : ($isOutlineStage ? '' : $defaultModel);
            if ($provider === '') {
                throw ValidationException::withMessages([
                    "routes.{$stage->value}.provider" => $isOutlineStage
                        ? "Outline 任务 {$stage->value} 缺少独立环境 Provider 配置。"
                        : "AI 任务 {$stage->value} 缺少环境 Provider 配置。",
                ]);
            }
            if ($model === '') {
                throw ValidationException::withMessages([
                    "routes.{$stage->value}.model" => $isOutlineStage
                        ? "Outline 任务 {$stage->value} 缺少独立环境模型配置。"
                        : "AI 任务 {$stage->value} 缺少环境模型配置。",
                ]);
            }
            $reasoningEffort = $stage === AiStage::Embedding
                ? null
                : config("ai.reasoning_efforts.{$stage->value}");
            $reasoningEffort = is_string($reasoningEffort) && trim($reasoningEffort) !== ''
                ? trim($reasoningEffort)
                : null;
            if ($reasoningEffort !== null && AiReasoningEffort::tryFrom($reasoningEffort) === null) {
                throw ValidationException::withMessages([
                    "routes.{$stage->value}.reasoning_effort" => '推理程度必须是 low、medium、high 或留空。',
                ]);
            }

            return [$stage->value => [
                'provider' => $provider,
                'model' => $model,
                'reasoning_effort' => $reasoningEffort,
            ]];
        })->all();
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'OpenAI',
            'deepseek' => 'DeepSeek',
            default => ucfirst($provider),
        };
    }
}
