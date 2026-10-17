<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\RequestUsageLedger;
use App\Enums\GenerationStage;
use App\Models\AIModelPrice;
use App\Models\AIProviderConnection;
use App\Services\GenerationRequestContract;
use App\Services\GenerationRunCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** 已核实能力与价格均来自隔离夹具，测试不探测真实供应商。 */
function contractRequest(): AiRequest
{
    createAiProviderConnectionForTest();
    AIModelPrice::create(['provider' => 'openai', 'model' => 'contract-model', 'currency' => 'USD', 'billing_unit' => 1000,
        'input_price' => 1, 'cached_input_price' => 1, 'output_price' => 1, 'context_window_tokens' => 10000,
        'max_output_tokens' => 2000, 'supports_structured_output' => true, 'supports_reasoning_effort' => true, 'is_enabled' => true]);

    return new AiRequest(model: 'contract-model', provider: 'openai', prompt: '完整的创作要求', maxTokens: 500, promptVersion: 'fixture-v1', responseSchema: ['type' => 'object']);
}

test('frozen contract retains price and rejects changed prompt or revoked connection', function () {
    $request = contractRequest();
    $contract = app(GenerationRequestContract::class)->freeze($request, 100);
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '航海悬疑');
    $coordinator = app(GenerationRunCoordinator::class);
    $run = $coordinator->enqueue($task->id, GenerationStage::ChapterWrite, 'chapter:1', $contract);
    $lease = $coordinator->claimExecution($run->id);
    $sent = new AiRequest(model: $request->model, provider: $request->provider, prompt: $request->prompt, maxTokens: 500,
        promptVersion: 'fixture-v1', responseSchema: ['type' => 'object'], metadata: ['generation_run_id' => $run->id, 'lease_token' => $lease['token'], 'control_version' => $lease['control_version']]);
    AIModelPrice::query()->update(['input_price' => 9]);
    $entry = app(RequestUsageLedger::class)->prepare($sent);
    expect((float) $entry->price_snapshot['input_price'])->toBe(1.0);
    expect($run->fresh()->execution_contract['reasoning_reserve_tokens'])->toBe(100);
    expect(fn () => app(GenerationRequestContract::class)->assertMatches($run, new AiRequest(model: 'contract-model', provider: 'openai', prompt: '不同文本')))->toThrow(AiProviderException::class);
    AIProviderConnection::query()->update(['is_enabled' => false]);
    expect(fn () => app(RequestUsageLedger::class)->send($entry))->toThrow(AiProviderException::class);
    expect($entry->fresh()->status)->toBe('prepared');
});

test('capacity checks include schema and reasoning before any usage or provider call', function () {
    $request = contractRequest();
    AIModelPrice::query()->update(['context_window_tokens' => 600]);
    expect(fn () => app(GenerationRequestContract::class)->freeze($request, 100))->toThrow(AiProviderException::class);
    AIModelPrice::query()->update(['context_window_tokens' => 10000, 'supports_structured_output' => false]);
    expect(fn () => app(GenerationRequestContract::class)->freeze($request, 100))->toThrow(AiProviderException::class);
    $this->assertDatabaseCount('usage_records', 0);
});

test('verified capacity with missing prices can freeze in completion first mode', function () {
    $request = contractRequest();
    AIModelPrice::query()->update(['input_price' => null, 'cached_input_price' => null, 'output_price' => null]);
    $contract = app(GenerationRequestContract::class)->freeze($request);
    expect($contract['capacity']['context_window_tokens'])->toBe(10000);
    expect($contract['price']['input_price'])->toBeNull();
    expect(app(RequestUsageLedger::class)->cost($contract['price'], 10, 20, 0))->toBeNull();
});
