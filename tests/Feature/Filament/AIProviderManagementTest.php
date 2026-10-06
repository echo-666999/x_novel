<?php

use App\AI\AiCostCalculator;
use App\AI\AiModelRouteService;
use App\AI\AiProviderConnectionTester;
use App\AI\AiSettingsResolver;
use App\AI\AiSettingsService;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Filament\Resources\AIModelPrices\Pages\CreateAIModelPrice;
use App\Filament\Resources\AIModelPrices\Pages\ListAIModelPrices;
use App\Filament\Resources\AIProviderConnections\Pages\CreateAIProviderConnection;
use App\Filament\Resources\AIProviderConnections\Pages\EditAIProviderConnection;
use App\Filament\Resources\AIProviderConnections\Pages\ListAIProviderConnections;
use App\Models\AIModelPrice;
use App\Models\AIModelRoute;
use App\Models\AIProviderConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('ai.cost.currency', 'USD');
});

test('ai and costs navigation exposes only provider connections and model prices', function () {
    Livewire::test(ListAIProviderConnections::class)
        ->assertOk()
        ->assertSee('供应商连接')
        ->assertSee('暂无供应商连接');

    Livewire::test(ListAIModelPrices::class)
        ->assertOk()
        ->assertSee('模型价格')
        ->assertSee('暂无模型价格');
});

test('provider connection encrypts api key and blank edit preserves it', function () {
    Livewire::test(CreateAIProviderConnection::class)
        ->fillForm([
            'provider' => 'openai',
            'name' => 'OpenAI 主连接',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'provider-secret-key',
            'connect_timeout' => 10,
            'timeout' => 60,
            'is_enabled' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $connection = AIProviderConnection::query()->sole();
    $ciphertext = $connection->getRawOriginal('api_key');

    expect($connection->api_key)->toBe('provider-secret-key')
        ->and($ciphertext)->not->toContain('provider-secret-key')
        ->and($connection->toArray())->not->toHaveKey('api_key');

    Livewire::test(EditAIProviderConnection::class, ['record' => $connection->id])
        ->fillForm(['name' => 'OpenAI 备用连接', 'api_key' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($connection->fresh()->name)->toBe('OpenAI 备用连接')
        ->and($connection->fresh()->api_key)->toBe('provider-secret-key')
        ->and($connection->fresh()->getRawOriginal('api_key'))->toBe($ciphertext);
});

test('provider connection form defaults to the long generation request timeout', function () {
    Livewire::test(CreateAIProviderConnection::class)
        ->assertFormSet([
            'connect_timeout' => 10,
            'timeout' => 150,
        ]);
});

test('provider connection is used before environment fallback and can be verified', function () {
    config()->set('ai.providers.openai.api_key', 'environment-key');
    config()->set('ai.providers.openai.base_url', 'https://environment.example/v1');
    $connection = AIProviderConnection::query()->create([
        'provider' => 'openai',
        'name' => 'Database connection',
        'base_url' => 'https://database.example/v1',
        'api_key' => 'database-key',
        'connect_timeout' => 12,
        'timeout' => 70,
        'is_enabled' => true,
    ]);
    Http::fake(['database.example/v1/models' => Http::response(['data' => [['id' => 'gpt-test']]])]);

    $result = app(AiProviderConnectionTester::class)->test($connection);

    expect(app(AiSettingsService::class)->apiKey('openai'))->toBe('database-key')
        ->and(app(AiSettingsService::class)->providerSettings('openai'))->toMatchArray([
            'base_url' => 'https://database.example/v1',
            'connect_timeout' => 12,
            'timeout' => 70,
        ])
        ->and($result)->toBe(['model_count' => 1])
        ->and($connection->fresh()->last_verified_at)->not->toBeNull();
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer database-key'));
});

test('a disabled database connection cannot be selected for runtime use', function () {
    config()->set('ai.providers.openai.api_key', 'environment-key');
    AIProviderConnection::query()->create([
        'provider' => 'openai',
        'name' => 'Disabled connection',
        'base_url' => 'https://database.example/v1',
        'api_key' => 'database-key',
        'connect_timeout' => 10,
        'timeout' => 60,
        'is_enabled' => false,
    ]);

    expect(fn () => app(AiSettingsService::class)->assertProviderAvailable('openai'))
        ->toThrow(AiProviderException::class, 'AI provider [openai] is disabled.');
});

test('model price can be maintained and drives cost calculation', function () {
    Livewire::test(CreateAIModelPrice::class)
        ->fillForm([
            'provider' => 'openai',
            'model' => ' gpt-priced ',
            'currency' => 'USD',
            'billing_unit' => 1_000_000,
            'context_window_tokens' => 200_000,
            'max_output_tokens' => 32_000,
            'supports_structured_output' => true,
            'supports_reasoning_effort' => true,
            'input_price' => '2',
            'cached_input_price' => '0.5',
            'output_price' => '8',
            'is_enabled' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $price = AIModelPrice::query()->sole();
    Livewire::test(ListAIModelPrices::class)->assertCanSeeTableRecords([$price]);

    $cost = app(AiCostCalculator::class)->estimate(new AiResponse(
        content: 'ok',
        structuredData: null,
        inputTokens: 1_000,
        outputTokens: 500,
        cachedTokens: 200,
        latencyMs: 10,
        providerRequestId: 'request',
        model: 'gpt-priced',
    ), 'openai');

    expect($price->input_price)->toBe('2.000000000000')
        ->and($price->model)->toBe('gpt-priced')
        ->and($price->context_window_tokens)->toBe(200_000)
        ->and($price->max_output_tokens)->toBe(32_000)
        ->and($price->supports_structured_output)->toBeTrue()
        ->and($price->supports_reasoning_effort)->toBeTrue()
        ->and($price->cached_input_price)->toBe('0.500000000000')
        ->and($price->output_price)->toBe('8.000000000000')
        ->and($cost)->toBe(0.0057);
});

test('model routes are maintained from the model price page and override environment models', function () {
    AIProviderConnection::query()->create([
        'provider' => 'openai',
        'name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'database-key',
        'connect_timeout' => 10,
        'timeout' => 60,
        'is_enabled' => true,
    ]);
    $price = AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'database-routed-model',
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'context_window_tokens' => 1_050_000,
        'max_output_tokens' => 128_000,
        'supports_structured_output' => true,
        'supports_reasoning_effort' => true,
        'input_price' => 1,
        'cached_input_price' => null,
        'output_price' => 2,
        'is_enabled' => true,
    ]);
    $routes = collect(AiStage::cases())->mapWithKeys(
        fn (AiStage $stage): array => [$stage->value => [
            'model_price_id' => $price->getKey(),
            'reasoning_effort' => match ($stage) {
                AiStage::Planner, AiStage::Reviewer => 'high',
                AiStage::Writer => 'low',
                default => null,
            },
        ]],
    )->all();

    Livewire::test(ListAIModelPrices::class)
        ->assertActionExists('configureModelRoutes')
        ->callAction('configureModelRoutes', data: ['routes' => $routes])
        ->assertHasNoActionErrors();

    expect(AIModelRoute::query()->count())->toBe(count(AiStage::cases()))
        ->and(AIModelRoute::query()->where('role', AiStage::Planner)->firstOrFail()->reasoning_effort?->value)
        ->toBe('high')
        ->and(app(AiSettingsResolver::class)->resolve(AiStage::Writer))
        ->provider->toBe('openai')
        ->model->toBe('database-routed-model')
        ->reasoningEffort->toBe('low')
        ->source->toBe('database')
        ->and(app(AiSettingsResolver::class)->resolve(AiStage::Reviewer)->reasoningEffort)
        ->toBe('high')
        ->and(app(AiSettingsResolver::class)->resolve(AiStage::Embedding)->model)
        ->toBe('database-routed-model');
});

test('saving the four outline fields creates four independent database route records', function () {
    AIProviderConnection::query()->create([
        'provider' => 'openai',
        'name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'database-key',
        'connect_timeout' => 10,
        'timeout' => 60,
        'is_enabled' => true,
    ]);
    $profiles = [
        AiStage::OutlineFoundation->value => ['model' => 'outline-foundation-db', 'reasoning' => 'low'],
        AiStage::OutlineStructure->value => ['model' => 'outline-structure-db', 'reasoning' => 'medium'],
        AiStage::OutlineArcBeats->value => ['model' => 'outline-arc-beats-db', 'reasoning' => 'high'],
        AiStage::OutlineBeatDetail->value => ['model' => 'outline-beat-detail-db', 'reasoning' => null],
    ];
    $defaultPrice = AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'non-outline-default-db',
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'context_window_tokens' => 500_000,
        'max_output_tokens' => 40_000,
        'supports_structured_output' => true,
        'supports_reasoning_effort' => true,
        'input_price' => 1,
        'output_price' => 2,
        'is_enabled' => true,
    ]);
    $prices = collect($profiles)->mapWithKeys(function (array $profile, string $stage): array {
        $price = AIModelPrice::query()->create([
            'provider' => 'openai',
            'model' => $profile['model'],
            'currency' => 'USD',
            'billing_unit' => 1_000_000,
            'context_window_tokens' => 500_000,
            'max_output_tokens' => 40_000,
            'supports_structured_output' => true,
            'supports_reasoning_effort' => true,
            'input_price' => 1,
            'output_price' => 2,
            'is_enabled' => true,
        ]);

        return [$stage => $price];
    });
    $routes = collect(AiStage::cases())->mapWithKeys(
        fn (AiStage $stage): array => [$stage->value => [
            'model_price_id' => $defaultPrice->getKey(),
            'reasoning_effort' => null,
        ]],
    )->all();
    foreach ($profiles as $stage => $profile) {
        $routes[$stage] = [
            'model_price_id' => $prices[$stage]->getKey(),
            'reasoning_effort' => $profile['reasoning'],
        ];
    }

    Livewire::test(ListAIModelPrices::class)
        ->callAction('configureModelRoutes', data: ['routes' => $routes])
        ->assertHasNoActionErrors();

    $outlineRoutes = AIModelRoute::query()
        ->whereIn('role', array_keys($profiles))
        ->get()
        ->keyBy(fn (AIModelRoute $route): string => $route->role->value);
    expect($outlineRoutes)->toHaveCount(4);
    foreach ($profiles as $stage => $profile) {
        expect($outlineRoutes[$stage]->provider)->toBe('openai')
            ->and($outlineRoutes[$stage]->model)->toBe($profile['model'])
            ->and($outlineRoutes[$stage]->reasoning_effort?->value)->toBe($profile['reasoning']);
    }
});

test('route modal keeps invalid outline routes open and shows actionable validation feedback', function () {
    AIProviderConnection::query()->create([
        'provider' => 'openai',
        'name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'database-key',
        'connect_timeout' => 10,
        'timeout' => 60,
        'is_enabled' => true,
    ]);
    $price = AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'outline-capability-not-verified',
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'input_price' => 1,
        'output_price' => 2,
        'is_enabled' => true,
    ]);
    $routes = collect(AiStage::cases())->mapWithKeys(
        fn (AiStage $stage): array => [$stage->value => [
            'model_price_id' => $price->getKey(),
            'reasoning_effort' => $stage === AiStage::Embedding ? null : 'high',
        ]],
    )->all();

    Livewire::test(ListAIModelPrices::class)
        ->callAction('configureModelRoutes', data: ['routes' => $routes])
        ->assertActionMounted('configureModelRoutes')
        ->assertNotified('模型路由未保存')
        ->assertHasActionErrors([
            'routes.outline_foundation.model_price_id',
            'routes.outline_foundation.reasoning_effort',
            'routes.outline_structure.model_price_id',
            'routes.outline_arc_beats.model_price_id',
            'routes.outline_beat_detail.model_price_id',
        ]);

    expect(AIModelRoute::query()->count())->toBe(0);
});

test('outline route fields stay empty when only the planner route exists', function () {
    $price = AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'planner-fallback-model',
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'is_enabled' => true,
    ]);
    AIModelRoute::query()->create([
        'role' => AiStage::Planner,
        'provider' => 'openai',
        'model' => 'planner-fallback-model',
        'reasoning_effort' => 'high',
    ]);

    $state = app(AiModelRouteService::class)->formState();

    foreach ([
        AiStage::OutlineFoundation,
        AiStage::OutlineStructure,
        AiStage::OutlineArcBeats,
        AiStage::OutlineBeatDetail,
    ] as $stage) {
        expect($state[$stage->value])->toBe([
            'model_price_id' => null,
            'reasoning_effort' => null,
        ]);
    }
});

test('model route rejects an unsupported reasoning effort', function () {
    AIProviderConnection::query()->create([
        'provider' => 'openai',
        'name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'database-key',
        'connect_timeout' => 10,
        'timeout' => 60,
        'is_enabled' => true,
    ]);
    $price = AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'reasoning-model',
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'context_window_tokens' => 1_050_000,
        'max_output_tokens' => 128_000,
        'supports_structured_output' => true,
        'supports_reasoning_effort' => true,
        'input_price' => 1,
        'output_price' => 2,
        'is_enabled' => true,
    ]);
    $routes = collect(AiStage::cases())->mapWithKeys(
        fn (AiStage $stage): array => [$stage->value => [
            'model_price_id' => $price->getKey(),
            'reasoning_effort' => $stage === AiStage::Writer ? 'extreme' : null,
        ]],
    )->all();

    try {
        app(AiModelRouteService::class)->save($routes, auth()->id());
        $this->fail('Expected invalid reasoning effort to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('routes.writer.reasoning_effort')
            ->and(AIModelRoute::query()->count())->toBe(0);
    }
});

test('outline routes reject models without verified capacity or required capabilities', function () {
    AIProviderConnection::query()->create([
        'provider' => 'openai',
        'name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'database-key',
        'connect_timeout' => 10,
        'timeout' => 60,
        'is_enabled' => true,
    ]);
    $price = AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'unverified-outline-model',
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'input_price' => 1,
        'output_price' => 2,
        'is_enabled' => true,
    ]);
    $routes = collect(AiStage::cases())->mapWithKeys(
        fn (AiStage $stage): array => [$stage->value => [
            'model_price_id' => $price->getKey(),
            'reasoning_effort' => $stage === AiStage::OutlineFoundation ? 'high' : null,
        ]],
    )->all();

    try {
        app(AiModelRouteService::class)->save($routes, auth()->id());
        $this->fail('Expected an unsuitable Outline model to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())
            ->toHaveKeys([
                'routes.outline_foundation.model_price_id',
                'routes.outline_foundation.reasoning_effort',
                'routes.outline_structure.model_price_id',
                'routes.outline_arc_beats.model_price_id',
                'routes.outline_beat_detail.model_price_id',
            ])
            ->and(AIModelRoute::query()->count())->toBe(0);
    }
});
