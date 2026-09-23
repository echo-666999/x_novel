<?php

namespace App\AI\Providers;

use App\AI\AiSettingsService;
use App\AI\Contracts\AiProvider;
use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;
use App\AI\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;

class OpenAiProvider implements AiProvider, EmbeddingProvider
{
    private static bool $legacyEnvironmentWarningLogged = false;

    private const PROVIDER = 'openai';

    private const DIAGNOSTIC_METADATA_KEYS = [
        'generation_run_id',
        'novel_id',
        'chapter_id',
        'scene_id',
        'stage',
    ];

    private const SENSITIVE_LOG_KEYS = [
        'authorization',
        'proxy_authorization',
        'api_key',
        'openai_api_key',
        'deepseek_api_key',
    ];

    public function __construct(private readonly AiSettingsService $settingsService) {}

    public function generate(AiRequest $request): AiResponse
    {
        $startedAt = hrtime(true);
        $requestLogId = (string) ($request->metadata['ai_request_log_id'] ?? Str::uuid());
        $payload = $this->payload($request);

        $requestLogContext = $this->diagnosticLogContext($request, $requestLogId);

        if ((bool) config('ai.logging.prompts', false)) {
            $requestLogContext['ai_models'] = $this->sanitizeForLogging((array) config('ai.models', []));
            $requestLogContext['metadata'] = $this->sanitizeForLogging($request->metadata);
            $requestLogContext['payload'] = $this->sanitizeForLogging($payload);
        }

        Log::debug('AI Provider 请求。', $requestLogContext);

        try {
            $response = $this->client()->post('/chat/completions', $payload);
        } catch (ConnectionException $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out')
                || str_contains(strtolower($exception->getMessage()), 'timeout');

            Log::warning('AI Provider 请求未收到响应。', [
                ...$this->diagnosticLogContext($request, $requestLogId),
                'exception' => $this->sanitizeForLogging($exception->getMessage()),
            ]);

            throw new AiProviderException(
                errorCode: $timedOut ? 'provider_timeout' : 'provider_connection_failed',
                message: $timedOut ? 'AI Provider 请求超时，请稍后重试。' : '无法连接 AI Provider，请稍后重试。',
                retryable: true,
                previous: $exception,
            );
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        Log::debug('AI Provider 完整响应。', [
            ...$this->diagnosticLogContext($request, $requestLogId),
            'model' => $response->json('model') ?? $request->model,
            'status' => $response->status(),
            'latency_ms' => $latencyMs,
            'provider_request_id' => $response->header('x-request-id') ?: $response->json('id'),
            'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
            'cached_tokens' => (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0),
            'body' => $this->sanitizedResponseBody($response),
        ]);

        if ($response->failed()) {
            throw $this->mapFailedResponse($response);
        }

        return $this->mapResponse($response, $latencyMs);
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client()->post('/embeddings', [
                'model' => $request->model,
                'input' => $request->input,
                'dimensions' => $request->dimensions,
            ]);
        } catch (ConnectionException $exception) {
            throw new AiProviderException(
                errorCode: 'provider_connection_failed',
                message: '无法连接 Embedding Provider，请稍后重试。',
                retryable: true,
                previous: $exception,
            );
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if ($response->failed()) {
            throw $this->mapFailedResponse($response);
        }

        $embedding = $response->json('data.0.embedding');
        $model = $response->json('model');

        if (! is_array($embedding) || ! is_string($model)) {
            throw new AiProviderException('provider_invalid_response', 'Embedding Provider 返回了无效响应。', false);
        }

        return new EmbeddingResponse(
            embedding: array_map(static fn (mixed $value): float => (float) $value, $embedding),
            inputTokens: (int) $response->json('usage.prompt_tokens', 0),
            latencyMs: $latencyMs,
            providerRequestId: $response->json('id'),
            model: $model,
        );
    }

    private function client(): PendingRequest
    {
        $this->logLegacyEnvironmentWarning();
        $providerSettings = $this->settingsService->providerSettings(self::PROVIDER);
        $apiKey = $this->settingsService->apiKey(self::PROVIDER);

        if ($apiKey === '') {
            throw new AiProviderException(
                errorCode: 'provider_not_configured',
                message: 'AI Provider API Key 尚未配置。',
                retryable: false,
            );
        }

        return Http::baseUrl(rtrim((string) ($providerSettings['base_url'] ?? config('ai.providers.openai.base_url')), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($providerSettings['connect_timeout'] ?? config('ai.providers.openai.connect_timeout', 10)))
            ->timeout((int) ($providerSettings['timeout'] ?? config('ai.providers.openai.timeout', 60)));
    }

    private function logLegacyEnvironmentWarning(): void
    {
        if (self::$legacyEnvironmentWarningLogged) {
            return;
        }

        $legacy = array_values(array_filter([
            config('ai.providers.openai.using_legacy_api_key') ? 'AI_API_KEY' : null,
            config('ai.providers.openai.using_legacy_base_url') ? 'AI_BASE_URL' : null,
        ]));

        if ($legacy !== []) {
            Log::warning('Deprecated OpenAI environment variables are in use.', ['variables' => $legacy]);
        }

        self::$legacyEnvironmentWarningLogged = true;
    }

    /** @return array<string, mixed> */
    private function payload(AiRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->resolvedMessages(),
            'max_completion_tokens' => $request->maxTokens,
        ];

        if (! str_starts_with($request->model, 'gpt-5.6')) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->responseSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'xnovel_response',
                    'strict' => true,
                    'schema' => $request->responseSchema,
                ],
            ];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function diagnosticLogContext(AiRequest $request, string $requestLogId): array
    {
        $context = [
            'ai_request_log_id' => $requestLogId,
            'provider' => self::PROVIDER,
            'model' => $request->model,
            'endpoint' => '/chat/completions',
            'prompt_version' => $request->promptVersion,
        ];

        foreach (self::DIAGNOSTIC_METADATA_KEYS as $key) {
            if (array_key_exists($key, $request->metadata)) {
                $context[$key] = $request->metadata[$key];
            }
        }

        return $context;
    }

    private function sanitizedResponseBody(Response $response): string
    {
        $decoded = $response->json();

        if (is_array($decoded)) {
            return json_encode(
                $this->sanitizeForLogging($decoded),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return (string) $this->sanitizeForLogging($response->body());
    }

    private function sanitizeForLogging(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $normalizedKey = is_string($key)
                    ? strtolower(str_replace(['-', '.', ' '], '_', $key))
                    : null;

                $sanitized[$key] = $normalizedKey !== null && in_array($normalizedKey, self::SENSITIVE_LOG_KEYS, true)
                    ? '[REDACTED]'
                    : $this->sanitizeForLogging($item);
            }

            return $sanitized;
        }

        if (! is_string($value)) {
            return $value;
        }

        $apiKey = $this->settingsService->apiKey(self::PROVIDER);

        return $apiKey === '' ? $value : str_replace($apiKey, '[REDACTED]', $value);
    }

    private function mapResponse(Response $response, int $latencyMs): AiResponse
    {
        $content = $response->json('choices.0.message.content');
        $model = $response->json('model');

        if (! is_string($content) || ! is_string($model)) {
            throw new AiProviderException(
                errorCode: 'provider_invalid_response',
                message: 'AI Provider 返回了无效响应。',
                retryable: false,
                statusCode: $response->status(),
            );
        }

        $structuredData = null;

        if ($content !== '' && str_starts_with(ltrim($content), '{')) {
            try {
                $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                $structuredData = is_array($decoded) ? $decoded : null;
            } catch (JsonException) {
                $structuredData = null;
            }
        }

        return new AiResponse(
            content: $content,
            structuredData: $structuredData,
            inputTokens: (int) $response->json('usage.prompt_tokens', 0),
            outputTokens: (int) $response->json('usage.completion_tokens', 0),
            cachedTokens: (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0),
            latencyMs: $latencyMs,
            providerRequestId: $response->json('id'),
            model: $model,
            metadata: [
                'finish_reason' => $response->json('choices.0.finish_reason'),
                'refusal' => $response->json('choices.0.message.refusal'),
            ],
        );
    }

    private function mapFailedResponse(Response $response): AiProviderException
    {
        $status = $response->status();

        return match ($status) {
            401, 403 => new AiProviderException(
                'provider_authentication_failed',
                'AI Provider 认证失败，请检查 API Key 和访问权限。',
                false,
                $status,
            ),
            408, 429 => new AiProviderException(
                $status === 429 ? 'provider_rate_limited' : 'provider_timeout',
                $status === 429 ? 'AI Provider 请求频率受限，请稍后重试。' : 'AI Provider 请求超时，请稍后重试。',
                true,
                $status,
            ),
            default => new AiProviderException(
                'provider_request_failed',
                $status >= 500
                    ? "AI Provider 服务暂时不可用（HTTP {$status}），请稍后重试。"
                    : "AI Provider 拒绝了请求（HTTP {$status}），请检查输入数据和结构化输出格式。",
                $status >= 500,
                $status,
            ),
        };
    }
}
