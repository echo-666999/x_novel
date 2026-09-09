<?php

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\UsageRecorder;
use App\Filament\Pages\AiDebugTest;
use App\Filament\Pages\Settings;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('ai.provider', 'openai');
    config()->set('ai.models.planner', 'planner-test-model');
    config()->set('ai.models.writer', 'writer-test-model');
    config()->set('ai.cost.currency', 'USD');
    config()->set('ai.cost.input_per_million', 1.0);
    config()->set('ai.cost.cached_input_per_million', 0.5);
    config()->set('ai.cost.output_per_million', 2.0);
});

test('settings links to the ai debug page without adding top level navigation', function () {
    Livewire::test(Settings::class)
        ->assertSee('AI Debug')
        ->assertSeeHtml(AiDebugTest::getUrl());

    expect(AiDebugTest::shouldRegisterNavigation())->toBeFalse();
});

test('ai debug page shows the required fields and resolved defaults', function () {
    Livewire::test(AiDebugTest::class)
        ->assertOk()
        ->assertSet('data.task_type', 'planner')
        ->assertSet('data.model', 'planner-test-model')
        ->assertSet('data.prompt_version', 'chapter-planner-v3')
        ->assertSee('Task Type')
        ->assertSee('Model')
        ->assertSee('Prompt Version')
        ->assertSee('Input')
        ->assertSee('Run Test')
        ->assertSee('Response')
        ->assertSee('Input Tokens')
        ->assertSee('Output Tokens')
        ->assertSee('Cost')
        ->assertSee('Latency');
});

test('changing task type refreshes model and prompt version', function () {
    Livewire::test(AiDebugTest::class)
        ->set('data.task_type', 'writer')
        ->assertSet('data.model', 'writer-test-model')
        ->assertSet('data.prompt_version', 'scene-writer-v6');
});

test('ai debug test calls the tracked provider and displays metrics', function () {
    $fake = (new FakeAiProvider)->enqueue(new AiResponse(
        content: 'Debug response',
        structuredData: null,
        inputTokens: 100,
        outputTokens: 50,
        cachedTokens: 20,
        latencyMs: 321,
        providerRequestId: 'debug-request-1',
        model: 'planner-test-model',
    ));
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));

    Livewire::test(AiDebugTest::class)
        ->set('data.input', 'Test the provider.')
        ->call('runTest')
        ->assertSet('result.response', 'Debug response')
        ->assertSet('result.input_tokens', 100)
        ->assertSet('result.output_tokens', 50)
        ->assertSet('result.latency_ms', 321)
        ->assertSee('Debug response')
        ->assertSee('321 ms');

    expect($fake->requests())->toHaveCount(1)
        ->and($fake->requests()[0]->model)->toBe('planner-test-model')
        ->and($fake->requests()[0]->promptVersion)->toBe('chapter-planner-v3')
        ->and($fake->requests()[0]->metadata)->toBe([
            'purpose' => 'ai_debug_test',
            'task_type' => 'planner',
        ])
        ->and(UsageRecord::query()->where('request_id', 'debug-request-1')->exists())->toBeTrue();
});

test('ai debug test displays provider failures', function () {
    app()->instance(AiProvider::class, (new FakeAiProvider)->enqueue(new AiProviderException(
        errorCode: 'provider_rate_limited',
        message: '请求过于频繁。',
        retryable: true,
        statusCode: 429,
    )));

    Livewire::test(AiDebugTest::class)
        ->call('runTest')
        ->assertSet('result', null)
        ->assertSet('error.code', 'provider_rate_limited')
        ->assertSet('error.retryable', true)
        ->assertSee('provider_rate_limited')
        ->assertSee('请求过于频繁。')
        ->assertSee('Yes');
});
