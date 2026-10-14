<?php

use App\Enums\AiStage;
use App\Models\SystemSetting;
use App\Services\GenerationRequestBudget;
use App\Services\GenerationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('database bootstrap settings reserve reasoning capacity for reasoning stages', function () {
    $settings = app(GenerationSettingsService::class)->settings();

    expect(data_get($settings, 'outline_request_budgets.outline_foundation'))->toBe([
        'output_tokens' => 12_000,
        'reasoning_reserve_tokens' => 8_000,
    ])->and(data_get($settings, 'chapter_request_budgets.planner'))->toBe([
        'initial' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 4_000],
        'retry' => ['output_tokens' => 16_000, 'reasoning_reserve_tokens' => 8_000],
        'final' => ['output_tokens' => 24_000, 'reasoning_reserve_tokens' => 16_000],
    ])->and(data_get($settings, 'chapter_request_budgets.extractor'))->toBe([
        'initial' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 32_000],
        'retry' => ['output_tokens' => 8_000, 'reasoning_reserve_tokens' => 40_000],
        'final' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 48_000],
    ])->and(data_get($settings, 'chapter_request_budgets.reviewer.initial.reasoning_reserve_tokens'))->toBe(8_000)
        ->and(data_get($settings, 'chapter_request_budgets.rewrite'))->toBe([
            'initial' => ['output_tokens' => 16_000, 'reasoning_reserve_tokens' => 8_000],
            'retry' => ['output_tokens' => 20_000, 'reasoning_reserve_tokens' => 16_000],
            'final' => ['output_tokens' => 24_000, 'reasoning_reserve_tokens' => 24_000],
        ])->and(data_get($settings, 'chapter_repair_request_budgets.extractor.coverage_evidence'))->toBe([
            'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 16_000],
            'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 24_000],
        ]);
});

test('database generation settings drive new runtime request budgets', function () {
    $service = app(GenerationSettingsService::class);
    $settings = $service->settings();
    $settings['chapter_request_budgets']['writer']['initial'] = [
        'output_tokens' => 13_000,
        'reasoning_reserve_tokens' => 9_000,
    ];
    $settings['review_context_token_budget'] = 52_000;
    $service->save($settings, 7);

    $writer = app(GenerationRequestBudget::class)->configured(AiStage::Writer);

    expect($service->current()['source'])->toBe('database')
        ->and($service->reviewContextTokenBudget())->toBe(52_000)
        ->and($writer['initial'])->toBe([
            'output_tokens' => 13_000,
            'reasoning_reserve_tokens' => 9_000,
            'max_completion_tokens' => 22_000,
        ]);
});

test('generation settings reject lower retry tiers without replacing stored values', function () {
    $service = app(GenerationSettingsService::class);
    $service->save($service->settings(), 7);
    $before = $service->settings();
    $invalid = $before;
    $invalid['chapter_request_budgets']['writer']['retry']['output_tokens'] = 1;

    expect(fn () => $service->save($invalid, 7))
        ->toThrow(ValidationException::class, '不能低于前一档预算')
        ->and($service->settings())->toBe($before);
});

test('missing database generation settings fail explicitly without a code fallback', function () {
    SystemSetting::query()->whereKey(SystemSetting::GENERATION)->delete();

    expect(fn () => app(GenerationSettingsService::class)->settings())
        ->toThrow(RuntimeException::class, '数据库缺少 generation 全局生成参数');
});

test('invalid database generation settings fail explicitly without a code fallback', function () {
    SystemSetting::query()->whereKey(SystemSetting::GENERATION)->update([
        'value' => ['schema_version' => 999],
    ]);

    expect(fn () => app(GenerationSettingsService::class)->settings())
        ->toThrow(RuntimeException::class, '数据库中的 generation 全局生成参数无效');
});
