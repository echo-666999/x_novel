<?php

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Providers\EmergencyStopAiProvider;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\UsageRecorder;
use App\Models\Chapter;
use App\Models\AIModelPrice;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException as TestRuntimeException;

uses(RefreshDatabase::class);

test('tracking provider records tokens latency cost request id and run context', function () {
    createUsageModelPrice();

    $response = usageResponse();
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $run = GenerationRun::factory()->for($novel)->create([
        'chapter_id' => $chapter->getKey(),
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
    ]);
    $fake = (new FakeAiProvider)->enqueue($response);
    $provider = new TrackingAiProvider($fake, app(UsageRecorder::class));
    $request = new AiRequest(
        model: 'requested-model',
        provider: 'openai',
        prompt: 'Write',
        metadata: [
            'generation_run_id' => $run->getKey(),
            'novel_id' => $novel->getKey(),
            'chapter_id' => $chapter->getKey(),
        ],
    );

    expect($provider->generate($request))->toBe($response);

    $usage = UsageRecord::query()->sole();

    expect($usage)
        ->generation_run_id->toBe($run->getKey())
        ->novel_id->toBe($novel->getKey())
        ->chapter_id->toBe($chapter->getKey())
        ->provider->toBe('openai')
        ->model->toBe('resolved-model')
        ->input_tokens->toBe(1_000)
        ->output_tokens->toBe(500)
        ->reasoning_tokens->toBe(125)
        ->cached_tokens->toBe(200)
        ->latency_ms->toBe(345)
        ->estimated_cost->toBe('0.005700')
        ->request_id->toBe('provider-request-1')
        ->and(data_get($usage->request_metadata, 'finish_reason'))->toBe('length')
        ->and(data_get($usage->request_metadata, 'completion_limit_reason'))->toBe('visible_output_truncated')
        ->and(data_get($usage->request_metadata, 'sent_parameters.max_completion_tokens'))->toBe(500);
});

test('tracking provider calculates cost from database pricing', function () {
    createUsageModelPrice();
    $fake = (new FakeAiProvider)->enqueue(usageResponse());
    $provider = new TrackingAiProvider($fake, app(UsageRecorder::class));

    $provider->generate(new AiRequest(
        model: 'requested-model',
        provider: 'openai',
        prompt: 'Write',
    ));

    expect(UsageRecord::query()->sole()->estimated_cost)->toBe('0.005700');
});

test('usage metadata records the reasoning effort sent to deepseek', function () {
    $provider = new TrackingAiProvider(
        (new FakeAiProvider)->enqueue(usageResponse()),
        app(UsageRecorder::class),
    );

    $provider->generate(new AiRequest(
        model: 'deepseek-chat',
        provider: 'deepseek',
        reasoningEffort: 'high',
        prompt: 'Write',
    ));

    expect(data_get(UsageRecord::query()->sole()->request_metadata, 'reasoning_effort_sent'))->toBe('high');
});

test('failed and fake only provider calls do not create usage records', function () {
    $fake = (new FakeAiProvider)->enqueue(new TestRuntimeException('failed'));
    $provider = new TrackingAiProvider($fake, app(UsageRecorder::class));

    try {
        $provider->generate(new AiRequest(model: 'model', prompt: 'Write'));
    } catch (TestRuntimeException) {
        // The failed call has no token usage response to persist.
    }

    expect(UsageRecord::query()->count())->toBe(0);
});

test('the application provider binding includes usage tracking', function () {
    expect(app(AiProvider::class))->toBeInstanceOf(EmergencyStopAiProvider::class);
});

function usageResponse(): AiResponse
{
    return new AiResponse(
        content: 'Draft',
        structuredData: null,
        inputTokens: 1_000,
        outputTokens: 500,
        cachedTokens: 200,
        latencyMs: 345,
        providerRequestId: 'provider-request-1',
        model: 'resolved-model',
        metadata: [
            'finish_reason' => 'length',
            'completion_limit_reason' => 'visible_output_truncated',
            'sent_parameters' => ['model' => 'resolved-model', 'max_completion_tokens' => 500],
        ],
        reasoningTokens: 125,
    );
}

function createUsageModelPrice(): AIModelPrice
{
    return AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'resolved-model',
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'input_price' => 2,
        'cached_input_price' => 0.5,
        'output_price' => 8,
        'is_enabled' => true,
    ]);
}
