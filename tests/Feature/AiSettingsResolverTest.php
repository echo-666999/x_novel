<?php

use App\AI\AiSettingsResolver;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Models\AIModelRoute;
use App\Models\AIProviderConnection;
use App\Models\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException as TestInvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    createAiProviderConnectionForTest();
});

test('every stage requires an explicit database model route', function () {
    expect(fn () => app(AiSettingsResolver::class)->resolve(AiStage::Writer))
        ->toThrow(AiProviderException::class, '尚未在管理后台配置模型路由');
});

test('database route resolves provider model and reasoning as one tuple', function () {
    createAiModelRouteForTest(AiStage::Writer, ' database-writer ', 'openai', 'high');

    expect(app(AiSettingsResolver::class)->resolve(AiStage::Writer))
        ->provider->toBe('openai')
        ->model->toBe('database-writer')
        ->reasoningEffort->toBe('high')
        ->source->toBe('database');
});

test('embedding requires its own database route and ignores novel overrides', function () {
    createAiModelRouteForTest(AiStage::Embedding, 'embedding-db');
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['stages' => [
            'embedding' => ['provider' => 'deepseek', 'model' => 'ignored-model'],
        ]]],
    ]);

    expect(app(AiSettingsResolver::class)->resolve(AiStage::Embedding, $novel))
        ->provider->toBe('openai')
        ->model->toBe('embedding-db')
        ->source->toBe('database');
});

test('structured novel override takes precedence over the database route', function () {
    createAiProviderConnectionForTest('deepseek');
    createAiModelRouteForTest(AiStage::Writer, 'writer-global');
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['stages' => [
            'writer' => ['provider' => 'deepseek', 'model' => 'writer-novel'],
        ]]],
    ]);

    expect(app(AiSettingsResolver::class)->resolve(AiStage::Writer, $novel))
        ->provider->toBe('deepseek')
        ->model->toBe('writer-novel')
        ->source->toBe('novel');
});

test('outline novel override must contain provider and model together', function () {
    createAiModelRouteForTest(AiStage::OutlineFoundation, 'foundation-global');
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['stages' => [
            'outline_foundation' => ['provider' => 'openai', 'model' => ''],
        ]]],
    ]);

    expect(fn () => app(AiSettingsResolver::class)->resolve(AiStage::OutlineFoundation, $novel))
        ->toThrow(AiProviderException::class, '必须同时配置 Provider 和 Model');
});

test('legacy outline model only override remains blocked', function () {
    createAiModelRouteForTest(AiStage::OutlineFoundation, 'foundation-global');
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['models' => ['outline_foundation' => 'legacy-model']]],
    ]);

    expect(fn () => app(AiSettingsResolver::class)->resolve(AiStage::OutlineFoundation, $novel))
        ->toThrow(AiProviderException::class, '缺少同来源 Provider');
});

test('route provider must have an enabled database connection and credential', function (string $case, string $message) {
    AIProviderConnection::query()->delete();
    createAiModelRouteForTest(AiStage::Writer, 'writer-model');

    if ($case === 'disabled') {
        createAiProviderConnectionForTest(enabled: false);
    } elseif ($case === 'missing-key') {
        AIProviderConnection::query()->create([
            'provider' => 'openai',
            'name' => 'OpenAI',
            'base_url' => 'https://openai.example/v1',
            'api_key' => null,
            'connect_timeout' => 10,
            'timeout' => 60,
            'is_enabled' => true,
        ]);
    }

    expect(fn () => app(AiSettingsResolver::class)->resolve(AiStage::Writer))
        ->toThrow(AiProviderException::class, $message);
})->with([
    'missing connection' => ['missing', 'no database connection'],
    'disabled connection' => ['disabled', 'disabled'],
    'missing credential' => ['missing-key', 'no configured API key'],
]);

test('unknown route provider is rejected before a request', function () {
    AIModelRoute::query()->create([
        'role' => AiStage::Writer,
        'provider' => 'unknown',
        'model' => 'unknown-model',
    ]);

    expect(fn () => app(AiSettingsResolver::class)->resolve(AiStage::Writer))
        ->toThrow(AiProviderException::class, 'is not registered');
});

test('unknown stage names are rejected', function () {
    app(AiSettingsResolver::class)->resolve('unknown');
})->throws(TestInvalidArgumentException::class, 'Unsupported AI stage.');
