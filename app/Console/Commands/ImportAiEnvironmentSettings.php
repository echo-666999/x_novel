<?php

namespace App\Console\Commands;

use App\AI\AiSettingsService;
use App\Models\AIModelPrice;
use App\Models\AIProviderConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportAiEnvironmentSettings extends Command
{
    protected $signature = 'ai:import-environment-settings
        {--force : Update existing provider connections and model prices with current environment values}';

    protected $description = 'Import current AI environment settings into provider connections and model prices';

    public function handle(AiSettingsService $settingsService): int
    {
        try {
            $hasData = AIProviderConnection::query()->exists() || AIModelPrice::query()->exists();
            if ($hasData && ! $this->option('force')) {
                $this->error('供应商连接或模型价格已存在；为避免覆盖后台配置，本次未导入。确认更新时使用 --force。');

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

                $provider = strtolower(trim((string) config('ai.provider', 'openai')));
                $currency = strtoupper(trim((string) config('ai.cost.currency', 'USD')));
                foreach ($this->textModels() as $model) {
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

        $this->info('AI 环境配置已写入供应商连接和模型价格；API Key 已加密且未输出。');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function textModels(): array
    {
        return collect([
            config('ai.model'),
            ...collect((array) config('ai.models', []))->except('embedding')->values()->all(),
        ])->filter(fn (mixed $model): bool => is_string($model) && trim($model) !== '')
            ->map(fn (string $model): string => trim($model))
            ->unique()
            ->values()
            ->all();
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
