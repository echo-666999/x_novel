<?php

namespace App\AI;

use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Models\AIProviderConnection;
use Illuminate\Support\Facades\Schema;

/**
 * 提供应用支持的 Provider 清单，从数据库读取连接、从config白名单读取凭据。
 */
class AiSettingsService
{
    public const URL_MAX_LENGTH = 2048;

    public const MIN_TIMEOUT_SECONDS = 1;

    public const MAX_CONNECT_TIMEOUT_SECONDS = 60;

    public const MAX_REQUEST_TIMEOUT_SECONDS = 300;

    /** @var array<int, string> */
    private const REGISTERED_PROVIDERS = ['openai', 'deepseek'];

    /** @return array<int, AiStage> */
    public function stages(): array
    {
        return array_values(array_filter(AiStage::cases(), static fn (AiStage $stage): bool => $stage !== AiStage::Embedding));
    }

    /** @return array<int, string> */
    public function registeredProviders(): array
    {
        return self::REGISTERED_PROVIDERS;
    }

    /** @return array{enabled: bool, base_url: string, connect_timeout: int, timeout: int}|array{} */
    public function providerSettings(string $provider): array
    {
        $connection = $this->providerConnection($provider);
        if ($connection === null) {
            return [];
        }

        return [
            'enabled' => $connection->is_enabled,
            'base_url' => $connection->base_url,
            'connect_timeout' => $connection->connect_timeout,
            'timeout' => $connection->timeout,
        ];
    }

    public function providerTimeout(string $provider): int
    {
        $timeout = data_get($this->providerSettings($provider), 'timeout');
        if (! is_int($timeout) || $timeout < self::MIN_TIMEOUT_SECONDS) {
            throw new AiProviderException('provider_not_configured', "AI provider [{$provider}] has no database connection.", false);
        }

        return $timeout;
    }

    public function apiKey(string $provider): string
    {
        $reference = $this->providerConnection($provider)?->credential_ref;
        $credentials = (array) config('ai.credentials', []);
        $apiKey = is_string($reference) && array_key_exists($reference, $credentials) ? $credentials[$reference] : null;

        return is_string($apiKey) ? $apiKey : '';
    }

    public function isCredentialConfigured(string $provider): bool
    {
        return $this->apiKey($provider) !== '';
    }

    public function assertProviderAvailable(string $provider, bool $requireEnabled = true): void
    {
        if (! in_array($provider, $this->registeredProviders(), true)) {
            throw new AiProviderException('provider_unsupported', "AI provider [{$provider}] is not registered.", false);
        }

        $connection = $this->providerConnection($provider);
        if ($connection === null) {
            throw new AiProviderException('provider_not_configured', "AI provider [{$provider}] has no database connection.", false);
        }
        if ($requireEnabled && ! $connection->is_enabled) {
            throw new AiProviderException('provider_disabled', "AI provider [{$provider}] is disabled.", false);
        }
        if (! $this->isCredentialConfigured($provider)) {
            throw new AiProviderException('provider_not_configured', "AI provider [{$provider}] has no configured API key.", false);
        }
    }

    private function providerConnection(string $provider): ?AIProviderConnection
    {
        if (! Schema::hasTable('ai_provider_connections')) {
            return null;
        }

        return AIProviderConnection::query()
            ->where('provider', strtolower(trim($provider)))
            ->first();
    }
}
