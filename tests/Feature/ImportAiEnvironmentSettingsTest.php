<?php

use App\AI\AiSettingsService;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.model', 'environment-default-model');
    config()->set('ai.providers.openai.api_key', 'environment-import-key');
    config()->set('ai.providers.openai.base_url', 'https://environment.example/v1');
    config()->set('ai.providers.openai.connect_timeout', 12);
    config()->set('ai.providers.openai.timeout', 70);
    config()->set('ai.cost.currency', 'CNY');
    config()->set('ai.cost.input_per_million', 2.5);
    config()->set('ai.cost.cached_input_per_million', 0.5);
    config()->set('ai.cost.output_per_million', 8);
    config()->set('ai.budget.daily_hard_limit', 12);
    config()->set('ai.budget.novel_total_limit', 30);
    config()->set('ai.budget.chapter_max_cost', 3);
});

test('the environment import command creates database settings with an encrypted api key', function () {
    $this->artisan('ai:import-environment-settings')
        ->expectsOutput('AI 环境配置已写入 system_settings.ai；API Key 已加密且未输出。')
        ->assertSuccessful();

    $stored = SystemSetting::query()->findOrFail(SystemSetting::AI)->value;

    expect(data_get($stored, 'providers.openai.base_url'))->toBe('https://environment.example/v1')
        ->and(data_get($stored, 'providers.openai.connect_timeout'))->toBe(12)
        ->and(data_get($stored, 'providers.openai.timeout'))->toBe(70)
        ->and(data_get($stored, 'providers.openai.credential'))->toBeString()
        ->not->toBe('environment-import-key')
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain('environment-import-key')
        ->and(data_get($stored, 'cost.currency'))->toBe('CNY')
        ->and(data_get($stored, 'cost.input_per_million'))->toBe(2.5)
        ->and(data_get($stored, 'budget.daily_hard_limit'))->toBe(12)
        ->and(app(AiSettingsService::class)->apiKey('openai'))->toBe('environment-import-key');
});

test('the environment import command does not overwrite existing database settings by default', function () {
    $settingsService = app(AiSettingsService::class);
    $settings = $settingsService->editableSettings();
    $settings['providers']['openai']['api_key'] = 'existing-database-key';
    $settings['providers']['openai']['base_url'] = 'https://database.example/v1';
    $settingsService->save($settings, 7);

    $this->artisan('ai:import-environment-settings')
        ->expectsOutput('system_settings.ai 已存在；为避免覆盖后台配置，本次未导入。确认覆盖时使用 --force。')
        ->assertFailed();

    expect(data_get(SystemSetting::query()->findOrFail(SystemSetting::AI)->value, 'providers.openai.base_url'))
        ->toBe('https://database.example/v1')
        ->and($settingsService->apiKey('openai'))->toBe('existing-database-key');
});

test('the environment import command can explicitly replace existing database settings', function () {
    $settingsService = app(AiSettingsService::class);
    $settings = $settingsService->editableSettings();
    $settings['providers']['openai']['api_key'] = 'existing-database-key';
    $settings['providers']['openai']['base_url'] = 'https://database.example/v1';
    $settingsService->save($settings, 7);

    $this->artisan('ai:import-environment-settings', ['--force' => true])
        ->expectsOutput('AI 环境配置已写入 system_settings.ai；API Key 已加密且未输出。')
        ->assertSuccessful();

    expect(data_get(SystemSetting::query()->findOrFail(SystemSetting::AI)->value, 'providers.openai.base_url'))
        ->toBe('https://environment.example/v1')
        ->and($settingsService->apiKey('openai'))->toBe('environment-import-key');
});
