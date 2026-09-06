<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class OpenAiProvider implements AiProvider
{
    public function generate(AiRequest $request): AiResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client()->post('/chat/completions', $this->payload($request));
        } catch (ConnectionException $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out')
                || str_contains(strtolower($exception->getMessage()), 'timeout');

            throw new AiProviderException(
                errorCode: $timedOut ? 'provider_timeout' : 'provider_connection_failed',
                message: $timedOut ? 'AI Provider 请求超时，请稍后重试。' : '无法连接 AI Provider，请稍后重试。',
                retryable: true,
                previous: $exception,
            );
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if ($response->failed()) {
            throw $this->mapFailedResponse($response);
        }

        return $this->mapResponse($response, $latencyMs);
    }

    private function client(): PendingRequest
    {
        $apiKey = (string) config('ai.providers.openai.api_key');

        if ($apiKey === '') {
            throw new AiProviderException(
                errorCode: 'provider_not_configured',
                message: 'AI Provider API Key 尚未配置。',
                retryable: false,
            );
        }

        return Http::baseUrl(rtrim((string) config('ai.providers.openai.base_url'), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('ai.providers.openai.connect_timeout', 10))
            ->timeout((int) config('ai.providers.openai.timeout', 60));
    }

    /** @return array<string, mixed> */
    private function payload(AiRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->resolvedMessages(),
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
        ];

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
        );
    }

    private function mapFailedResponse(Response $response): AiProviderException
    {
        $status = $response->status();
        $providerMessage = $response->json('error.message');

        return match ($status) {
            401, 403 => new AiProviderException(
                'provider_authentication_failed',
                is_string($providerMessage) ? $providerMessage : 'AI Provider 认证失败。',
                false,
                $status,
            ),
            408, 429 => new AiProviderException(
                $status === 429 ? 'provider_rate_limited' : 'provider_timeout',
                is_string($providerMessage) ? $providerMessage : 'AI Provider 暂时不可用，请稍后重试。',
                true,
                $status,
            ),
            default => new AiProviderException(
                'provider_request_failed',
                is_string($providerMessage) ? $providerMessage : 'AI Provider 请求失败。',
                $status >= 500,
                $status,
            ),
        };
    }
}
