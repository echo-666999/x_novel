<?php

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Data\EmbeddingRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\EmergencyStopAiProvider;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\OpenAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('ai request resolves system messages and a prompt in order', function () {
    $request = new AiRequest(
        model: 'test-model',
        systemPrompt: 'System rules',
        messages: [['role' => 'assistant', 'content' => 'Earlier response']],
        prompt: 'Write the next line',
    );

    expect($request->resolvedMessages())->toBe([
        ['role' => 'system', 'content' => 'System rules'],
        ['role' => 'assistant', 'content' => 'Earlier response'],
        ['role' => 'user', 'content' => 'Write the next line'],
    ]);
});

test('fake provider returns queued responses and records requests', function () {
    $response = aiResponse();
    $provider = (new FakeAiProvider)->enqueue($response);
    $request = new AiRequest(model: 'fake-model', prompt: 'Ping');

    expect($provider->generate($request))->toBe($response)
        ->and($provider->requests())->toBe([$request]);
});

test('openai provider maps a successful response to the provider dto', function () {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.base_url', 'https://llm.example/v1');

    Http::fake([
        'llm.example/*' => Http::response([
            'id' => 'request-123',
            'model' => 'current-model-2026-09-01',
            'choices' => [['message' => ['content' => '{"answer":"OK"}']]],
            'usage' => [
                'prompt_tokens' => 11,
                'completion_tokens' => 3,
                'prompt_tokens_details' => ['cached_tokens' => 4],
            ],
        ]),
    ]);

    $response = app(OpenAiProvider::class)->generate(new AiRequest(
        model: 'current-model',
        prompt: 'Ping',
        temperature: 0,
        maxTokens: 8,
        responseSchema: [
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
            'additionalProperties' => false,
        ],
    ));

    expect($response->content)->toBe('{"answer":"OK"}')
        ->and($response->structuredData)->toBe(['answer' => 'OK'])
        ->and($response->inputTokens)->toBe(11)
        ->and($response->outputTokens)->toBe(3)
        ->and($response->cachedTokens)->toBe(4)
        ->and($response->providerRequestId)->toBe('request-123')
        ->and($response->model)->toBe('current-model-2026-09-01');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://llm.example/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['response_format']['type'] === 'json_schema');
});

test('openai provider maps a fixed dimension embedding response', function () {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.base_url', 'https://llm.example/v1');
    Http::fake(['llm.example/*' => Http::response([
        'id' => 'embedding-request-1',
        'model' => 'text-embedding-test',
        'data' => [['embedding' => [0.1, -0.2, 0.3]]],
        'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
    ])]);

    $response = app(OpenAiProvider::class)->embed(new EmbeddingRequest(
        model: 'text-embedding-test',
        input: '需要向量化的记忆',
        dimensions: 3,
    ));

    expect($response->embedding)->toBe([0.1, -0.2, 0.3])
        ->and($response->inputTokens)->toBe(7)
        ->and($response->providerRequestId)->toBe('embedding-request-1')
        ->and($response->model)->toBe('text-embedding-test');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://llm.example/v1/embeddings'
        && $request['model'] === 'text-embedding-test'
        && $request['input'] === '需要向量化的记忆'
        && $request['dimensions'] === 3);
});

test('openai provider maps retryable and non retryable errors', function (int $status, string $code, bool $retryable) {
    config()->set('ai.providers.openai.api_key', 'test-key');
    Http::fake(['*' => Http::response(['error' => ['message' => 'Provider error']], $status)]);

    try {
        app(OpenAiProvider::class)->generate(new AiRequest(model: 'test-model', prompt: 'Ping'));
        $this->fail('Expected AiProviderException was not thrown.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->retryable)->toBe($retryable)
            ->and($exception->statusCode)->toBe($status)
            ->and($exception->getMessage())->toMatch('/[\x{4e00}-\x{9fff}]/u');
    }
})->with([
    'authentication' => [401, 'provider_authentication_failed', false],
    'rate limit' => [429, 'provider_rate_limited', true],
    'server error' => [503, 'provider_request_failed', true],
]);

test('openai provider rejects missing credentials before sending a request', function () {
    config()->set('ai.providers.openai.api_key', null);
    Http::fake();

    app(OpenAiProvider::class)->generate(new AiRequest(model: 'test-model', prompt: 'Ping'));
})->throws(AiProviderException::class, 'AI Provider API Key 尚未配置。');

test('openai provider maps connection timeouts to a retryable timeout error', function () {
    config()->set('ai.providers.openai.api_key', 'test-key');
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    try {
        app(OpenAiProvider::class)->generate(new AiRequest(model: 'test-model', prompt: 'Ping'));
        $this->fail('Expected AiProviderException was not thrown.');
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('provider_timeout')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->statusCode)->toBeNull();
    }
});

test('the configured provider is bound through the interface', function () {
    config()->set('ai.provider', 'openai');

    expect(app(AiProvider::class))->toBeInstanceOf(EmergencyStopAiProvider::class);
});

function aiResponse(): AiResponse
{
    return new AiResponse(
        content: 'OK',
        structuredData: null,
        inputTokens: 1,
        outputTokens: 1,
        cachedTokens: 0,
        latencyMs: 12,
        providerRequestId: 'fake-request',
        model: 'fake-model',
    );
}
