<?php

namespace App\AI;

use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Models\AIProviderConnection;
use App\Models\SystemSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AiSettingsService
{
    public const SCHEMA_VERSION = 1;

    public const MODEL_MAX_LENGTH = 255;

    public const URL_MAX_LENGTH = 2048;

    public const MIN_TIMEOUT_SECONDS = 1;

    public const MAX_CONNECT_TIMEOUT_SECONDS = 60;

    public const MAX_REQUEST_TIMEOUT_SECONDS = 300;

    /** @return array<int, AiStage> */
    public function stages(): array
    {
        return array_values(array_filter(AiStage::cases(), static fn (AiStage $stage): bool => $stage !== AiStage::Embedding));
    }

    /** @return array<int, string> */
    public function registeredProviders(): array
    {
        return array_values(array_filter(array_keys((array) config('ai.providers', [])), static fn (mixed $provider): bool => is_string($provider) && $provider !== ''));
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $defaultProvider = trim((string) config('ai.provider'));
        $defaultModel = trim((string) config('ai.model'));
        $providers = [];

        foreach ($this->registeredProviders() as $provider) {
            $providers[$provider] = [
                'enabled' => $provider === $defaultProvider || (bool) config("ai.providers.{$provider}.enabled", false),
                'base_url' => rtrim((string) config("ai.providers.{$provider}.base_url"), '/'),
                'credential' => null,
                'connect_timeout' => (int) config("ai.providers.{$provider}.connect_timeout", 10),
                'timeout' => (int) config("ai.providers.{$provider}.timeout", 150),
            ];
        }

        $stages = [];
        foreach ($this->stages() as $stage) {
            $configuredModel = config("ai.models.{$stage->value}");
            $stages[$stage->value] = [
                'provider' => $defaultProvider,
                'model' => is_string($configuredModel) && trim($configuredModel) !== '' ? trim($configuredModel) : $defaultModel,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'default_provider' => $defaultProvider,
            'providers' => $providers,
            'stages' => $stages,
            'cost' => [
                'currency' => strtoupper(trim((string) config('ai.cost.currency', 'USD'))),
                'input_per_million' => (float) config('ai.cost.input_per_million', 0),
                'cached_input_per_million' => (float) config('ai.cost.cached_input_per_million', 0),
                'output_per_million' => (float) config('ai.cost.output_per_million', 0),
            ],
            'budget' => [
                'daily_hard_limit' => $this->nullableNonNegative(config('ai.budget.daily_hard_limit')),
                'novel_total_limit' => $this->nullableNonNegative(config('ai.budget.novel_total_limit')),
                'chapter_max_cost' => $this->nullableNonNegative(config('ai.budget.chapter_max_cost')),
            ],
        ];
    }

    /** @return array{settings: array<string, mixed>, source: string} */
    public function current(): array
    {
        if (! Schema::hasTable('system_settings')) {
            return ['settings' => $this->defaults(), 'source' => 'environment'];
        }

        $value = SystemSetting::query()->find(SystemSetting::AI)?->value;
        if (! is_array($value)) {
            return ['settings' => $this->defaults(), 'source' => 'environment'];
        }

        try {
            return ['settings' => $this->normalize(array_replace_recursive($this->defaults(), $value), false), 'source' => 'database'];
        } catch (ValidationException $exception) {
            Log::warning('Stored AI settings are invalid; environment defaults are active.', [
                'setting_key' => SystemSetting::AI,
                'validation_fields' => array_keys($exception->errors()),
            ]);

            return ['settings' => $this->defaults(), 'source' => 'environment_invalid_database'];
        }
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->current()['settings'];
    }

    /** @return array<string, mixed> */
    public function editableSettings(): array
    {
        return $this->editable($this->settings());
    }

    /** @return array<string, mixed> */
    public function providerSettings(string $provider): array
    {
        $connection = $this->providerConnection($provider);
        if ($connection !== null) {
            return [
                'enabled' => $connection->is_enabled,
                'base_url' => $connection->base_url,
                'connect_timeout' => $connection->connect_timeout,
                'timeout' => $connection->timeout,
            ];
        }

        $settings = data_get($this->settings(), "providers.{$provider}");

        return is_array($settings) ? collect($settings)->except('credential')->all() : [];
    }

    /** @return array<string, mixed> */
    public function costSettings(): array
    {
        return (array) data_get($this->settings(), 'cost', []);
    }

    /** @return array<string, mixed> */
    public function budgetSettings(): array
    {
        return (array) data_get($this->settings(), 'budget', []);
    }

    public function apiKey(string $provider): string
    {
        $connection = $this->providerConnection($provider);
        if ($connection !== null && filled($connection->api_key)) {
            return $connection->api_key;
        }

        $credential = data_get($this->settings(), "providers.{$provider}.credential");
        if (is_string($credential) && $credential !== '') {
            try {
                return Crypt::decryptString($credential);
            } catch (DecryptException) {
                return '';
            }
        }

        return (string) config("ai.providers.{$provider}.api_key");
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
        $enabled = $connection?->is_enabled ?? data_get($this->settings(), "providers.{$provider}.enabled");
        if ($requireEnabled && $enabled !== true) {
            throw new AiProviderException('provider_disabled', "AI provider [{$provider}] is disabled.", false);
        }
        if (! $this->isCredentialConfigured($provider)) {
            throw new AiProviderException('provider_not_configured', "AI provider [{$provider}] has no configured API key.", false);
        }
    }

    /** @param array<string, mixed> $settings @return array{settings: array<string, mixed>, changed: bool} */
    public function save(array $settings, ?int $actorId): array
    {
        return DB::transaction(function () use ($settings, $actorId): array {
            $record = SystemSetting::query()->lockForUpdate()->find(SystemSetting::AI);
            $before = is_array($record?->value) ? $record->value : null;
            $existing = is_array($before) ? array_replace_recursive($this->defaults(), $before) : $this->defaults();
            $normalized = $this->normalize($settings, true, $existing, true);
            $beforeNormalized = is_array($before) ? $this->normalize($existing, false) : null;

            if ($beforeNormalized === $normalized) {
                return ['settings' => $this->editable($normalized), 'changed' => false];
            }

            $record === null
                ? SystemSetting::query()->create(['key' => SystemSetting::AI, 'value' => $normalized])
                : $record->update(['value' => $normalized]);

            Log::info('AI settings changed.', [
                'actor_id' => $actorId,
                'before' => $this->auditSnapshot($before),
                'after' => $this->auditSnapshot($normalized),
            ]);

            return ['settings' => $this->editable($normalized), 'changed' => true];
        });
    }

    /** @param array<string, mixed> $settings @param array<string, mixed> $existing @return array<string, mixed> */
    private function normalize(array $settings, bool $requireCredentials, array $existing = [], bool $acceptCredentialInput = false): array
    {
        $errors = [];
        foreach (array_diff(array_keys($settings), ['schema_version', 'default_provider', 'providers', 'stages', 'cost', 'budget']) as $key) {
            $errors[(string) $key][] = 'AI 配置包含未知字段。';
        }
        if (($settings['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors['schema_version'][] = 'AI 配置 Schema Version 不受支持。';
        }

        $registeredProviders = $this->registeredProviders();
        $defaultProvider = $this->normalizedProvider($settings['default_provider'] ?? null);
        if (! in_array($defaultProvider, $registeredProviders, true)) {
            $errors['default_provider'][] = '默认 Provider 未在应用中注册。';
        }

        $providerInput = is_array($settings['providers'] ?? null) ? $settings['providers'] : [];
        if (! is_array($settings['providers'] ?? null)) {
            $errors['providers'][] = 'Provider 配置必须是对象。';
        }
        foreach (array_keys($providerInput) as $provider) {
            if (! is_string($provider) || ! in_array($provider, $registeredProviders, true)) {
                $errors["providers.{$provider}"][] = 'Provider 未在应用中注册。';
            }
        }

        $providers = [];
        foreach ($registeredProviders as $provider) {
            $input = $providerInput[$provider] ?? null;
            if (! is_array($input)) {
                $errors["providers.{$provider}"][] = '缺少 Provider 配置。';

                continue;
            }
            $allowed = ['enabled', 'base_url', 'credential', 'connect_timeout', 'timeout'];
            if ($acceptCredentialInput) {
                $allowed = [...$allowed, 'api_key', 'clear_api_key'];
            }
            foreach (array_diff(array_keys($input), $allowed) as $key) {
                $errors["providers.{$provider}.{$key}"][] = 'Provider 配置包含未知字段。';
            }

            $enabled = filter_var($input['enabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $baseUrl = is_string($input['base_url'] ?? null) ? rtrim(trim($input['base_url']), '/') : '';
            $connectTimeout = filter_var($input['connect_timeout'] ?? null, FILTER_VALIDATE_INT);
            $timeout = filter_var($input['timeout'] ?? null, FILTER_VALIDATE_INT);
            if ($enabled === null) {
                $errors["providers.{$provider}.enabled"][] = '启用状态必须是布尔值。';
            }
            if (! $this->validBaseUrl($baseUrl)) {
                $errors["providers.{$provider}.base_url"][] = 'Base URL 必须是有效的 HTTP 或 HTTPS 地址。';
            }
            if ($connectTimeout === false || $connectTimeout < self::MIN_TIMEOUT_SECONDS || $connectTimeout > self::MAX_CONNECT_TIMEOUT_SECONDS) {
                $errors["providers.{$provider}.connect_timeout"][] = '连接超时必须在 1 到 60 秒之间。';
            }
            if ($timeout === false || $timeout < self::MIN_TIMEOUT_SECONDS || $timeout > self::MAX_REQUEST_TIMEOUT_SECONDS) {
                $errors["providers.{$provider}.timeout"][] = '请求超时必须在 1 到 300 秒之间。';
            }
            if ($connectTimeout !== false && $timeout !== false && $connectTimeout > $timeout) {
                $errors["providers.{$provider}.connect_timeout"][] = '连接超时不能大于请求超时。';
            }

            $credential = $acceptCredentialInput
                ? $this->credentialForSave($provider, $input, $existing, $errors)
                : ($input['credential'] ?? null);
            if ($credential !== null && (! is_string($credential) || ! $this->decryptable($credential))) {
                $errors["providers.{$provider}.credential"][] = '已保存的 API Key 无法使用当前应用密钥解密。';
                $credential = null;
            }
            $providers[$provider] = [
                'enabled' => $enabled ?? false,
                'base_url' => $baseUrl,
                'credential' => $credential,
                'connect_timeout' => $connectTimeout === false ? 0 : $connectTimeout,
                'timeout' => $timeout === false ? 0 : $timeout,
            ];
        }

        $stageInput = is_array($settings['stages'] ?? null) ? $settings['stages'] : [];
        if (! is_array($settings['stages'] ?? null)) {
            $errors['stages'][] = 'Stage 配置必须是对象。';
        }
        $knownStages = array_map(static fn (AiStage $stage): string => $stage->value, $this->stages());
        foreach (array_keys($stageInput) as $stage) {
            if (! is_string($stage) || ! in_array($stage, $knownStages, true)) {
                $errors["stages.{$stage}"][] = '未知的 AI Stage。';
            }
        }

        $stages = [];
        foreach ($this->stages() as $stage) {
            $input = $stageInput[$stage->value] ?? null;
            if (! is_array($input)) {
                $errors["stages.{$stage->value}"][] = '缺少 Stage 配置。';

                continue;
            }
            foreach (array_diff(array_keys($input), ['provider', 'model']) as $key) {
                $errors["stages.{$stage->value}.{$key}"][] = 'Stage 配置包含未知字段。';
            }
            $provider = $this->normalizedProvider($input['provider'] ?? null);
            $model = is_string($input['model'] ?? null) ? trim($input['model']) : '';
            if (! in_array($provider, $registeredProviders, true)) {
                $errors["stages.{$stage->value}.provider"][] = 'Stage Provider 未在应用中注册。';
            }
            if ($model === '') {
                $errors["stages.{$stage->value}.model"][] = 'Model 不能为空。';
            } elseif (mb_strlen($model) > self::MODEL_MAX_LENGTH) {
                $errors["stages.{$stage->value}.model"][] = 'Model 不能超过 255 个字符。';
            }
            $stages[$stage->value] = ['provider' => $provider, 'model' => $model];
        }

        $cost = $this->normalizeCost($settings['cost'] ?? null, $errors);
        $budget = $this->normalizeBudget($settings['budget'] ?? null, $errors);
        foreach (array_unique([$defaultProvider, ...array_column($stages, 'provider')]) as $provider) {
            if (! in_array($provider, $registeredProviders, true)) {
                continue;
            }
            if (($providers[$provider]['enabled'] ?? false) !== true) {
                $errors["providers.{$provider}.enabled"][] = '生效配置使用的 Provider 必须启用。';
            }
            if ($requireCredentials && ! $this->hasCredential($provider, $providers[$provider]['credential'] ?? null)) {
                $errors["providers.{$provider}.credential"][] = '生效配置使用的 Provider 尚未配置 API Key。';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'default_provider' => $defaultProvider,
            'providers' => $providers,
            'stages' => $stages,
            'cost' => $cost,
            'budget' => $budget,
        ];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $existing @param array<string, array<int, string>> $errors */
    private function credentialForSave(string $provider, array $input, array $existing, array &$errors): ?string
    {
        $apiKey = is_string($input['api_key'] ?? null) ? trim($input['api_key']) : '';
        $clear = filter_var($input['clear_api_key'] ?? false, FILTER_VALIDATE_BOOL);
        if ($clear && $apiKey !== '') {
            $errors["providers.{$provider}.api_key"][] = '替换 API Key 和清除 API Key 不能同时执行。';
        }
        if (mb_strlen($apiKey) > 10_000) {
            $errors["providers.{$provider}.api_key"][] = 'API Key 长度异常。';
        }
        if ($clear) {
            return null;
        }
        if ($apiKey !== '') {
            return Crypt::encryptString($apiKey);
        }
        $credential = data_get($existing, "providers.{$provider}.credential");

        return is_string($credential) && $credential !== '' ? $credential : null;
    }

    /** @param array<string, array<int, string>> $errors @return array<string, mixed> */
    private function normalizeCost(mixed $input, array &$errors): array
    {
        if (! is_array($input)) {
            $errors['cost'][] = '成本配置必须是对象。';
            $input = [];
        }
        foreach (array_diff(array_keys($input), ['currency', 'input_per_million', 'cached_input_per_million', 'output_per_million']) as $key) {
            $errors["cost.{$key}"][] = '成本配置包含未知字段。';
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors['cost.currency'][] = '货币代码必须是三个大写字母。';
        }
        $result = ['currency' => $currency];
        foreach (['input_per_million', 'cached_input_per_million', 'output_per_million'] as $key) {
            $value = $input[$key] ?? null;
            if (! is_numeric($value) || (float) $value < 0) {
                $errors["cost.{$key}"][] = 'Token 单价必须是大于或等于 0 的数字。';
            }
            $result[$key] = is_numeric($value) ? (float) $value : 0.0;
        }

        return $result;
    }

    /** @param array<string, array<int, string>> $errors @return array<string, float|null> */
    private function normalizeBudget(mixed $input, array &$errors): array
    {
        if (! is_array($input)) {
            $errors['budget'][] = '预算配置必须是对象。';
            $input = [];
        }
        foreach (array_diff(array_keys($input), ['daily_hard_limit', 'novel_total_limit', 'chapter_max_cost']) as $key) {
            $errors["budget.{$key}"][] = '预算配置包含未知字段。';
        }
        $result = [];
        foreach (['daily_hard_limit', 'novel_total_limit', 'chapter_max_cost'] as $key) {
            $value = $input[$key] ?? null;
            if (filled($value) && (! is_numeric($value) || (float) $value < 0)) {
                $errors["budget.{$key}"][] = '预算必须留空或填写大于等于 0 的数字。';
            }
            $result[$key] = $this->nullableNonNegative($value);
        }

        return $result;
    }

    private function hasCredential(string $provider, mixed $credential): bool
    {
        return (is_string($credential) && $credential !== '' && $this->decryptable($credential)) || filled(config("ai.providers.{$provider}.api_key"));
    }

    private function decryptable(string $credential): bool
    {
        try {
            Crypt::decryptString($credential);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    private function validBaseUrl(string $url): bool
    {
        return mb_strlen($url) <= self::URL_MAX_LENGTH
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    private function nullableNonNegative(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    private function normalizedProvider(mixed $provider): string
    {
        return is_string($provider) ? strtolower(trim($provider)) : '';
    }

    private function providerConnection(string $provider): ?AIProviderConnection
    {
        if (! Schema::hasTable('ai_provider_connections')) {
            return null;
        }

        return AIProviderConnection::query()->where('provider', $provider)->first();
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function editable(array $settings): array
    {
        foreach ($this->registeredProviders() as $provider) {
            data_forget($settings, "providers.{$provider}.credential");
            data_set($settings, "providers.{$provider}.api_key", null);
            data_set($settings, "providers.{$provider}.clear_api_key", false);
        }

        return $settings;
    }

    /** @return array<string, mixed>|null */
    private function auditSnapshot(mixed $settings): ?array
    {
        if (! is_array($settings)) {
            return null;
        }
        $snapshot = [
            'default_provider' => $settings['default_provider'] ?? null,
            'providers' => [],
            'stages' => [],
            'cost' => $settings['cost'] ?? null,
            'budget' => $settings['budget'] ?? null,
        ];
        foreach ($this->registeredProviders() as $provider) {
            $snapshot['providers'][$provider] = [
                'enabled' => data_get($settings, "providers.{$provider}.enabled"),
                'base_url' => data_get($settings, "providers.{$provider}.base_url"),
                'credential_configured' => filled(data_get($settings, "providers.{$provider}.credential")) || filled(config("ai.providers.{$provider}.api_key")),
                'connect_timeout' => data_get($settings, "providers.{$provider}.connect_timeout"),
                'timeout' => data_get($settings, "providers.{$provider}.timeout"),
            ];
        }
        foreach ($this->stages() as $stage) {
            $snapshot['stages'][$stage->value] = [
                'provider' => data_get($settings, "stages.{$stage->value}.provider"),
                'model' => data_get($settings, "stages.{$stage->value}.model"),
            ];
        }

        return $snapshot;
    }
}
