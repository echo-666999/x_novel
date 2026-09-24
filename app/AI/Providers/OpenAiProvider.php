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
use App\AI\JsonSchemaValidator;
use App\AI\OpenAiStructuredOutputSchema;
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

    public function __construct(
        private readonly AiSettingsService $settingsService,
        private readonly OpenAiStructuredOutputSchema $structuredOutputSchema,
        private readonly JsonSchemaValidator $schemaValidator,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        if ($request->responseSchema !== null) {
            $this->structuredOutputSchema->assertValid($request->responseSchema);
        }

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

        return $this->mapResponse($response, $latencyMs, $request->responseSchema);
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        if (trim($request->model) === '' || trim($request->input) === '' || $request->dimensions < 1) {
            throw new AiProviderException(
                errorCode: 'provider_invalid_embedding_request',
                message: 'Embedding 请求必须包含模型、非空输入和正整数维度。',
                retryable: false,
            );
        }

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

        if (! is_array($embedding)
            || ! array_is_list($embedding)
            || count($embedding) !== $request->dimensions
            || collect($embedding)->contains(fn (mixed $value): bool => ! is_int($value) && ! is_float($value))
            || ! is_string($model)) {
            throw new AiProviderException('provider_invalid_response', 'Embedding Provider 返回了无效响应。', false);
        }

        return new EmbeddingResponse(
            embedding: array_map(static fn (mixed $value): float => (float) $value, $embedding),
            inputTokens: (int) $response->json('usage.prompt_tokens', 0),
            latencyMs: $latencyMs,
            providerRequestId: $response->header('x-request-id') ?: $response->json('id'),
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

        if ($request->reasoningEffort !== null) {
            $payload['reasoning_effort'] = $request->reasoningEffort;
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

    /** @param array<string, mixed>|null $schema */
    private function mapResponse(Response $response, int $latencyMs, ?array $schema): AiResponse
    {
        $content = $response->json('choices.0.message.content');
        $model = $response->json('model');
        $refusal = $response->json('choices.0.message.refusal');

        if (is_string($refusal) && trim($refusal) !== '') {
            throw new AiProviderException(
                errorCode: 'provider_refused',
                message: 'AI Provider 拒绝生成当前内容：'.$this->safeProviderDetail($refusal),
                retryable: false,
                statusCode: $response->status(),
            );
        }

        if (! is_string($content) || ! is_string($model)) {
            throw new AiProviderException(
                errorCode: 'provider_invalid_response',
                message: 'AI Provider 返回了无效响应。',
                retryable: false,
                statusCode: $response->status(),
            );
        }

        $structuredData = null;

        if ($schema !== null) {
            if ($response->json('choices.0.finish_reason') === 'length') {
                throw new AiProviderException('provider_output_truncated', 'AI Provider 结构化输出因 Token 用尽而被截断。', true, $response->status());
            }

            if (trim($content) === '') {
                throw new AiProviderException('provider_empty_json_response', 'AI Provider 结构化输出为空。', false, $response->status());
            }

            try {
                $decodedObject = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
                $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new AiProviderException('provider_invalid_json_response', 'AI Provider 结构化输出不是合法 JSON。', false, $response->status(), $exception);
            }

            if (! is_array($decoded) || ! $this->schemaValidator->matches($decodedObject, $schema)) {
                throw new AiProviderException('provider_schema_validation_failed', 'AI Provider 结构化输出不符合响应 Schema。', false, $response->status());
            }

            $structuredData = $decoded;
        } elseif ($content !== '' && str_starts_with(ltrim($content), '{')) {
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
            providerRequestId: $response->header('x-request-id') ?: $response->json('id'),
            model: $model,
            metadata: [
                'finish_reason' => $response->json('choices.0.finish_reason'),
                'refusal' => $refusal,
            ],
        );
    }

    private function mapFailedResponse(Response $response): AiProviderException
    {
        $status = $response->status();
        $detail = $this->providerErrorDetail($response);

        if ($status === 400 && str_contains(strtolower($detail), 'invalid schema')) {
            return new AiProviderException(
                'provider_invalid_response_schema',
                'AI Provider 拒绝了响应 Schema（HTTP 400）：'.$detail,
                false,
                $status,
            );
        }

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
                    : "AI Provider 拒绝了请求（HTTP {$status}）：{$detail}",
                $status >= 500,
                $status,
            ),
        };
    }

    private function providerErrorDetail(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) && trim($message) !== ''
            ? $this->safeProviderDetail($message)
            : 'Provider 未返回具体错误原因。';
    }

    private function safeProviderDetail(string $detail): string
    {
        $sanitized = (string) $this->sanitizeForLogging($detail);
        $sanitized = preg_replace('/\s+/u', ' ', trim($sanitized)) ?? trim($sanitized);

        return mb_substr($sanitized, 0, 600);
    }
}
