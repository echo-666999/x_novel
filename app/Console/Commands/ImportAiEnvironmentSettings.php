<?php

namespace App\Console\Commands;

use App\AI\AiSettingsService;
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

    public function handle(AiSettingsService $settingsService): int
    {
        try {
            $hasData = AIProviderConnection::query()->exists()
                || AIModelPrice::query()->exists()
                || AIModelRoute::query()->exists();
            if ($hasData && ! $this->option('force')) {
                $this->error('供应商连接、模型价格或模型路由已存在；为避免覆盖后台配置，本次未导入。确认更新时使用 --force。');

                return self::FAILURE;
            }

            DB::transaction(function () use ($settingsService): void {
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
                        'timeout' => (int) config("ai.providers.{$provider}.timeout", 60),
                        'is_enabled' => true,
                    ])->save();
                }

                $currency = strtoupper(trim((string) config('ai.cost.currency', 'USD')));
                foreach ($this->stageRoutes() as $role => $route) {
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

                    AIModelRoute::query()->updateOrCreate(
                        ['role' => $role],
                        compact('provider', 'model'),
                    );
                }
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

    /** @return array<string, array{provider: string, model: string}> */
    private function stageRoutes(): array
    {
        $defaultProvider = strtolower(trim((string) config('ai.provider', 'openai')));
        $defaultModel = trim((string) config('ai.model'));

        return collect(AiStage::cases())->mapWithKeys(function (AiStage $stage) use ($defaultProvider, $defaultModel): array {
            $provider = $stage === AiStage::Embedding
                ? strtolower(trim((string) config('ai.embedding.provider', 'openai')))
                : $defaultProvider;
            $configuredModel = $stage === AiStage::Embedding
                ? config('ai.embedding.model')
                : config("ai.models.{$stage->value}");
            $model = is_string($configuredModel) && trim($configuredModel) !== ''
                ? trim($configuredModel)
                : $defaultModel;

            return [$stage->value => compact('provider', 'model')];
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
