<?php

use App\AI\Exceptions\AiProviderException;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use App\Services\GenerationFailurePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('provider failure facts remain persisted for recovery decisions', function (int $status, bool $retryable, string $category, string $action) {
    $run = GenerationRun::factory()->create(['status' => RunStatus::Running]);
    $exception = new AiProviderException(
        'provider_request_failed',
        "Provider failed with secret-token at HTTP {$status}",
        $retryable,
        $status,
        providerRequestId: "request-{$status}",
    );

    app(GenerationFailurePolicy::class)->record($run, $exception, 'generation_failed');
    $run->refresh();
    $failure = app(GenerationFailurePolicy::class)->forRun($run);

    expect($run->error_retryable)->toBe($retryable)
        ->and(data_get($run->error_metadata, 'category'))->toBe($category)
        ->and(data_get($run->error_metadata, 'http_status'))->toBe($status)
        ->and(data_get($run->error_metadata, 'provider_request_id'))->toBe("request-{$status}")
        ->and(json_encode($run->error_metadata))->not->toContain('secret-token')
        ->and($failure->recommendedAction)->toBe($action);
})->with([
    'HTTP 503' => [503, true, 'external_temporary', '重试'],
    'HTTP 400' => [400, false, 'structured_output', '检查请求结构或输出预算'],
]);

test('legacy runs use compatibility retry mapping only when the persisted fact is null', function () {
    $legacy = GenerationRun::factory()->create([
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'error_retryable' => null,
    ]);
    $authoritative = GenerationRun::factory()->create([
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'error_retryable' => false,
    ]);

    expect(app(GenerationFailurePolicy::class)->forRun($legacy)->retryable)->toBeTrue()
        ->and(app(GenerationFailurePolicy::class)->forRun($authoritative)->retryable)->toBeFalse();
});

test('frozen provider route drift is classified as provider configuration', function (string $code) {
    $failure = app(GenerationFailurePolicy::class)->fromException(
        new AiProviderException($code, 'Frozen route mismatch.', false),
        'generation_failed',
    );

    expect($failure->metadata['category'])->toBe('provider_configuration')
        ->and($failure->recommendedAction)->toBe('修复 AI 配置');
})->with([
    'provider_run_mismatch',
    'provider_run_route_missing',
    'model_run_mismatch',
]);

test('exhausted structured output budget recommends changing the model route or budget', function () {
    $failure = app(GenerationFailurePolicy::class)->fromException(
        new AiProviderException('assembly_output_budget_exhausted', 'Budget exhausted.', false),
        'chapter_assembly_failed',
    );

    expect($failure->metadata['category'])->toBe('structured_output')
        ->and($failure->recommendedAction)->toBe('调整模型路由或输出预算');
});
