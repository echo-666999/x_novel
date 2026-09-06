<?php

use App\AI\BudgetService;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\BudgetExceededException;
use App\AI\Providers\BudgetGuardAiProvider;
use App\AI\Providers\FakeAiProvider;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException as TestInvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.budget.daily_hard_limit', null);
    config()->set('ai.budget.novel_total_limit', null);
    config()->set('ai.budget.chapter_max_cost', null);
});

test('daily hard limit blocks a new provider request before it is sent', function () {
    config()->set('ai.budget.daily_hard_limit', 1.5);
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

test('novel override limit takes precedence over the global novel limit', function () {
    config()->set('ai.budget.novel_total_limit', 10);
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
    config()->set('ai.budget.chapter_max_cost', 3);
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

test('a request below all limits reaches the provider', function () {
    config()->set('ai.budget.daily_hard_limit', 10);
    config()->set('ai.budget.novel_total_limit', 5);
    config()->set('ai.budget.chapter_max_cost', 2);
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
