<?php

use App\AI\AiSettingsResolver;
use App\AI\AiSettingsService;
use App\AI\Data\AiRequest;
use App\AI\Providers\OpenAiProvider;
use App\Enums\AiStage;
use App\Models\AIModelRoute;
use App\Models\Novel;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.model', 'environment-default-model');
    config()->set('ai.providers.openai.api_key', 'resolver-secret-key');
    config()->set('ai.providers.openai.connect_timeout', 10);
    config()->set('ai.providers.openai.timeout', 60);

    foreach (AiStage::cases() as $stage) {
        config()->set("ai.models.{$stage->value}", "environment-{$stage->value}-model");
    }
    config()->set('ai.embedding.model', 'environment-embedding-model');
});

test('missing ai database settings preserve the environment behavior', function () {
    $resolver = app(AiSettingsResolver::class);

    foreach (AiStage::cases() as $stage) {
        $resolved = $resolver->resolve($stage);

        expect($resolved->stage)->toBe($stage)
            ->and($resolved->provider)->toBe('openai')
            ->and($resolved->model)->toBe("environment-{$stage->value}-model")
            ->and($resolved->source)->toBe('environment');
    }
});

test('database stage settings override environment defaults', function () {
    $settings = app(AiSettingsService::class)->defaults();
    $settings['stages']['writer']['model'] = ' database-writer-model ';
    $settings['providers']['openai']['connect_timeout'] = 12;
    $settings['providers']['openai']['timeout'] = 70;

    app(AiSettingsService::class)->save($settings, 7);

    $resolved = app(AiSettingsResolver::class)->resolve(AiStage::Writer);

    expect($resolved->provider)->toBe('openai')
        ->and($resolved->model)->toBe('database-writer-model')
        ->and($resolved->source)->toBe('database')
        ->and(app(AiSettingsService::class)->providerSettings('openai'))->toMatchArray([
            'connect_timeout' => 12,
            'timeout' => 70,
        ]);
});

test('database model routes are normalized before provider requests', function () {
    AIModelRoute::query()->create([
        'role' => AiStage::Planner,
        'provider' => ' openai ',
        'model' => ' gpt-5.6-terra ',
        'reasoning_effort' => 'high',
    ]);

    expect(app(AiSettingsResolver::class)->resolve(AiStage::Planner))
        ->provider->toBe('openai')
        ->model->toBe('gpt-5.6-terra')
        ->reasoningEffort->toBe('high')
        ->source->toBe('database');
});

test('novel stage overrides affect only that novel and stage', function () {
    $settings = app(AiSettingsService::class)->defaults();
    $settings['stages']['writer']['model'] = 'database-writer-model';
    app(AiSettingsService::class)->save($settings, 7);

    $overriddenNovel = Novel::factory()->create([
        'settings' => ['ai' => ['models' => ['writer' => ' novel-writer-model ']]],
    ]);
    $otherNovel = Novel::factory()->create();
    $resolver = app(AiSettingsResolver::class);

    expect($resolver->resolve(AiStage::Writer, $overriddenNovel))
        ->provider->toBe('openai')
        ->model->toBe('novel-writer-model')
        ->source->toBe('novel')
        ->and($resolver->resolve(AiStage::Reviewer, $overriddenNovel)->source)->toBe('database')
        ->and($resolver->resolve(AiStage::Writer, $otherNovel)->model)->toBe('database-writer-model')
        ->and($resolver->resolve(AiStage::Writer, $otherNovel)->source)->toBe('database');
});

test('novel stage provider and model overrides use the structured stage path', function () {
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['stages' => ['writer' => [
            'provider' => ' openai ',
            'model' => ' novel-stage-writer ',
        ]]]],
    ]);

    expect(app(AiSettingsResolver::class)->resolve(AiStage::Writer, $novel))
        ->provider->toBe('openai')
        ->model->toBe('novel-stage-writer')
        ->source->toBe('novel');
});

test('invalid stored ai settings fall back to environment defaults', function () {
    SystemSetting::query()->create([
        'key' => SystemSetting::AI,
        'value' => ['schema_version' => 999, 'default_provider' => 'unknown'],
    ]);

    $resolved = app(AiSettingsResolver::class)->resolve(AiStage::Writer);

    expect($resolved->model)->toBe('environment-writer-model')
        ->and($resolved->provider)->toBe('openai')
        ->and($resolved->source)->toBe('environment');
});

test('invalid settings are rejected without replacing the stored configuration', function (Closure $mutate, string $errorKey) {
    $service = app(AiSettingsService::class);
    $valid = $service->defaults();
    $service->save($valid, 7);
    $before = SystemSetting::query()->findOrFail(SystemSetting::AI)->value;
    $invalid = $mutate($valid);

    try {
        $service->save($invalid, 7);
        $this->fail('Expected ValidationException was not thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey)
            ->and(SystemSetting::query()->findOrFail(SystemSetting::AI)->value)->toBe($before);
    }
})->with([
    'unknown provider' => [function (array $settings): array {
        $settings['default_provider'] = 'unknown';

        return $settings;
    }, 'default_provider'],
    'unknown stage' => [function (array $settings): array {
        $settings['stages']['unknown'] = ['provider' => 'openai', 'model' => 'model'];

        return $settings;
    }, 'stages.unknown'],
    'empty model' => [function (array $settings): array {
        $settings['stages']['writer']['model'] = '   ';

        return $settings;
    }, 'stages.writer.model'],
    'invalid timeout' => [function (array $settings): array {
        $settings['providers']['openai']['timeout'] = 0;

        return $settings;
    }, 'providers.openai.timeout'],
    'disabled provider' => [function (array $settings): array {
        $settings['providers']['openai']['enabled'] = false;

        return $settings;
    }, 'providers.openai.enabled'],
    'invalid base url' => [function (array $settings): array {
        $settings['providers']['openai']['base_url'] = 'not-a-url';

        return $settings;
    }, 'providers.openai.base_url'],
]);

test('a provider without an api key cannot be saved as effective', function () {
    config()->set('ai.providers.openai.api_key', null);
    $service = app(AiSettingsService::class);

    expect(fn () => $service->save($service->defaults(), 7))
        ->toThrow(ValidationException::class);

    expect(SystemSetting::query()->whereKey(SystemSetting::AI)->exists())->toBeFalse();
});

test('a stored provider becomes unusable when its api key is removed', function () {
    $service = app(AiSettingsService::class);
    $service->save($service->defaults(), 7);
    config()->set('ai.providers.openai.api_key', null);

    expect(fn () => app(AiSettingsResolver::class)->resolve(AiStage::Writer))
        ->toThrow(InvalidArgumentException::class, 'AI provider [openai] has no configured API key.');
});

test('api keys are encrypted in system settings and blank submissions preserve them', function () {
    config()->set('ai.providers.openai.api_key', null);
    $service = app(AiSettingsService::class);
    $settings = $service->editableSettings();
    $settings['providers']['openai']['api_key'] = 'database-secret-key';

    $first = $service->save($settings, 7);
    $stored = SystemSetting::query()->findOrFail(SystemSetting::AI)->value;
    $credential = data_get($stored, 'providers.openai.credential');

    expect($credential)->toBeString()
        ->not->toBe('database-secret-key')
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain('database-secret-key')
        ->and($service->apiKey('openai'))->toBe('database-secret-key')
        ->and(data_get($first, 'settings.providers.openai.api_key'))->toBeNull()
        ->and(data_get($first, 'settings.providers.openai.credential'))->toBeNull();

    $second = $service->save($first['settings'], 7);

    expect($second['changed'])->toBeFalse()
        ->and(data_get(SystemSetting::query()->findOrFail(SystemSetting::AI)->value, 'providers.openai.credential'))->toBe($credential);
});

test('an api key can be cleared explicitly and then falls back to the environment', function () {
    config()->set('ai.providers.openai.api_key', 'environment-fallback-key');
    $service = app(AiSettingsService::class);
    $settings = $service->editableSettings();
    $settings['providers']['openai']['api_key'] = 'database-key-to-clear';
    $saved = $service->save($settings, 7);
    $saved['settings']['providers']['openai']['clear_api_key'] = true;

    $service->save($saved['settings'], 7);

    expect(data_get(SystemSetting::query()->findOrFail(SystemSetting::AI)->value, 'providers.openai.credential'))->toBeNull()
        ->and($service->apiKey('openai'))->toBe('environment-fallback-key');
});

test('database cost and budget settings override environment defaults', function () {
    $service = app(AiSettingsService::class);
    $settings = $service->editableSettings();
    $settings['cost'] = [
        'currency' => 'CNY',
        'input_per_million' => 2.5,
        'cached_input_per_million' => 0.5,
        'output_per_million' => 8.0,
    ];
    $settings['budget'] = [
        'daily_hard_limit' => 12,
        'novel_total_limit' => 30,
        'chapter_max_cost' => 3,
    ];

    $service->save($settings, 7);

    expect($service->costSettings())->toBe($settings['cost'])
        ->and($service->budgetSettings())->toBe([
            'daily_hard_limit' => 12.0,
            'novel_total_limit' => 30.0,
            'chapter_max_cost' => 3.0,
        ]);
});

test('openai provider uses the database base url and encrypted api key', function () {
    config()->set('ai.providers.openai.api_key', null);
    $service = app(AiSettingsService::class);
    $settings = $service->editableSettings();
    $settings['providers']['openai']['base_url'] = 'https://database-llm.example/v1';
    $settings['providers']['openai']['api_key'] = 'database-provider-key';
    $service->save($settings, 7);
    Http::fake(['database-llm.example/*' => Http::response([
        'id' => 'database-provider-request',
        'model' => 'resolved-model',
        'choices' => [['message' => ['content' => 'OK']]],
        'usage' => [],
    ])]);

    app(OpenAiProvider::class)->generate(new AiRequest(model: 'requested-model', prompt: 'Ping'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://database-llm.example/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer database-provider-key'));
});

test('identical saves do not update or log the setting twice', function () {
    $records = [];
    Log::shouldReceive('info')
        ->once()
        ->andReturnUsing(function (string $message, array $context) use (&$records): void {
            $records[] = compact('message', 'context');
        });

    $service = app(AiSettingsService::class);
    $settings = $service->defaults();
    $settings['stages']['writer']['model'] = '  saved-writer-model  ';
    $first = $service->save($settings, 42);
    $updatedAt = SystemSetting::query()->findOrFail(SystemSetting::AI)->updated_at;
    $second = $service->save($first['settings'], 42);

    expect($first['changed'])->toBeTrue()
        ->and($second['changed'])->toBeFalse()
        ->and($second['settings']['stages']['writer']['model'])->toBe('saved-writer-model')
        ->and(SystemSetting::query()->findOrFail(SystemSetting::AI)->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($records)->toHaveCount(1)
        ->and(json_encode($records, JSON_THROW_ON_ERROR))->not->toContain('resolver-secret-key')
        ->and(json_encode($records, JSON_THROW_ON_ERROR))->not->toContain('api_key');
});

test('deleting the ai setting restores environment defaults', function () {
    $service = app(AiSettingsService::class);
    $settings = $service->defaults();
    $settings['stages']['writer']['model'] = 'database-writer-model';
    $service->save($settings, 7);
    SystemSetting::query()->whereKey(SystemSetting::AI)->delete();

    expect(app(AiSettingsResolver::class)->resolve(AiStage::Writer))
        ->model->toBe('environment-writer-model')
        ->source->toBe('environment');
});

test('unknown ai stages are rejected', function () {
    app(AiSettingsResolver::class)->resolve('unknown');
})->throws(InvalidArgumentException::class, 'Unsupported AI stage.');
