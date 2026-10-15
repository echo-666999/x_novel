<?php

use App\AI\BudgetService;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\BudgetExceededException;
use App\AI\Providers\BudgetGuardAiProvider;
use App\AI\Providers\FakeAiProvider;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\UsageRecord;
use App\Services\GenerationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException as TestInvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    setGlobalBudgetsForTest();
});

test('daily hard limit blocks a new provider request before it is sent', function () {
    setGlobalBudgetsForTest(daily: 1.5);
    UsageRecord::factory()->create(['estimated_cost' => 1.5, 'created_at' => now()]);
    $fake = (new FakeAiProvider)->enqueue(budgetResponse());
    $provider = new BudgetGuardAiProvider($fake, app(BudgetService::class));

    try {
        $provider->generate(new AiRequest(model: 'model', prompt: 'Write'));
        $this->fail('Expected BudgetExceededException was not thrown.');
    } catch (BudgetExceededException $exception) {
        expect($exception->errorCode)->toBe('budget_daily_hard_limit')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->used)->toBe(1.5)
            ->and($exception->limit)->toBe(1.5);
    }

    expect($fake->requests())->toBe([]);
});

test('daily hard limit is read from database generation settings', function () {
    setGlobalBudgetsForTest(daily: 1.5);
    UsageRecord::factory()->create(['estimated_cost' => 1.5, 'created_at' => now()]);

    expect(fn () => app(BudgetService::class)->assertCanRequest(new AiRequest(model: 'model')))
        ->toThrow(BudgetExceededException::class, 'AI daily hard limit 已达到。');
});

test('novel override limit takes precedence over the global novel limit', function () {
    setGlobalBudgetsForTest(novel: 10);
    $novel = Novel::factory()->create([
        'settings' => ['budget' => ['novel_total_limit' => 2]],
    ]);
    UsageRecord::factory()->create([
        'novel_id' => $novel->getKey(),
        'estimated_cost' => 2,
    ]);

    $usage = app(BudgetService::class)->novelUsage($novel);

    expect($usage->used)->toBe(2.0)
        ->and($usage->limit)->toBe(2.0)
        ->and($usage->reached())->toBeTrue();
});

test('chapter hard limit only counts usage for the requested chapter', function () {
    setGlobalBudgetsForTest(chapter: 3);
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $otherChapter = Chapter::factory()->for($novel)->create();
    UsageRecord::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'estimated_cost' => 3,
    ]);
    UsageRecord::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => $otherChapter->getKey(),
        'estimated_cost' => 9,
    ]);

    expect(fn () => app(BudgetService::class)->assertCanRequest(new AiRequest(
        model: 'model',
        metadata: [
            'novel_id' => $novel->getKey(),
            'chapter_id' => $chapter->getKey(),
        ],
    )))->toThrow(BudgetExceededException::class, 'AI chapter hard limit 已达到。');
});

test('chapter limit preflight can guard a queued follow up stage', function () {
    setGlobalBudgetsForTest(chapter: 3);
    $chapter = Chapter::factory()->create();
    UsageRecord::factory()->create([
        'novel_id' => $chapter->novel_id,
        'chapter_id' => $chapter->getKey(),
        'estimated_cost' => 3,
    ]);

    expect(fn () => app(BudgetService::class)->assertWithinChapterLimits($chapter))
        ->toThrow(BudgetExceededException::class, 'AI chapter hard limit 已达到。');
});

test('a request below all limits reaches the provider', function () {
    setGlobalBudgetsForTest(daily: 10, novel: 5, chapter: 2);
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $response = budgetResponse();
    $fake = (new FakeAiProvider)->enqueue($response);
    $provider = new BudgetGuardAiProvider($fake, app(BudgetService::class));
    $request = new AiRequest(model: 'model', metadata: [
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
    ]);

    expect($provider->generate($request))->toBe($response)
        ->and($fake->requests())->toBe([$request]);
});

test('projected maximum request cost is reserved before provider execution', function () {
    setGlobalBudgetsForTest(daily: 1.5, novel: 1.5, chapter: 1.5);
    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create(['status' => 'running']);
    AIModelPrice::query()->create([
        'provider' => 'openai', 'model' => 'priced-model', 'currency' => 'USD', 'billing_unit' => 1,
        'context_window_tokens' => 100_000, 'max_output_tokens' => 10_000,
        'supports_structured_output' => true, 'supports_reasoning_effort' => true,
        'input_price' => 0, 'cached_input_price' => 0, 'output_price' => 1,
        'is_enabled' => true,
    ]);
    $fake = (new FakeAiProvider)->enqueue(budgetResponse());
    $provider = new BudgetGuardAiProvider($fake, app(BudgetService::class));

    expect(fn () => $provider->generate(new AiRequest(
        model: 'priced-model',
        provider: 'openai',
        prompt: '请求不会发出',
        maxTokens: 2,
        metadata: ['generation_run_id' => $run->getKey(), 'novel_id' => $novel->getKey(), 'chapter_id' => $chapter->getKey()],
    )))->toThrow(BudgetExceededException::class);

    expect($fake->requests())->toBe([])
        ->and((float) $run->fresh()->reserved_cost)->toBe(0.0);
});

test('mismatched novel and chapter context is rejected before the provider call', function () {
    $novel = Novel::factory()->create();
    $foreignChapter = Chapter::factory()->create();

    app(BudgetService::class)->assertCanRequest(new AiRequest(model: 'model', metadata: [
        'novel_id' => $novel->getKey(),
        'chapter_id' => $foreignChapter->getKey(),
    ]));
})->throws(TestInvalidArgumentException::class, 'AI request Chapter does not belong to the Novel context.');

function budgetResponse(): AiResponse
{
    return new AiResponse(
        content: 'OK',
        structuredData: null,
        inputTokens: 10,
        outputTokens: 10,
        cachedTokens: 0,
        latencyMs: 10,
        providerRequestId: 'budget-test',
        model: 'model',
    );
}

function setGlobalBudgetsForTest(?float $daily = null, ?float $novel = null, ?float $chapter = null): void
{
    $service = app(GenerationSettingsService::class);
    $settings = $service->settings();
    $settings['budget'] = [
        'daily_hard_limit' => $daily,
        'novel_total_limit' => $novel,
        'chapter_max_cost' => $chapter,
    ];
    $service->save($settings, null);
}
