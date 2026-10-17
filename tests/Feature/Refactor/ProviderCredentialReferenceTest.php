<?php

use App\AI\AiSettingsService;
use App\AI\Exceptions\AiProviderException;
use App\Models\AIProviderConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// 测试密钥只存在进程配置；数据库不得得到明文或密文副本。
test('provider resolves only allowlisted environment reference and blocks disabled connections', function () {
    config(['ai.credentials.openai' => 'fixture-not-a-real-key']);
    $connection = AIProviderConnection::create(['provider' => 'openai', 'name' => '测试连接', 'base_url' => 'https://example.invalid',
        'credential_ref' => 'openai', 'connect_timeout' => 10, 'timeout' => 150, 'is_enabled' => true]);
    $service = app(AiSettingsService::class);
    expect($service->apiKey('openai'))->toBe('fixture-not-a-real-key');
    expect($connection->getRawOriginal('api_key'))->toBeNull();
    expect(fn () => $connection->forceFill(['api_key' => 'forbidden']))->toThrow(LogicException::class);
    $connection->update(['credential_ref' => 'APP_KEY']);
    expect($service->apiKey('openai'))->toBe('');
    $connection->update(['credential_ref' => 'openai', 'is_enabled' => false]);
    expect(fn () => $service->assertProviderAvailable('openai'))->toThrow(AiProviderException::class);
});
