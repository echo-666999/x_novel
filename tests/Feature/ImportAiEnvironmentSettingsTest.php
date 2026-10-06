<?php

use App\Models\AIModelPrice;
use App\Models\AIModelRoute;
use App\Models\AIProviderConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.model', 'environment-default-model');
    config()->set('ai.models.writer', 'environment-writer-model');
    config()->set('ai.models.outline_foundation', 'environment-outline-foundation-model');
    config()->set('ai.stage_providers.outline_foundation', 'openai');
    config()->set('ai.reasoning_efforts.outline_foundation', 'low');
    config()->set('ai.embedding.model', 'environment-embedding-model');
    config()->set('ai.providers.openai.api_key', 'environment-import-key');
    config()->set('ai.providers.openai.base_url', 'https://environment.example/v1');
    config()->set('ai.providers.openai.connect_timeout', 12);
    config()->set('ai.providers.openai.timeout', 70);
    config()->set('ai.cost.currency', 'CNY');
    config()->set('ai.cost.input_per_million', 2.5);
    config()->set('ai.cost.cached_input_per_million', 0.5);
    config()->set('ai.cost.output_per_million', 8);
});

test('the environment import command creates a provider connection, model prices, and model routes', function () {
    createVerifiedEnvironmentOutlinePrices();

    $this->artisan('ai:import-environment-settings', ['--force' => true])
        ->expectsOutput('AI 环境配置已写入供应商连接、模型价格和模型路由；API Key 已加密且未输出。')
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
        ->and(AIModelPrice::query()->where('provider', 'openai')->where('model', 'environment-writer-model')->exists())->toBeTrue()
        ->and(AIModelPrice::query()->where('provider', 'openai')->where('model', 'environment-embedding-model')->exists())->toBeTrue()
        ->and(AIModelRoute::query()->where('role', 'writer')->where('model', 'environment-writer-model')->exists())->toBeTrue()
        ->and(AIModelRoute::query()->where('role', 'outline_foundation')->where('provider', 'openai')->where('model', 'environment-outline-foundation-model')->where('reasoning_effort', 'low')->exists())->toBeTrue()
        ->and(AIModelRoute::query()->where('role', 'embedding')->where('model', 'environment-embedding-model')->exists())->toBeTrue();

    $price = AIModelPrice::query()->where('model', 'environment-default-model')->sole();
    expect($price->currency)->toBe('CNY')
        ->and($price->billing_unit)->toBe(1_000_000)
        ->and($price->input_price)->toBe('2.500000000000')
        ->and($price->cached_input_price)->toBe('0.500000000000')
        ->and($price->output_price)->toBe('8.000000000000');
});

test('the environment import command rejects a missing dedicated outline provider without partial writes', function () {
    config()->set('ai.stage_providers.outline_foundation', null);

    $this->artisan('ai:import-environment-settings')
        ->expectsOutput('AI 环境配置未通过校验。请检查字段：routes.outline_foundation.provider')
        ->assertFailed();

    expect(AIProviderConnection::query()->count())->toBe(0)
        ->and(AIModelPrice::query()->count())->toBe(0)
        ->and(AIModelRoute::query()->count())->toBe(0);
});

test('the environment import command does not overwrite existing data by default', function () {
    createExistingProviderConnection();

    $this->artisan('ai:import-environment-settings')
        ->expectsOutput('供应商连接、模型价格或模型路由已存在；为避免覆盖后台配置，本次未导入。确认更新时使用 --force。')
        ->assertFailed();

    expect(AIProviderConnection::query()->sole()->base_url)->toBe('https://database.example/v1');
});

test('the environment import command can explicitly update existing data', function () {
    createExistingProviderConnection();
    createVerifiedEnvironmentOutlinePrices();

    $this->artisan('ai:import-environment-settings', ['--force' => true])
        ->assertSuccessful();

    expect(AIProviderConnection::query()->sole()->base_url)->toBe('https://environment.example/v1')
        ->and(AIProviderConnection::query()->sole()->api_key)->toBe('environment-import-key');
});

test('the environment import command does not infer outline model capacity or capabilities', function () {
    $this->artisan('ai:import-environment-settings')
        ->assertFailed();

    expect(AIProviderConnection::query()->count())->toBe(0)
        ->and(AIModelPrice::query()->count())->toBe(0)
        ->and(AIModelRoute::query()->count())->toBe(0);
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

function createVerifiedEnvironmentOutlinePrices(): void
{
    collect([
        'outline_foundation',
        'outline_structure',
        'outline_arc_beats',
        'outline_beat_detail',
    ])->map(fn (string $stage): array => [
        'provider' => strtolower((string) config("ai.stage_providers.{$stage}")),
        'model' => (string) config("ai.models.{$stage}"),
    ])->unique(fn (array $route): string => $route['provider'].'|'.$route['model'])
        ->each(function (array $route): void {
            AIModelPrice::query()->create([
                ...$route,
                'currency' => 'CNY',
                'billing_unit' => 1_000_000,
                'context_window_tokens' => 1_050_000,
                'max_output_tokens' => 128_000,
                'supports_structured_output' => true,
                'supports_reasoning_effort' => true,
                'input_price' => 0,
                'output_price' => 0,
                'is_enabled' => true,
            ]);
        });
}
