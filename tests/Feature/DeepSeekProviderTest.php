<?php

use App\AI\AiSettingsResolver;
use App\AI\AiSettingsService;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\DeepSeekProvider;
use App\Enums\AiStage;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.providers.deepseek.enabled', true);
    config()->set('ai.providers.deepseek.api_key', 'deepseek-test-key');
    config()->set('ai.providers.deepseek.base_url', 'https://deepseek.example');
});

test('deepseek structured requests use json output and map usage', function () {
    Http::fake(['deepseek.example/*' => Http::response([
        'id' => 'deepseek-request-1',
        'model' => 'deepseek-chat-resolved',
        'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"answer":"OK"}']]],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4, 'prompt_cache_hit_tokens' => 3],
    ])]);

    $response = app(DeepSeekProvider::class)->generate(new AiRequest(
        model: 'deepseek-chat',
        provider: 'deepseek',
        prompt: 'Return the answer.',
        maxTokens: 128,
        responseSchema: [
            'type' => 'object',
            'required' => ['answer'],
            'properties' => ['answer' => ['type' => 'string']],
            'additionalProperties' => false,
        ],
    ));

    expect($response->structuredData)->toBe(['answer' => 'OK'])
        ->and($response->cachedTokens)->toBe(3)
        ->and($response->providerRequestId)->toBe('deepseek-request-1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://deepseek.example/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer deepseek-test-key')
        && $request['model'] === 'deepseek-chat'
        && $request['max_tokens'] === 128
        && ! isset($request['max_completion_tokens'])
        && $request['response_format'] === ['type' => 'json_object']
        && str_contains($request['messages'][0]['content'], 'valid JSON object'));
});

test('deepseek structured output failures are non retryable', function (string $content, string $code) {
    Http::fake(['deepseek.example/*' => Http::response([
        'id' => 'deepseek-invalid',
        'model' => 'deepseek-chat',
        'choices' => [['message' => ['content' => $content]]],
        'usage' => [],
    ])]);

    try {
        app(DeepSeekProvider::class)->generate(new AiRequest(
            model: 'deepseek-chat',
            provider: 'deepseek',
            responseSchema: [
                'type' => 'object',
                'required' => ['answer'],
                'properties' => ['answer' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        ));
        $this->fail('Expected AiProviderException was not thrown.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->retryable)->toBeFalse();
    }
})->with([
    'empty' => ['', 'provider_empty_json_response'],
    'invalid json' => ['{invalid', 'provider_invalid_json_response'],
    'schema mismatch' => ['{"answer":12}', 'provider_schema_validation_failed'],
]);

test('router records the frozen provider on usage and does not change an existing run', function () {
    $settings = app(AiSettingsService::class)->defaults();
    $settings['providers']['deepseek']['enabled'] = true;
    $settings['default_provider'] = 'deepseek';
    foreach ($settings['stages'] as &$stage) {
        $stage['provider'] = 'deepseek';
    }
    unset($stage);
    app(AiSettingsService::class)->save($settings, null);

    $novel = Novel::factory()->create();
    $run = GenerationRun::factory()->for($novel)->create([
        'provider' => 'deepseek',
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Running,
    ]);

    config()->set('ai.providers.openai.api_key', 'openai-test-key');
    $switched = $settings;
    $switched['default_provider'] = 'openai';
    $switched['providers']['deepseek']['enabled'] = false;
    foreach ($switched['stages'] as &$stage) {
        $stage['provider'] = 'openai';
    }
    unset($stage);
    app(AiSettingsService::class)->save($switched, null);

    Http::fake(['deepseek.example/*' => Http::response([
        'id' => 'deepseek-tracked',
        'model' => 'deepseek-chat',
        'choices' => [['message' => ['content' => 'OK']]],
        'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1],
    ])]);

    app(AiProvider::class)->generate(new AiRequest(
        model: 'deepseek-chat',
        metadata: ['generation_run_id' => $run->getKey(), 'novel_id' => $novel->getKey()],
    ));

    expect($run->fresh()->provider)->toBe('deepseek')
        ->and(UsageRecord::query()->sole()->provider)->toBe('deepseek');
});

test('planner and writer resolve different providers while existing runs remain frozen', function () {
    config()->set('ai.providers.openai.api_key', 'openai-test-key');
    $novel = Novel::factory()->create();
    $existing = GenerationRun::factory()->for($novel)->create(['provider' => 'openai']);
    $settings = app(AiSettingsService::class)->defaults();
    $settings['providers']['deepseek']['enabled'] = true;
    $settings['stages']['planner'] = ['provider' => 'openai', 'model' => 'planner-openai'];
    $settings['stages']['writer'] = ['provider' => 'deepseek', 'model' => 'writer-deepseek'];
    app(AiSettingsService::class)->save($settings, null);

    $resolver = app(AiSettingsResolver::class);

    expect($resolver->resolve(AiStage::Planner, $novel))
        ->provider->toBe('openai')
        ->model->toBe('planner-openai')
        ->and($resolver->resolve(AiStage::Writer, $novel))
        ->provider->toBe('deepseek')
        ->model->toBe('writer-deepseek')
        ->and($existing->fresh()->provider)->toBe('openai');
});

test('deepseek prompt logging follows the shared environment switch', function () {
    config()->set('ai.logging.prompts', false);
    Http::fake(['deepseek.example/*' => Http::response([
        'id' => 'deepseek-log-test',
        'model' => 'deepseek-chat',
        'choices' => [['message' => ['content' => 'OK']]],
        'usage' => [],
    ])]);
    $records = [];
    Log::shouldReceive('debug')->twice()->andReturnUsing(function (string $message, array $context) use (&$records): void {
        $records[$message] = $context;
    });

    app(DeepSeekProvider::class)->generate(new AiRequest(
        model: 'deepseek-chat',
        provider: 'deepseek',
        systemPrompt: 'private-system-prompt',
        prompt: 'private-user-prompt',
        metadata: ['private_context' => 'private-metadata'],
    ));

    $serialized = json_encode($records, JSON_THROW_ON_ERROR);
    expect($records['AI Provider 请求。'])->not->toHaveKeys(['payload', 'metadata'])
        ->and($records['AI Provider 请求。']['provider'])->toBe('deepseek')
        ->and($serialized)->not->toContain('private-system-prompt')
        ->and($serialized)->not->toContain('private-user-prompt')
        ->and($serialized)->not->toContain('private-metadata')
        ->and($serialized)->not->toContain('deepseek-test-key');
});

test('router rejects disabled missing and unknown providers before http', function (string $provider, ?string $key, string $code) {
    config()->set('ai.providers.deepseek.api_key', $key);
    if ($code === 'provider_disabled') {
        config()->set('ai.providers.deepseek.enabled', false);
    }
    Http::fake();

    try {
        app(AiProvider::class)->generate(new AiRequest(model: 'model', provider: $provider));
        $this->fail('Expected AiProviderException was not thrown.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    Http::assertNothingSent();
})->with([
    'disabled' => ['deepseek', 'key', 'provider_disabled'],
    'missing key' => ['deepseek', null, 'provider_not_configured'],
    'unknown' => ['unknown', 'key', 'provider_unsupported'],
]);

test('deepseek retry mapping matches the pipeline policy', function (int $status, bool $retryable) {
    Http::fake(['deepseek.example/*' => Http::response([], $status)]);

    try {
        app(DeepSeekProvider::class)->generate(new AiRequest(model: 'deepseek-chat', provider: 'deepseek'));
        $this->fail('Expected AiProviderException was not thrown.');
    } catch (AiProviderException $exception) {
        expect($exception->retryable)->toBe($retryable);
    }
})->with([[401, false], [403, false], [429, true], [503, true]]);
