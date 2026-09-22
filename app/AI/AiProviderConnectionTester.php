<?php

namespace App\AI;

use App\AI\Exceptions\AiProviderException;
use App\Models\AIProviderConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AiProviderConnectionTester
{
    /** @return array{model_count: int} */
    public function test(AIProviderConnection $connection): array
    {
        if (! $connection->is_enabled || blank($connection->api_key)) {
            throw new AiProviderException('provider_not_configured', '供应商连接未启用或 API Key 未配置。', false);
        }

        try {
            $response = Http::baseUrl(rtrim($connection->base_url, '/'))
                ->withToken($connection->api_key)
                ->acceptJson()
                ->connectTimeout($connection->connect_timeout)
                ->timeout($connection->timeout)
                ->get('/models');
        } catch (ConnectionException $exception) {
            throw new AiProviderException('provider_connection_failed', '供应商连接失败：'.$exception->getMessage(), true, previous: $exception);
        }

        if ($response->failed()) {
            throw new AiProviderException(
                'provider_http_error',
                '供应商返回 HTTP '.$response->status().'。',
                $response->status() === 429 || $response->serverError(),
                $response->status(),
            );
        }

        $models = $response->json('data');
        $connection->forceFill(['last_verified_at' => now()])->save();

        return ['model_count' => is_array($models) ? count($models) : 0];
    }
}
