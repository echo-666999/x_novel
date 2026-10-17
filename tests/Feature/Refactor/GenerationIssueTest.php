<?php

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Data\GenerationFailure;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Services\GenerationIssueCatalog;
use App\Services\GenerationIssueService;
use App\Services\GenerationRunCoordinator;
use App\Support\GenerationDiagnosticRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/GenerationIssueFixtures.php';

test('same failure keeps first evidence and different source gets a different issue', function () {
    [$task, $run, $issue] = issueFixture();
    $same = app(GenerationIssueService::class)->record($run);
    expect($same->id)->toBe($issue->id);
    expect($same->evidence['occurrences'])->toHaveCount(1);
    $other = app(GenerationRunCoordinator::class)->enqueue($task->id, GenerationStage::Preflight, 'book', ['revision' => 2]);
    $other->update(['status' => RunStatus::Failed, 'error_code' => 'provider_timeout', 'error_retryable' => true]);
    expect(app(GenerationIssueService::class)->record($other)->id)->not->toBe($issue->id);
    $this->assertDatabaseCount('generation_issues', 2);
});

test('only a validated success with identical source closes the issue', function () {
    [$task, $failed, $issue] = issueFixture();
    $coordinator = app(GenerationRunCoordinator::class);
    $wrong = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'book', ['revision' => 2]);
    $wrong->update(['issue_id' => $issue->id]);
    $lease = $coordinator->claimExecution($wrong->id);
    $coordinator->completeExecution($wrong->id, $lease['token'], $lease['control_version'], ArtifactType::OperationRecord, ['checks' => ['task_identity' => 'valid', 'sources' => 'valid']]);
    expect(app(GenerationIssueService::class)->verifySuccess($wrong->fresh()))->toBeFalse();
    $right = $coordinator->enqueue($task->id, $failed->stage, $failed->scope_key, $failed->execution_contract);
    $lease = $coordinator->claimExecution($right->id);
    $coordinator->completeExecution($right->id, $lease['token'], $lease['control_version'], ArtifactType::OperationRecord, ['checks' => ['task_identity' => 'valid', 'sources' => 'valid']]);
    expect(app(GenerationIssueService::class)->verifySuccess($right->fresh()))->toBeTrue();
    expect($issue->fresh()->status)->toBe('resolved');
    expect($failed->fresh()->status)->toBe(RunStatus::Failed);
    app(GenerationIssueService::class)->record($failed->fresh());
    expect($issue->fresh()->status)->toBe('resolved');
});

test('warning and unknown costs do not block a completion first task', function (string $code) {
    [$task, $run, $issue] = issueFixture($code, false);
    expect($task->status)->toBe('running');
    expect($issue->severity)->toBe('warning');
    expect($issue->evidence['policy']['blocking'])->toBeFalse();
})->with(['cost_warning', 'cost_unknown', 'price_missing']);

test('failure catalog distinguishes all PRD matrix groups without parsing messages', function (string $code, string $stage, string $category) {
    $policy = app(GenerationIssueCatalog::class)->classify(new GenerationFailure($code, '同一个任意报错文本', true, [], '检查'), GenerationStage::from($stage));
    expect($policy['category'])->toBe($category);
    expect($policy['suggestion'])->not->toBeEmpty();
})->with([
    ['provider_timeout', 'preflight', 'transient'], ['provider_not_configured', 'preflight', 'configuration'],
    ['worker_lost', 'preflight', 'infrastructure'], ['provider_invalid_json', 'preflight', 'schema'],
    ['review_evidence_output_invalid', 'review', 'review_artifact'], ['request_capacity_exceeded', 'review', 'capacity'],
    ['plan_validation_failed', 'chapter_planning', 'plan'], ['quality_insufficient', 'review', 'content'],
    ['locked_fact_conflict', 'review', 'hard_constraint'], ['source_changed', 'review', 'source_conflict'],
    ['closure_debt', 'ending_audit', 'ending'], ['cost_warning', 'preflight', 'cost_warning'],
    ['budget_exceeded', 'preflight', 'cost_limit'], ['cost_unknown', 'preflight', 'cost_unknown'],
    ['provider_quota_exceeded', 'preflight', 'provider_quota'], ['repair_deadline', 'review', 'repair_limit'],
    ['summary_failed', 'memory_summary', 'summary'], ['embedding_failed', 'embedding', 'derived'],
    ['export_failed', 'export', 'export'], ['never_seen_before', 'preflight', 'unknown'],
]);

test('issue diagnosis redacts credentials in raw evidence', function () {
    config(['ai.credentials.openai' => 'fixture-private-value']);
    [$task, $run] = issueFixture();
    $run->update(['issue_id' => null, 'input_hash' => 'different']);
    $issue = app(GenerationIssueService::class)->record($run, new GenerationFailure('new_error', 'Bearer abc-secret api_key=other fixture-private-value', false, ['password' => 'hidden'], '检查'));
    $json = json_encode($issue->evidence);
    expect($json)->not->toContain('abc-secret', 'other', 'fixture-private-value', 'hidden');
});

test('redacted diagnostics keep enum and time values and remove secrets inside JSON strings', function () {
    $redactor = app(GenerationDiagnosticRedactor::class);
    $result = $redactor->clean(['stage' => GenerationStage::Preflight, 'status' => RunStatus::Failed,
        'at' => new DateTimeImmutable('2026-10-17T10:00:00+08:00'), 'raw' => '{"api_key":"not-configured-secret","error":"timeout"}']);
    expect($result['stage'])->toBe('preflight');
    expect($result['status'])->toBe('failed');
    expect($result['at'])->toBe('2026-10-17T10:00:00+08:00');
    expect($result['raw'])->not->toContain('not-configured-secret')->toContain('timeout');
});

test('new run failures retain structured fields and refuse semantic blind retries', function () {
    [$task, $first, $issue] = issueFixture();
    $coordinator = app(GenerationRunCoordinator::class);
    $run = $coordinator->enqueue($task->id, GenerationStage::Preflight, 'another', ['revision' => 2]);
    $lease = $coordinator->claimExecution($run->id);
    $coordinator->failExecution($run->id, $lease['token'], 'schema_invalid', 'Bearer fixture-secret', true,
        ['fields' => ['plan.title' => '缺少标题'], 'rule_id' => 'required_title', 'http_status' => 400]);
    expect($run->fresh()->error_retryable)->toBeFalse();
    expect($run->fresh()->error_message)->not->toContain('fixture-secret');
    $recorded = app(GenerationIssueService::class)->record($run->fresh());
    expect($recorded->evidence['policy']['fields'])->toBe(['plan.title' => '缺少标题']);
    expect($task->fresh()->status)->toBe('needs_attention');
});

test('a successful review request with rewrite decision cannot close a content problem', function () {
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '评审复验夹具')->fresh();
    $coordinator = app(GenerationRunCoordinator::class);
    $source = $coordinator->enqueue($task->id, GenerationStage::Review, 'review-fixture', []);
    $source->update(['status' => RunStatus::Failed, 'error_code' => 'quality_insufficient', 'error_retryable' => false]);
    $originalIssue = app(GenerationIssueService::class)->record($source);
    $task->update(['status' => 'running']);
    $candidate = $coordinator->enqueue($task->id, GenerationStage::Review, $source->scope_key, $source->execution_contract);
    $candidate->update(['issue_id' => $originalIssue->id]);
    $lease = $coordinator->claimExecution($candidate->id);
    $coordinator->completeExecution($candidate->id, $lease['token'], $lease['control_version'], ArtifactType::ReviewResult, ['decision' => 'REWRITE']);
    expect($candidate->input_hash)->toBe($source->input_hash);
    expect(app(GenerationIssueService::class)->verifySuccess($candidate->fresh()))->toBeFalse();
    expect($originalIssue->fresh()->status)->toBe('awaiting_user');
});
