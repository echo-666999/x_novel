<?php

namespace App\AI\Providers;

use App\AI\AiSettingsService;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\JsonSchemaValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;

class DeepSeekProvider implements AiProvider
{
    private const PROVIDER = 'deepseek';

    private const DIAGNOSTIC_METADATA_KEYS = ['generation_run_id', 'novel_id', 'chapter_id', 'scene_id', 'stage'];

    private const SENSITIVE_LOG_KEYS = ['authorization', 'proxy_authorization', 'api_key', 'openai_api_key', 'deepseek_api_key'];

    public function __construct(
        private readonly AiSettingsService $settingsService,
        private readonly JsonSchemaValidator $schemaValidator,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $startedAt = hrtime(true);
        $requestLogId = (string) ($request->metadata['ai_request_log_id'] ?? Str::uuid());
        $payload = $this->payload($request);
        $requestLogContext = $this->diagnosticLogContext($request, $requestLogId);

        if ((bool) config('ai.logging.prompts', false)) {
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
                $timedOut ? 'provider_timeout' : 'provider_connection_failed',
                $timedOut ? 'AI Provider 请求超时，请稍后重试。' : '无法连接 AI Provider，请稍后重试。',
                true,
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
            'cached_tokens' => (int) $response->json('usage.prompt_cache_hit_tokens', $response->json('usage.prompt_tokens_details.cached_tokens', 0)),
            'body' => $this->sanitizedResponseBody($response),
        ]);

        if ($response->failed()) {
            throw $this->mapFailedResponse($response);
        }

        return $this->mapResponse($response, $latencyMs, $request->responseSchema);
    }

    /** @return array<string, mixed> */
    private function payload(AiRequest $request): array
    {
        $messages = $request->resolvedMessages();
        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
        ];

        if ($request->responseSchema !== null) {
            array_unshift($payload['messages'], [
                'role' => 'system',
                'content' => 'Return one valid JSON object only. It must satisfy this JSON Schema: '.json_encode($request->responseSchema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    private function client(): PendingRequest
    {
        $apiKey = $this->settingsService->apiKey(self::PROVIDER);
        if ($apiKey === '') {
            throw new AiProviderException('provider_not_configured', 'DeepSeek API Key 尚未配置。', false);
        }

        $providerSettings = $this->settingsService->providerSettings(self::PROVIDER);

        return Http::baseUrl(rtrim((string) ($providerSettings['base_url'] ?? config('ai.providers.deepseek.base_url')), '/'))
            ->withToken($apiKey)->acceptJson()->asJson()
            ->connectTimeout((int) ($providerSettings['connect_timeout'] ?? config('ai.providers.deepseek.connect_timeout', 10)))
            ->timeout((int) ($providerSettings['timeout'] ?? config('ai.providers.deepseek.timeout', 60)));
    }

    /** @param array<string, mixed>|null $schema */
    private function mapResponse(Response $response, int $latencyMs, ?array $schema): AiResponse
    {
        $content = $response->json('choices.0.message.content');
        $model = $response->json('model');

        if (! is_string($content) || ! is_string($model)) {
            throw new AiProviderException('provider_invalid_response', 'DeepSeek 返回了无效响应。', false, $response->status());
        }

        $structuredData = null;
        if ($schema !== null) {
            if (trim($content) === '') {
                throw new AiProviderException('provider_empty_json_response', 'DeepSeek JSON Output 返回了空内容。', false, $response->status());
            }

            try {
                $decodedObject = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
                $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new AiProviderException('provider_invalid_json_response', 'DeepSeek JSON Output 不是合法 JSON。', false, $response->status(), $exception);
            }

            if (! is_array($decoded) || ! $this->schemaValidator->matches($decodedObject, $schema)) {
                throw new AiProviderException('provider_schema_validation_failed', 'DeepSeek JSON Output 不符合响应 Schema。', false, $response->status());
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
            cachedTokens: (int) $response->json('usage.prompt_cache_hit_tokens', $response->json('usage.prompt_tokens_details.cached_tokens', 0)),
            latencyMs: $latencyMs,
            providerRequestId: $response->json('id'),
            model: $model,
            metadata: ['finish_reason' => $response->json('choices.0.finish_reason'), 'refusal' => null],
        );
    }

    private function mapFailedResponse(Response $response): AiProviderException
    {
        $status = $response->status();

        return match ($status) {
            401, 403 => new AiProviderException('provider_authentication_failed', 'AI Provider 认证失败，请检查 API Key 和访问权限。', false, $status),
            408, 429 => new AiProviderException($status === 429 ? 'provider_rate_limited' : 'provider_timeout', $status === 429 ? 'AI Provider 请求频率受限，请稍后重试。' : 'AI Provider 请求超时，请稍后重试。', true, $status),
            default => new AiProviderException('provider_request_failed', $status >= 500 ? "AI Provider 服务暂时不可用（HTTP {$status}），请稍后重试。" : "AI Provider 拒绝了请求（HTTP {$status}）。", $status >= 500, $status),
        };
    }

    /** @return array<string, mixed> */
    private function diagnosticLogContext(AiRequest $request, string $requestLogId): array
    {
        $context = ['ai_request_log_id' => $requestLogId, 'provider' => self::PROVIDER, 'model' => $request->model, 'endpoint' => '/chat/completions', 'prompt_version' => $request->promptVersion];
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

        return is_array($decoded)
            ? json_encode($this->sanitizeForLogging($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $this->sanitizeForLogging($response->body());
    }

    private function sanitizeForLogging(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $normalized = is_string($key) ? strtolower(str_replace(['-', '.', ' '], '_', $key)) : null;
                $sanitized[$key] = $normalized !== null && in_array($normalized, self::SENSITIVE_LOG_KEYS, true) ? '[REDACTED]' : $this->sanitizeForLogging($item);
            }

            return $sanitized;
        }
        if (! is_string($value)) {
            return $value;
        }
        $keys = array_filter([
            $this->settingsService->apiKey('openai'),
            $this->settingsService->apiKey('deepseek'),
        ]);

        return str_replace($keys, array_fill(0, count($keys), '[REDACTED]'), $value);
    }
}
