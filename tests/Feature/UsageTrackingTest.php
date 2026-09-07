<?php

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Providers\EmergencyStopAiProvider;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\UsageRecorder;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException as TestRuntimeException;

uses(RefreshDatabase::class);

test('tracking provider records tokens latency cost request id and run context', function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.cost.input_per_million', 2);
    config()->set('ai.cost.cached_input_per_million', 0.5);
    config()->set('ai.cost.output_per_million', 8);

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
        ->cached_tokens->toBe(200)
        ->latency_ms->toBe(345)
        ->estimated_cost->toBe('0.005700')
        ->request_id->toBe('provider-request-1');
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
    );
}
