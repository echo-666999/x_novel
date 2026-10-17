<?php

use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\FakeEmbeddingProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\Providers\TrackingEmbeddingProvider;
use App\AI\RequestUsageLedger;
use App\AI\UsageRecorder;
use App\Models\AIModelPrice;
use App\Models\SystemSetting;
use App\Models\UsageRecord;
use App\Services\GenerationCostPolicy;
use App\Services\GenerationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** 测试只修改隔离库策略，没有任何真实模型调用。 */
function setR01CostPolicy(array $policy): void
{
    $setting = SystemSetting::findOrFail(SystemSetting::GENERATION);
    $data = $setting->value;
    $data['budget'] = $policy;
    $setting->update(['value' => $data]);
}
function r01Price(): void
{
    AIModelPrice::create(['provider' => 'openai', 'model' => 'fixture-model', 'currency' => 'USD', 'billing_unit' => 1000,
        'input_price' => '1', 'cached_input_price' => '1', 'output_price' => '1', 'is_enabled' => true]);
}
function r01Response(): AiResponse
{
    return new AiResponse('测试正文', null, 5, 5, 0, 10, 'fixture-response', 'fixture-model');
}

test('default generation continues beyond warnings and preserves unknown price', function () {
    setR01CostPolicy(['warning_amount' => 0, 'daily_hard_limit' => 0]);
    $fake = (new FakeAiProvider)->enqueue(r01Response());
    $tracking = new TrackingAiProvider($fake, app(UsageRecorder::class));
    $tracking->generate(new AiRequest(model: 'fixture-model', provider: 'openai', prompt: '小说', maxTokens: 10));
    $entry = UsageRecord::firstOrFail();
    expect($entry->status)->toBe('settled');
    expect($entry->estimated_cost)->toBeNull();
    expect($entry->cost_status)->toBe('unknown');
    expect($fake->requests())->toHaveCount(1);
});

test('explicit cap reserves outstanding requests before another request can enter', function () {
    r01Price();
    setR01CostPolicy(['daily_hard_limit_enabled' => true, 'daily_hard_limit' => '0.50']);
    $request = new AiRequest(model: 'fixture-model', provider: 'openai', prompt: 'x', maxTokens: 100);
    $ledger = app(RequestUsageLedger::class);
    $first = $ledger->prepare($request);
    expect((float) $first->reserved_amount)->toBeGreaterThan(0.1);
    expect(fn () => $ledger->prepare($request))->toThrow(AiProviderException::class);
    $this->assertDatabaseCount('usage_records', 1);
    $ledger->failed($first);
    expect($first->fresh()->status)->toBe('unknown');
    expect($first->fresh()->reserved_amount)->toBe($first->reserved_amount);
});

test('disabling an active cap permits continuation without deleting unknown usage', function () {
    r01Price();
    setR01CostPolicy(['daily_hard_limit_enabled' => true, 'daily_hard_limit' => '0.50']);
    $ledger = app(RequestUsageLedger::class);
    $request = new AiRequest(model: 'fixture-model', provider: 'openai', prompt: 'x', maxTokens: 100);
    $entry = $ledger->prepare($request);
    $ledger->failed($entry);
    setR01CostPolicy(['daily_hard_limit_enabled' => false, 'daily_hard_limit' => '0.50']);
    $next = $ledger->prepare($request);
    expect($next->id)->not->toBe($entry->id);
    expect($entry->fresh()->status)->toBe('unknown');
    $this->assertDatabaseCount('usage_records', 2);
});

test('embedding and requests without a run obey the same explicitly enabled limit', function () {
    setR01CostPolicy(['daily_hard_limit_enabled' => true, 'daily_hard_limit' => '1']);
    $fake = (new FakeEmbeddingProvider)->enqueue(new EmbeddingResponse([0.1], 1, 10, null, 'fixture-model'));
    $tracking = new TrackingEmbeddingProvider($fake, app(UsageRecorder::class));
    expect(fn () => $tracking->embed(new EmbeddingRequest('fixture-model', 'text', 1)))->toThrow(AiProviderException::class);
    expect($fake->requests())->toHaveCount(0);
    $this->assertDatabaseCount('usage_records', 0);
});

test('lost responses remain unknown and successful settlement releases reservation exactly once', function () {
    r01Price();
    $fake = (new FakeAiProvider)->enqueue(new RuntimeException('模拟传输中断'));
    $tracking = new TrackingAiProvider($fake, app(UsageRecorder::class));
    $request = new AiRequest(model: 'fixture-model', provider: 'openai', prompt: 'x', maxTokens: 10);
    expect(fn () => $tracking->generate($request))->toThrow(RuntimeException::class);
    $entry = UsageRecord::firstOrFail();
    expect($entry->status)->toBe('unknown');
    expect($entry->reserved_amount)->not->toBeNull();
    app(UsageRecorder::class)->record($request, r01Response(), $entry);
    app(UsageRecorder::class)->record($request, r01Response(), $entry);
    expect($entry->fresh()->estimated_cost)->toBe('0.01000000');
    expect($entry->fresh()->reserved_amount)->toBeNull();
    $this->assertDatabaseCount('usage_records', 1);
});

test('amount alone never enables a hard limit and an enabled missing amount is rejected', function () {
    $policy = app(GenerationCostPolicy::class)->normalize(['novel_total_limit' => 12]);
    expect($policy['novel_total_limit_enabled'])->toBeFalse();
    expect(fn () => app(GenerationCostPolicy::class)->normalize(['novel_total_limit_enabled' => true]))
        ->toThrow(ValidationException::class);
});

test('a cap changed after preparation is enforced before send and user changes are audited', function () {
    r01Price();
    $ledger = app(RequestUsageLedger::class);
    $entry = $ledger->prepare(new AiRequest(model: 'fixture-model', provider: 'openai', prompt: 'x', maxTokens: 100));
    $settings = app(GenerationSettingsService::class);
    $data = $settings->settings();
    $data['budget']['daily_hard_limit_enabled'] = true;
    $data['budget']['daily_hard_limit'] = '0.01';
    $settings->save($data, null);
    expect(fn () => $ledger->send($entry))->toThrow(AiProviderException::class);
    $ledger->failed($entry, true);
    expect($entry->fresh()->status)->toBe('not_sent');
    expect(SystemSetting::findOrFail(SystemSetting::GENERATION)->policy_history)->toHaveCount(1);
});

test('unknown usage can be reconciled idempotently without erasing request evidence', function () {
    $ledger = app(RequestUsageLedger::class);
    $entry = $ledger->prepare(new AiRequest(model: 'unknown-price', provider: 'openai', prompt: 'x'));
    $ledger->failed($entry);
    $key = (string) Str::uuid();
    $ledger->reconcile($entry->id, $key, '0.25', '根据供应商账单确认', 1);
    $ledger->reconcile($entry->id, $key, '0.25', '根据供应商账单确认', 1);
    expect($entry->fresh()->actual_cost)->toBe('0.25000000');
    expect($entry->fresh()->reconciliation_history)->toHaveCount(1);
    expect($entry->fresh()->request_key)->toBe($entry->request_key);
    expect(fn () => $ledger->reconcile($entry->id, $key, '0', '改口为免费', 1))->toThrow(LogicException::class);
});

test('a successful response without usage remains unknown instead of free', function () {
    r01Price();
    $response = new AiResponse('正文已返回', null, 0, 0, 0, 20, 'missing-usage', 'fixture-model', usageAvailable: false);
    $tracking = new TrackingAiProvider((new FakeAiProvider)->enqueue($response), app(UsageRecorder::class));
    expect($tracking->generate(new AiRequest(model: 'fixture-model', provider: 'openai', prompt: 'x')))->toBe($response);
    $entry = UsageRecord::firstOrFail();
    expect($entry->status)->toBe('unknown')->and($entry->input_tokens)->toBeNull()->and($entry->estimated_cost)->toBeNull();
    expect($entry->reserved_amount)->not->toBeNull();
});
