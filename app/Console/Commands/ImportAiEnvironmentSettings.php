<?php

namespace App\Console\Commands;

use App\AI\AiSettingsService;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportAiEnvironmentSettings extends Command
{
    protected $signature = 'ai:import-environment-settings
        {--force : Replace an existing system_settings.ai record with current environment values}';

    protected $description = 'Import current AI environment settings into system_settings.ai with encrypted API keys';

    public function handle(AiSettingsService $settingsService): int
    {
        try {
            if (SystemSetting::query()->whereKey(SystemSetting::AI)->exists() && ! $this->option('force')) {
                $this->error('system_settings.ai 已存在；为避免覆盖后台配置，本次未导入。确认覆盖时使用 --force。');

                return self::FAILURE;
            }

            $settings = $settingsService->defaults();
            foreach ($settingsService->registeredProviders() as $provider) {
                $apiKey = config("ai.providers.{$provider}.api_key");
                if (is_string($apiKey) && trim($apiKey) !== '') {
                    data_set($settings, "providers.{$provider}.api_key", trim($apiKey));
                }
            }

            $settingsService->save($settings, null);
        } catch (ValidationException $exception) {
            $fields = implode(', ', array_keys($exception->errors()));
            $this->error('AI 环境配置未通过校验。请检查字段：'.$fields);

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('AI 环境配置写入失败。请检查数据库连接后重试。');

            return self::FAILURE;
        }

        $this->info('AI 环境配置已写入 system_settings.ai；API Key 已加密且未输出。');

        return self::SUCCESS;
    }
}
