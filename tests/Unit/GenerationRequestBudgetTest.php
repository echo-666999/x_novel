<?php

use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\Enums\AiStage;
use App\Models\GenerationRun;
use App\Services\GenerationRequestBudget;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

function frozenBudgetRun(string $errorCode, string $tier, array $budget): GenerationRun
{
    return (new GenerationRun)->forceFill([
        'error_code' => $errorCode,
        'context_snapshot' => [
            'generation_preferences' => [
                'request_budget_tier' => $tier,
                'selected_request_budget' => $budget,
                'max_completion_tokens' => $budget['max_completion_tokens'],
            ],
        ],
    ]);
}

test('repair budget config is normalized into independent completion contracts', function () {
    config()->set('generation.chapter_repair_request_budgets.reviewer.coverage_judgment', [
        'initial' => ['output_tokens' => 1_500, 'reasoning_reserve_tokens' => 8_000],
        'retry' => ['output_tokens' => 3_000, 'reasoning_reserve_tokens' => 16_000],
    ]);

    $budgets = (new GenerationRequestBudget)->configuredRepairs(AiStage::Reviewer);

    expect(data_get($budgets, 'coverage_judgment.initial'))->toBe([
        'output_tokens' => 1_500,
        'reasoning_reserve_tokens' => 8_000,
        'max_completion_tokens' => 9_500,
    ])->and(data_get($budgets, 'coverage_judgment.retry.max_completion_tokens'))->toBe(19_000);
});

test('repair budget config rejects a retry tier that lowers reasoning reserve', function () {
    config()->set('generation.chapter_repair_request_budgets.reviewer.coverage_judgment', [
        'initial' => ['output_tokens' => 1_500, 'reasoning_reserve_tokens' => 8_000],
        'retry' => ['output_tokens' => 3_000, 'reasoning_reserve_tokens' => 4_000],
    ]);

    expect(fn () => (new GenerationRequestBudget)->configuredRepairs(AiStage::Reviewer))
        ->toThrow(ValidationException::class, '推理预留都不能低于前一档预算');
});

test('reasoning exhaustion skips tiers that do not increase the reasoning reserve', function () {
    $budgets = [
        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 100, 'max_completion_tokens' => 1_100],
        'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 100, 'max_completion_tokens' => 2_100],
        'final' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 300, 'max_completion_tokens' => 2_300],
    ];
    $prior = frozenBudgetRun('plan_reasoning_budget_exhausted', 'initial', $budgets['initial']);

    $selection = (new GenerationRequestBudget)->resolveForAttempt(
        $budgets,
        AiStage::Planner,
        [$prior],
        'plan',
        'Chapter Plan',
    );

    expect($selection['tier'])->toBe('final')
        ->and($selection['trigger'])->toBe('reasoning_budget_exhausted')
        ->and($selection['budget']['reasoning_reserve_tokens'])->toBe(300);
});

test('unclassified completion exhaustion upgrades by total completion budget', function () {
    $budgets = [
        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 100, 'max_completion_tokens' => 1_100],
        'retry' => ['output_tokens' => 1_100, 'reasoning_reserve_tokens' => 100, 'max_completion_tokens' => 1_200],
    ];
    $prior = frozenBudgetRun('summary_completion_budget_exhausted', 'initial', $budgets['initial']);

    $selection = (new GenerationRequestBudget)->resolveForAttempt(
        $budgets,
        AiStage::Summary,
        [$prior],
        'summary',
        'Canonical Chapter Summary',
    );

    expect($selection['tier'])->toBe('retry')
        ->and($selection['trigger'])->toBe('completion_budget_exhausted')
        ->and($selection['budget']['max_completion_tokens'])->toBe(1_200);
});

test('completion failures become terminal when the matching frozen budget cannot increase', function () {
    $budget = ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 100, 'max_completion_tokens' => 1_100];
    $exception = new AiProviderException(
        'review_output_truncated',
        'Visible output truncated.',
        false,
    );

    $classified = (new GenerationRequestBudget)->classifyRetry(
        $exception,
        ['initial' => $budget],
        AiStage::Reviewer,
        $budget,
        'review',
        'Narrative Review',
        'initial',
    );

    expect($classified->errorCode)->toBe('review_output_truncated')
        ->and($classified->retryable)->toBeFalse()
        ->and($classified->getMessage())->toContain('最高可见输出额度');
});

test('partial content is classified as reasoning exhaustion when reasoning exceeds the frozen reserve', function () {
    $budgets = [
        'initial' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 8_000, 'max_completion_tokens' => 12_000],
        'retry' => ['output_tokens' => 8_000, 'reasoning_reserve_tokens' => 16_000, 'max_completion_tokens' => 24_000],
    ];
    $providerException = new AiProviderException(
        'event_output_truncated',
        'Provider 根据少量可见内容判断为输出截断。',
        false,
    );
    $response = new AiResponse(
        content: '{"events":[',
        structuredData: null,
        inputTokens: 10_002,
        outputTokens: 12_000,
        cachedTokens: 0,
        latencyMs: 90_000,
        providerRequestId: 'reasoning-dominated-request',
        model: 'deepseek-flash',
        metadata: [
            'finish_reason' => 'length',
            'completion_limit_reason' => 'visible_output_truncated',
        ],
        reasoningTokens: 11_700,
    );

    $classified = (new GenerationRequestBudget)->classifyRetry(
        $providerException,
        $budgets,
        AiStage::Extractor,
        $budgets['initial'],
        'event',
        'Story Event Extraction',
        'initial',
        $response,
    );

    expect($classified->errorCode)->toBe('event_reasoning_budget_exhausted')
        ->and($classified->retryable)->toBeTrue()
        ->and($classified->getMessage())->toContain('实际推理消耗 11700 Token')
        ->and($classified->getMessage())->toContain('仅留下约 300 Token 可见输出');
});

test('all chapter provider stages expose normalized monotonic request budgets', function (AiStage $stage, array $expectedTiers) {
    $budgets = (new GenerationRequestBudget)->configured($stage);

    expect(array_keys($budgets))->toBe($expectedTiers);

    $previousOutput = 0;
    $previousReserve = 0;
    foreach ($budgets as $budget) {
        // 每个冻结档都必须能独立解释可见输出、隐藏推理和 Provider 实际完成上限。
        expect($budget['max_completion_tokens'])
            ->toBe($budget['output_tokens'] + $budget['reasoning_reserve_tokens'])
            ->and($budget['output_tokens'])->toBeGreaterThanOrEqual($previousOutput)
            ->and($budget['reasoning_reserve_tokens'])->toBeGreaterThanOrEqual($previousReserve);
        $previousOutput = $budget['output_tokens'];
        $previousReserve = $budget['reasoning_reserve_tokens'];
    }
})->with([
    'planner' => [AiStage::Planner, ['initial', 'retry', 'final']],
    'writer' => [AiStage::Writer, ['initial', 'retry', 'final']],
    'extractor' => [AiStage::Extractor, ['initial', 'retry', 'final']],
    'reviewer' => [AiStage::Reviewer, ['initial']],
    'rewrite' => [AiStage::Rewrite, ['initial', 'retry', 'final']],
    'summary' => [AiStage::Summary, ['initial', 'retry', 'final']],
]);

test('visible truncation skips tiers that only increase reasoning reserve', function () {
    $budgets = [
        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 100, 'max_completion_tokens' => 1_100],
        'retry' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 300, 'max_completion_tokens' => 1_300],
        'final' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 300, 'max_completion_tokens' => 2_300],
    ];
    $prior = frozenBudgetRun('scene_output_truncated', 'initial', $budgets['initial']);

    $selection = (new GenerationRequestBudget)->resolveForAttempt(
        $budgets,
        AiStage::Writer,
        [$prior],
        'scene',
        'Scene Draft',
    );

    expect($selection['tier'])->toBe('final')
        ->and($selection['trigger'])->toBe('visible_output_truncated')
        ->and($selection['budget']['output_tokens'])->toBe(2_000);
});

test('temporary provider failures keep the initial frozen budget instead of upgrading by attempt count', function () {
    $budgets = [
        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 100, 'max_completion_tokens' => 1_100],
        'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 200, 'max_completion_tokens' => 2_200],
    ];
    $prior = frozenBudgetRun('provider_timeout', 'initial', $budgets['initial']);

    $selection = (new GenerationRequestBudget)->resolveForAttempt(
        $budgets,
        AiStage::Writer,
        [$prior],
        'scene',
        'Scene Draft',
    );

    expect($selection['tier'])->toBe('initial')
        ->and($selection['trigger'])->toBeNull()
        ->and($selection['budget'])->toBe($budgets['initial']);
});

test('configured chapter budgets reject a decreasing output or reasoning tier', function () {
    config()->set('generation.chapter_request_budgets.writer', [
        'initial' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 200],
        'retry' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 100],
    ]);

    expect(fn () => (new GenerationRequestBudget)->configured(AiStage::Writer))
        ->toThrow(ValidationException::class, '不能低于前一档预算');
});
