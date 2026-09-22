<?php

use App\Models\AIModelPrice;
use App\Models\AIProviderConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.model', 'environment-default-model');
    config()->set('ai.models.writer', 'environment-writer-model');
    config()->set('ai.providers.openai.api_key', 'environment-import-key');
    config()->set('ai.providers.openai.base_url', 'https://environment.example/v1');
    config()->set('ai.providers.openai.connect_timeout', 12);
    config()->set('ai.providers.openai.timeout', 70);
    config()->set('ai.cost.currency', 'CNY');
    config()->set('ai.cost.input_per_million', 2.5);
    config()->set('ai.cost.cached_input_per_million', 0.5);
    config()->set('ai.cost.output_per_million', 8);
});

test('the environment import command creates a provider connection and model prices', function () {
    $this->artisan('ai:import-environment-settings')
        ->expectsOutput('AI 环境配置已写入供应商连接和模型价格；API Key 已加密且未输出。')
        ->assertSuccessful();

    $connection = AIProviderConnection::query()->where('provider', 'openai')->sole();
    $rawApiKey = DB::table('ai_provider_connections')->where('id', $connection->id)->value('api_key');

    expect($connection->base_url)->toBe('https://environment.example/v1')
        ->and($connection->connect_timeout)->toBe(12)
        ->and($connection->timeout)->toBe(70)
        ->and($connection->api_key)->toBe('environment-import-key')
        ->and($rawApiKey)->toBeString()
        ->not->toBe('environment-import-key')
        ->and(AIModelPrice::query()->where('provider', 'openai')->where('model', 'environment-default-model')->exists())->toBeTrue()
        ->and(AIModelPrice::query()->where('provider', 'openai')->where('model', 'environment-writer-model')->exists())->toBeTrue();

    $price = AIModelPrice::query()->where('model', 'environment-default-model')->sole();
    expect($price->currency)->toBe('CNY')
        ->and($price->billing_unit)->toBe(1_000_000)
        ->and($price->input_price)->toBe('2.500000000000')
        ->and($price->cached_input_price)->toBe('0.500000000000')
        ->and($price->output_price)->toBe('8.000000000000');
});

test('the environment import command does not overwrite existing data by default', function () {
    createExistingProviderConnection();

    $this->artisan('ai:import-environment-settings')
        ->expectsOutput('供应商连接或模型价格已存在；为避免覆盖后台配置，本次未导入。确认更新时使用 --force。')
        ->assertFailed();

    expect(AIProviderConnection::query()->sole()->base_url)->toBe('https://database.example/v1');
});

test('the environment import command can explicitly update existing data', function () {
    createExistingProviderConnection();

    $this->artisan('ai:import-environment-settings', ['--force' => true])
        ->assertSuccessful();

    expect(AIProviderConnection::query()->sole()->base_url)->toBe('https://environment.example/v1')
        ->and(AIProviderConnection::query()->sole()->api_key)->toBe('environment-import-key');
});

function createExistingProviderConnection(): AIProviderConnection
{
    return AIProviderConnection::query()->create([
        'provider' => 'openai',
        'name' => 'Existing',
        'base_url' => 'https://database.example/v1',
        'api_key' => 'existing-database-key',
        'connect_timeout' => 10,
        'timeout' => 60,
        'is_enabled' => true,
    ]);
}
