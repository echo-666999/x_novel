<?php

use App\AI\Exceptions\AiProviderException;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use App\Services\GenerationFailurePolicy;
use Illuminate\Database\QueryException;
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
    'outline_route_not_configured',
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

test('reasoning exhaustion and visible truncation keep distinct failure categories and actions', function (string $code, bool $retryable, string $category, string $action) {
    $failure = app(GenerationFailurePolicy::class)->fromException(
        new AiProviderException($code, 'Completion limit reached.', $retryable),
        'generation_failed',
    );

    expect($failure->metadata['category'])->toBe($category)
        ->and($failure->recommendedAction)->toBe($action);
})->with([
    'reasoning exhausted' => ['novel_outline_foundation_reasoning_budget_exhausted', false, 'reasoning_budget_exhausted', '增加推理预留或降低推理程度'],
    'visible truncated' => ['novel_outline_foundation_output_truncated', true, 'visible_output_truncated', '调整模型路由或输出预算'],
    'unclassified completion limit' => ['novel_outline_foundation_completion_budget_exhausted', false, 'completion_budget_exhausted', '检查 Usage 并调整请求预算'],
]);

test('unexpected code failures are terminal while database failures remain retryable', function () {
    $policy = app(GenerationFailurePolicy::class);
    $codeFailure = $policy->fromException(new ErrorException('Undefined array key 0'), 'generation_code_failure');
    $databaseFailure = $policy->fromException(
        new QueryException('pgsql', 'select 1', [], new RuntimeException('connection lost')),
        'generation_code_failure',
    );

    expect($codeFailure->retryable)->toBeFalse()
        ->and($codeFailure->metadata['category'])->toBe('manual_attention')
        ->and($databaseFailure->retryable)->toBeTrue()
        ->and($databaseFailure->metadata['category'])->toBe('infrastructure_temporary');
});
