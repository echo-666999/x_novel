<?php

use App\AI\Contracts\AiProvider;
use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\EmbeddingRequest;
use App\AI\Exceptions\AiProviderException;
use App\Models\SystemSetting;
use App\Services\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('emergency stop is persisted in postgres and can be released', function () {
    $service = app(EmergencyStopService::class);

    expect($service->isActive())->toBeFalse();
    $service->setActive(true);

    expect($service->isActive())->toBeTrue()
        ->and(SystemSetting::query()->findOrFail(EmergencyStopService::SETTING_KEY)->value)->toBeTrue();

    $service->setActive(false);
    expect($service->isActive())->toBeFalse();
});

test('emergency stop blocks text and embedding provider requests before network access', function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.providers.openai.api_key', 'test-key');
    Http::fake();
    app(EmergencyStopService::class)->setActive(true);

    expect(fn () => app(AiProvider::class)->generate(new AiRequest(model: 'test-model', prompt: 'Ping')))
        ->toThrow(AiProviderException::class, '紧急停止')
        ->and(fn () => app(EmbeddingProvider::class)->embed(new EmbeddingRequest('embedding-model', 'Ping', 3)))
        ->toThrow(AiProviderException::class, '紧急停止');

    Http::assertNothingSent();
});
