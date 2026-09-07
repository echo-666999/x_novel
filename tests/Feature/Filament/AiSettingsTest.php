<?php

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Filament\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('ai.provider', 'openai');
    config()->set('ai.model', 'test-model');
    config()->set('ai.models.planner', 'planner-model');
    config()->set('ai.models.writer', 'writer-model');
});

test('ai settings shows provider model status and connection action without exposing credentials', function () {
    config()->set('ai.providers.openai.api_key', null);

    Livewire::test(Settings::class)
        ->assertOk()
        ->assertSee('Provider')
        ->assertSee('openai')
        ->assertSee('Model')
        ->assertSee('test-model')
        ->assertSee('Connection Status')
        ->assertSee('未配置')
        ->assertSee('Stage Models')
        ->assertSee('Planner')
        ->assertSee('planner-model')
        ->assertSee('Writer')
        ->assertSee('writer-model')
        ->assertSee('Global Default')
        ->assertSee('Prompt Versions')
        ->assertSee('chapter-planner-v1')
        ->assertSee('scene-writer-v1')
        ->assertSee('assembler-v1')
        ->assertSee('event-extractor-v1')
        ->assertSee('reviewer-v1')
        ->assertSee('rewrite-v1')
        ->assertSee('summary-v1')
        ->assertSee('config/prompts.php')
        ->assertSee('Test Connection')
        ->assertDontSee('AI_API_KEY');
});

test('ai settings can run a successful connection test', function () {
    $fake = (new FakeAiProvider)->enqueue(new AiResponse(
        content: 'OK',
        structuredData: null,
        inputTokens: 2,
        outputTokens: 1,
        cachedTokens: 0,
        latencyMs: 37,
        providerRequestId: 'connection-test',
        model: 'resolved-test-model',
    ));
    app()->instance(AiProvider::class, $fake);

    Livewire::test(Settings::class)
        ->assertActionExists('testAiConnection')
        ->callAction('testAiConnection')
        ->assertSet('aiConnectionResult.success', true)
        ->assertSee('Success')
        ->assertSee('resolved-test-model')
        ->assertSee('37 ms');

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->model)->toBe('planner-model')
        ->and($fake->requests()[0]->promptVersion)->toBe('chapter-planner-v1')
        ->and($fake->requests()[0]->metadata)->toBe(['purpose' => 'connection_test']);
});

test('ai settings displays mapped connection failures', function () {
    $fake = (new FakeAiProvider)->enqueue(new AiProviderException(
        errorCode: 'provider_rate_limited',
        message: '请求过于频繁。',
        retryable: true,
        statusCode: 429,
    ));
    app()->instance(AiProvider::class, $fake);

    Livewire::test(Settings::class)
        ->callAction('testAiConnection')
        ->assertSet('aiConnectionResult.success', false)
        ->assertSee('Failed')
        ->assertSee('provider_rate_limited')
        ->assertSee('请求过于频繁。');
});
