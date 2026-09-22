<?php

use App\AI\AiSettingsResolver;
use App\AI\AiSettingsService;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\Enums\AiStage;
use App\Filament\Pages\Settings;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('ai.provider', 'openai');
    config()->set('ai.model', 'test-model');
    config()->set('ai.models.planner', 'planner-model');
    config()->set('ai.models.writer', 'writer-model');
});

test('ai settings shows editable provider stage model timeout and credential status without exposing credentials', function () {
    config()->set('ai.providers.openai.api_key', null);

    Livewire::test(Settings::class)
        ->assertOk()
        ->assertSet('data.default_provider', 'openai')
        ->assertSet('data.stages.planner.model', 'planner-model')
        ->assertSet('data.stages.writer.model', 'writer-model')
        ->assertSee('默认文本生成 Provider')
        ->assertSee('OpenAI API Key')
        ->assertSee('未配置')
        ->assertSee('章节规划')
        ->assertSee('场景写作')
        ->assertSee('连接超时')
        ->assertSee('请求超时')
        ->assertSee('OpenAI Base URL')
        ->assertSee('成本货币代码')
        ->assertSee('每日成本硬限制')
        ->assertSee('保存 AI 配置')
        ->assertSee('Prompt Versions')
        ->assertSee('chapter-planner-v9')
        ->assertSee('scene-writer-v13')
        ->assertSee('assembler-v11')
        ->assertSee('event-extractor-v6')
        ->assertSee('reviewer-v13')
        ->assertSee('rewrite-v12')
        ->assertSee('summary-v1')
        ->assertSee('config/prompts.php')
        ->assertSee('Test Connection')
        ->assertDontSee('AI_API_KEY')
        ->assertDontSee('secret-page-key');
});

test('ai settings saves an api key without returning or logging its plaintext', function () {
    config()->set('ai.providers.openai.api_key', null);
    $records = [];
    Log::shouldReceive('info')->once()->andReturnUsing(function (string $message, array $context) use (&$records): void {
        $records[] = compact('message', 'context');
    });

    Livewire::test(Settings::class)
        ->set('data.providers.openai.api_key', 'page-secret-key')
        ->call('saveAiSettings')
        ->assertHasNoErrors()
        ->assertSet('data.providers.openai.api_key', null)
        ->assertDontSee('page-secret-key')
        ->assertNotified('AI 配置已保存');

    $stored = SystemSetting::query()->findOrFail(SystemSetting::AI)->value;

    expect(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain('page-secret-key')
        ->and(json_encode($records, JSON_THROW_ON_ERROR))->not->toContain('page-secret-key')
        ->and(app(AiSettingsService::class)->apiKey('openai'))->toBe('page-secret-key');
});

test('ai settings saves normalized values and reloads the database source without logging the key', function () {
    config()->set('ai.providers.openai.api_key', 'secret-page-key');
    $records = [];
    Log::shouldReceive('info')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$records): void {
            $records[] = compact('message', 'context');
        });

    Livewire::test(Settings::class)
        ->set('data.providers.openai.connect_timeout', 15)
        ->set('data.providers.openai.timeout', 75)
        ->set('data.stages.writer.model', '  database-writer-model  ')
        ->call('saveAiSettings')
        ->assertHasNoErrors()
        ->assertSet('data.stages.writer.model', 'database-writer-model')
        ->assertSee('当前生效来源：system_settings.ai')
        ->assertDontSee('secret-page-key')
        ->assertNotified('AI 配置已保存');

    $stored = SystemSetting::query()->findOrFail(SystemSetting::AI)->value;
    $resolved = app(AiSettingsResolver::class)->resolve(AiStage::Writer);

    expect(data_get($stored, 'providers.openai.connect_timeout'))->toBe(15)
        ->and(data_get($stored, 'providers.openai.timeout'))->toBe(75)
        ->and($resolved->model)->toBe('database-writer-model')
        ->and($resolved->source)->toBe('database')
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain('secret-page-key')
        ->and(json_encode($records, JSON_THROW_ON_ERROR))->not->toContain('secret-page-key')
        ->and(json_encode($records, JSON_THROW_ON_ERROR))->not->toContain('api_key');
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
        ->and($fake->requests()[0]->provider)->toBe('openai')
        ->and($fake->requests()[0]->promptVersion)->toBe('chapter-planner-v9')
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
