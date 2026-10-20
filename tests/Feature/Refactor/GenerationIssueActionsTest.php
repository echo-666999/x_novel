<?php

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\UsageRecorder;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Services\GenerationIssueActions;
use App\Services\GenerationIssueService;
use App\Services\GenerationRunCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
require_once __DIR__.'/GenerationIssueFixtures.php';

test('manual note is idempotent and never pretends the issue is resolved', function () {
    [$task, $failed, $issue] = issueFixture('unknown_error', false);
    $actions = app(GenerationIssueActions::class);
    $key = (string) Str::uuid();
    $run = $actions->execute($issue->id, 'save_note', $issue->fingerprint, $task->control_version, $key, '已检查服务，等待日志定位。');
    $same = $actions->execute($issue->id, 'save_note', $issue->fingerprint, $task->control_version, $key, '已检查服务，等待日志定位。');
    expect($same->id)->toBe($run->id);
    expect($issue->fresh()->status)->toBe('awaiting_user');
    expect(GenerationArtifact::find($run->result_artifact_id)->type)->toBe(ArtifactType::OperationRecord);
    expect(fn () => $actions->execute($issue->id, 'save_note', $issue->fingerprint, $task->control_version, $key, '不同内容'))->toThrow(LogicException::class);
});

test('unknown and future domain actions are rejected without writes', function () {
    [$task, $run, $issue] = issueFixture();
    foreach (['shell', 'sql', 'rewrite_range', 'repair_evidence', 'raise_budget', 'unlock_fact'] as $action) {
        expect(fn () => app(GenerationIssueActions::class)->execute($issue->id, $action, $issue->fingerprint, $task->control_version, (string) Str::uuid()))->toThrow(InvalidArgumentException::class);
    }
    expect($issue->fresh()->attempt_count)->toBe(0);
});

test('same operation retries once and cumulative limits cannot be reset by new runs', function () {
    [$task, $failed, $issue] = issueFixture();
    $actions = app(GenerationIssueActions::class);
    $key = (string) Str::uuid();
    $run = $actions->execute($issue->id, 'retry_transient', $issue->fingerprint, $task->control_version, $key, dispatch: false);
    $same = $actions->execute($issue->id, 'retry_transient', $issue->fingerprint, $task->control_version, $key, dispatch: false);
    expect($same->id)->toBe($run->id);
    expect($issue->fresh()->attempt_count)->toBe(1);
    $coordinator = app(GenerationRunCoordinator::class);
    $lease = $coordinator->claimExecution($run->id);
    $this->travel(10)->seconds();
    $coordinator->failExecution($run->id, $lease['token'], 'provider_timeout', '故障仍在', true);
    app(GenerationIssueService::class)->observe($run->fresh());
    expect($issue->fresh()->repair_elapsed_ms)->toBeGreaterThanOrEqual(10000);
    $second = $actions->execute($issue->id, 'retry_transient', $issue->fingerprint, $task->control_version, (string) Str::uuid(), dispatch: false);
    $second->update(['status' => RunStatus::Failed, 'finished_at' => now()]);
    app(GenerationIssueService::class)->observe($second->fresh());
    expect(fn () => $actions->execute($issue->id, 'retry_transient', $issue->fingerprint, $task->control_version, (string) Str::uuid(), dispatch: false))->toThrow(LogicException::class, '耗尽');
    expect($issue->fresh()->attempt_count)->toBe(2);
});

test('pause makes old previews stale and still leaves a manual path', function () {
    [$task, $run, $issue] = issueFixture();
    app(GenerationRunCoordinator::class)->control($task->id, $task->control_version, 'pause');
    expect(fn () => app(GenerationIssueActions::class)->execute($issue->id, 'save_note', $issue->fingerprint, $task->control_version, (string) Str::uuid(), '保留输入'))->toThrow(LogicException::class, '过期');
    $preview = app(GenerationIssueActions::class)->preview($issue, 'diagnose');
    expect($preview['available'])->toBeFalse();
    expect(app(GenerationIssueActions::class)->preview($issue, 'save_note')['available'])->toBeTrue();
});

test('lost workers allow explicit bounded recovery only after the old lease is fenced', function () {
    [$task, $failed, $issue] = issueFixture('worker_lost', false);
    $actions = app(GenerationIssueActions::class);
    $failed->update(['lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute()]);
    expect($actions->preview($issue, 'retry_transient')['available'])->toBeFalse();
    $failed->update(['lease_token' => null, 'lease_expires_at' => null]);
    expect($actions->preview($issue, 'retry_transient')['available'])->toBeTrue();
    $key = (string) Str::uuid();
    $retry = $actions->execute($issue->id, 'retry_transient', $issue->fingerprint, $task->control_version, $key, dispatch: false);
    $same = $actions->execute($issue->id, 'retry_transient', $issue->fingerprint, $task->control_version, $key, dispatch: false);
    expect($same->id)->toBe($retry->id);
    expect($retry->id)->not->toBe($failed->id);
    expect($failed->fresh()->error_code)->toBe('worker_lost');
    expect($failed->fresh()->status)->toBe(RunStatus::Failed);
    expect($issue->fresh()->attempt_count)->toBe(1);
    expect($issue->fresh()->status)->not->toBe('resolved');
});

test('deadline stops a repair without removing manual advice or original evidence', function () {
    [$task, $run, $issue] = issueFixture();
    $issue->update(['deadline_at' => now()->subSecond()]);
    expect(app(GenerationIssueActions::class)->preview($issue, 'retry_transient')['available'])->toBeFalse();
    expect(app(GenerationIssueActions::class)->preview($issue, 'save_note')['available'])->toBeTrue();
    expect($issue->fresh()->first_run_id)->toBe($run->id);
});

test('diagnosis uses one metered request and stores a proposal without resolving the issue', function () {
    [$task, $source, $issue] = issueDiagnosticFixture();
    $fake = (new FakeAiProvider)->enqueue(new AiResponse('', issueProposal($issue->id), 10, 20, 0, 10, 'diagnostic-id', 'diagnostic-fixture'));
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));
    $run = app(GenerationIssueActions::class)->execute($issue->id, 'diagnose', $issue->fingerprint, $task->control_version, (string) Str::uuid(), '检查来源', dispatch: false);
    $job = new ExecuteGenerationRunJob($run->id);
    $job->handle(app(GenerationRunCoordinator::class));
    $job->handle(app(GenerationRunCoordinator::class));
    app(GenerationIssueService::class)->observe($run->fresh());
    expect($fake->requests())->toHaveCount(1);
    expect($run->fresh()->status)->toBe(RunStatus::Succeeded);
    expect($issue->fresh()->status)->toBe('awaiting_user');
    expect($task->fresh()->status)->toBe('needs_attention');
    $this->assertDatabaseHas('usage_records', ['generation_run_id' => $run->id, 'issue_id' => $issue->id, 'status' => 'settled']);
    expect(fn () => app(GenerationIssueActions::class)->execute($issue->id, 'diagnose', $issue->fingerprint, $task->control_version, (string) Str::uuid(), '检查来源', dispatch: false))->toThrow(LogicException::class, '没有进展');
});

test('invalid AI action leaves original problem and spent usage visible without recursive repair', function () {
    [$task, $source, $issue] = issueDiagnosticFixture();
    $proposal = [...issueProposal($issue->id), 'action' => 'run_shell'];
    $fake = (new FakeAiProvider)->enqueue(new AiResponse('', $proposal, 10, 20, 0, 10, 'diagnostic-id', 'diagnostic-fixture'));
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));
    $run = app(GenerationIssueActions::class)->execute($issue->id, 'diagnose', $issue->fingerprint, $task->control_version, (string) Str::uuid(), dispatch: false);
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    app(GenerationIssueService::class)->observe($run->fresh());
    expect($run->fresh()->status)->toBe(RunStatus::Failed);
    expect($issue->fresh()->error_code)->toBe('unknown_error');
    expect($issue->fresh()->attempt_count)->toBe(1);
    $this->assertDatabaseCount('generation_issues', 1);
    $this->assertDatabaseCount('usage_records', 1);
    expect($fake->requests())->toHaveCount(1);
});

test('diagnosis cannot exceed an explicitly enabled issue cap and never clears prior costs', function () {
    [$task, $source, $issue] = issueDiagnosticFixture();
    AIModelPrice::query()->update(['input_price' => 1, 'output_price' => 1]);
    $novel = $task->novel;
    $settings = $novel->settings ?? [];
    $settings['budget'] = ['issue_max_cost_enabled' => true, 'issue_max_cost' => '0.000001'];
    $novel->update(['settings' => $settings]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));
    $run = app(GenerationIssueActions::class)->execute($issue->id, 'diagnose', $issue->fingerprint, $task->control_version, (string) Str::uuid(), dispatch: false);
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    app(GenerationIssueService::class)->observe($run->fresh());
    expect($fake->requests())->toHaveCount(0);
    expect($run->fresh()->error_code)->toContain('budget_');
    expect($issue->fresh()->attempt_count)->toBe(1);
    expect($task->fresh()->novel->settings['budget']['issue_max_cost_enabled'])->toBeTrue();
});

test('missing AI authorization keeps the manual path available', function () {
    [$task, $run, $issue] = issueFixture('unknown_error', false);
    createAiProviderConnectionForTest();
    createAiModelRouteForTest(AiStage::Reviewer);
    $preview = app(GenerationIssueActions::class)->preview($issue, 'diagnose');
    expect($preview['available'])->toBeFalse();
    expect($preview['reason'])->toContain('尚未授权');
    expect(app(GenerationIssueActions::class)->preview($issue, 'save_note')['available'])->toBeTrue();
});

test('late AI result is isolated after a source artifact pointer changes', function () {
    [$task, $source, $issue] = issueDiagnosticFixture();
    $chapter = Chapter::factory()->create(['novel_id' => $task->novel_id]);
    $draft = GenerationArtifact::factory()->create(['generation_run_id' => $source->id, 'novel_id' => $task->novel_id, 'validation_status' => 'validated']);
    $chapter->update(['current_draft_artifact_id' => $draft->id]);
    $source->update(['source_manifest' => [['artifact_id' => $draft->id, 'checksum' => $draft->checksum, 'pointer' => 'current_draft_artifact_id', 'chapter_id' => $chapter->id]]]);
    $issue->update(['source_identity' => app(GenerationIssueService::class)->identity($source)]);
    // 竞态夹具：认领之后人工保存新稿，旧响应不得覆盖指针或关闭问题。
    $run = app(GenerationIssueActions::class)->execute($issue->id, 'diagnose', $issue->fingerprint, $task->control_version, (string) Str::uuid(), dispatch: false);
    $lease = app(GenerationRunCoordinator::class)->claimExecution($run->id);
    $newDraft = GenerationArtifact::factory()->create(['version' => 2, 'generation_run_id' => $source->id, 'novel_id' => $task->novel_id, 'validation_status' => 'validated']);
    $chapter->update(['current_draft_artifact_id' => $newDraft->id]);
    $artifact = app(GenerationRunCoordinator::class)->completeExecution($run->id, $lease['token'], $lease['control_version'], ArtifactType::RepairProposal, ['proposal' => issueProposal($issue->id)]);
    expect($artifact->validation_status)->toBe(ArtifactValidationStatus::Rejected);
    expect($chapter->fresh()->current_draft_artifact_id)->toBe($newDraft->id);
    expect($issue->fresh()->status)->not->toBe('resolved');
});

test('chapter scoped diagnostic retains chapter costs and obeys the chapter hard cap', function () {
    [$task, $source, $issue] = issueDiagnosticFixture();
    $chapter = Chapter::factory()->create(['novel_id' => $task->novel_id]);
    $source->update(['chapter_id' => $chapter->id]);
    $issue->update(['chapter_id' => $chapter->id, 'source_identity' => app(GenerationIssueService::class)->identity($source)]);
    AIModelPrice::query()->update(['input_price' => 1, 'output_price' => 1]);
    $settings = $task->novel->settings;
    $settings['budget'] = ['chapter_max_cost_enabled' => true, 'chapter_max_cost' => '0.000001'];
    $task->novel->update(['settings' => $settings]);
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));
    $run = app(GenerationIssueActions::class)->execute($issue->id, 'diagnose', $issue->fingerprint, $task->control_version, (string) Str::uuid(), dispatch: false);
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    expect($fake->requests())->toHaveCount(0);
    expect($run->fresh()->chapter_id)->toBe($chapter->id);
    expect($run->fresh()->error_code)->toBe('budget_chapter_max_cost_hard_limit');
});

test('summary transport timeout remains retryable even with historical summary classification', function () {
    [$task, $failed, $issue] = issueFixture();
    $failed->update(['error_retryable' => false, 'stage' => GenerationStage::MemorySummary, 'execution_contract' => [...$failed->execution_contract, 'book_stage' => 'summary']]);
    $issue->update(['source_identity' => app(GenerationIssueService::class)->identity($failed->fresh()), 'category' => 'summary', 'evidence' => [...$issue->evidence, 'policy' => ['retryable' => false]]]);
    $actions = app(GenerationIssueActions::class);
    expect($actions->preview($issue->fresh(), 'retry_transient')['available'])->toBeTrue();
    $run = $actions->execute($issue->id, 'retry_transient', $issue->fingerprint, $task->control_version, (string) Str::uuid(), dispatch: false);
    expect($run->execution_contract)->toEqual($failed->execution_contract);
    expect($issue->fresh()->attempt_count)->toBe(1);
    expect($issue->fresh()->evidence['policy']['retryable'])->toBeFalse();
});
