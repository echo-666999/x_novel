<?php

use App\Actions\Chapters\RepairBookReviewEvidenceAction;
use App\AI\Exceptions\AiProviderException;
use App\Filament\Resources\Novels\Pages\ViewBookChapter;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\GenerationIssueActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
require_once __DIR__.'/NovelPipelineFixtures.php';

function evidenceRepairFixture(string $quote = '林舟站在港口', bool $hard = false): array
{
    config(['generation.issues.max_attempts' => 0]);
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    $reference = ['paragraph_key' => 'p1', 'offset' => 99999, 'quote' => $quote];
    $run = pipelineStep($task, ['findings' => $hard ? [['rule_id' => 'fact', 'severity' => 'hard', 'message' => '事实冲突', 'repair_instruction' => '保持阻断', 'evidence' => [$reference]]] : [],
        'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [$reference]]]]);
    $issue = GenerationIssue::sole();
    $issue->update(['attempt_count' => 2]);
    $action = app(RepairBookReviewEvidenceAction::class);

    return [$task->fresh(), Chapter::sole(), $issue, $run, $action->candidate($run)];
}

function eventEvidenceRepairFixture(string $quote = '林舟站在港口', bool $invalidEntity = false): array
{
    config(['generation.issues.max_attempts' => 0]);
    $task = pipelineTask();
    $source = pipelineUntil($task, 'extract');
    $id = array_key_first($source->execution_contract['input']['context']['state']['characters']);
    $run = pipelineStep($task, ['events' => [['event_type' => 'character_learned', 'subject_type' => 'character',
        'subject_id' => $invalidEntity ? '999999' : (string) $id, 'story_time' => '清晨',
        'payload' => json_encode(['key' => 'harbor_clue', 'value' => '站在港口'], JSON_UNESCAPED_UNICODE),
        'evidence' => [['paragraph_key' => 'p1', 'offset' => 99999, 'quote' => $quote]]]]]);
    $issue = GenerationIssue::sole();
    $issue->update(['attempt_count' => 2]);

    return [$task->fresh(), Chapter::sole(), $issue, $run, app(RepairBookReviewEvidenceAction::class)->candidate($run)];
}

test('event offset repair preserves content and fees and requires independent review', function () {
    [$task, $chapter, $issue, $run, $old] = eventEvidenceRepairFixture();
    $usage = UsageRecord::count();
    $original = $old->data;
    $key = (string) Str::uuid();
    $action = app(RepairBookReviewEvidenceAction::class);

    $new = $action->handle($chapter->id, $run->id, $old->id, $old->checksum, $task->control_version, $key, false);

    $expected = $original;
    $expected['events'][0]['evidence'][0]['offset'] = 0;
    expect(json_decode($new->content, true))->toBe($expected);
    expect($old->fresh()->data)->toBe($original);
    expect($run->fresh()->error_code)->toBe('book_evidence_invalid');
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect(UsageRecord::count())->toBe($usage);
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
    $next = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    expect($next->execution_contract['book_stage'])->toBe('review');
    expect($next->execution_contract['event_artifact_id'])->toBe($new->id);
    expect($action->handle($chapter->id, $run->id, $old->id, $old->checksum, $task->control_version, $key, false)->id)->toBe($new->id);
    $review = pipelineStep($task);
    expect(GenerationArtifact::findOrFail($review->result_artifact_id)->data['decision'])->toBe('PASS');
    expect($issue->fresh()->status)->toBe('resolved');
    expect($issue->fresh()->attempt_count)->toBe(2);
});

test('event offset repair refuses ambiguous absent stale or otherwise invalid events atomically', function (string $case) {
    [$task, $chapter, $issue, $run, $old] = eventEvidenceRepairFixture($case === 'absent' ? '不存在的引文' : ($case === 'ambiguous' ? '。' : '林舟站在港口'), $case === 'entity');
    $count = GenerationArtifact::count();
    $usage = UsageRecord::count();

    expect(fn () => app(RepairBookReviewEvidenceAction::class)->handle($chapter->id, $run->id, $old->id, $old->checksum,
        $task->control_version - ($case === 'stale' ? 1 : 0), (string) Str::uuid(), false))->toThrow($case === 'entity' ? AiProviderException::class : LogicException::class);

    expect(GenerationArtifact::count())->toBe($count);
    expect(UsageRecord::count())->toBe($usage);
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
})->with(['absent', 'ambiguous', 'stale', 'entity']);

test('chapter page exposes event offset correction without claiming a review pass', function () {
    [$task, $chapter] = eventEvidenceRepairFixture();
    $this->actingAs(User::factory()->create());
    Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => $chapter->id])
        ->assertSee('保存响应的证据定位修正')->call('repairEvidence')->assertSee('事件定位修正已保存并通过完整校验，仍需独立审校');
});

test('saved review offset repair preserves history usage limits and is idempotent', function () {
    [$task, $chapter, $issue, $run, $old] = evidenceRepairFixture();
    $usage = UsageRecord::count();
    $original = $old->data;
    $key = (string) Str::uuid();
    $action = app(RepairBookReviewEvidenceAction::class);
    $new = $action->handle($chapter->id, $run->id, $old->id, $old->checksum, $task->control_version, $key, false);
    expect($new->data['goal_checks'][0]['evidence'][0]['offset'])->toBe(0);
    expect($new->data['decision'])->toBe('PASS');
    expect($old->fresh()->data)->toBe($original);
    expect($run->fresh()->error_code)->toBe('book_evidence_invalid');
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($issue->fresh()->status)->toBe('resolved');
    expect(UsageRecord::count())->toBe($usage);
    expect($action->handle($chapter->id, $run->id, $old->id, $old->checksum, $task->control_version, $key, false)->id)->toBe($new->id);
});

test('offset repair retains blocking findings and never converts them to pass', function () {
    [$task, $chapter, $issue, $run, $old] = evidenceRepairFixture(hard: true);
    $new = app(RepairBookReviewEvidenceAction::class)->handle($chapter->id, $run->id, $old->id, $old->checksum, $task->control_version, (string) Str::uuid(), false);
    expect($new->data['decision'])->toBe('BLOCK');
    expect($new->data['findings'][0]['message'])->toBe('事实冲突');
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
    expect($issue->fresh()->status)->toBe('awaiting_user');
});

test('corrected blocking review uses the remaining repair allowance for its current body findings', function () {
    [$task, $chapter, $issue, $run, $old] = evidenceRepairFixture(hard: true);
    $issue->update(['attempt_count' => 1, 'repair_limit_snapshot' => [...$issue->repair_limit_snapshot, 'max_attempts' => 2]]);
    $new = app(RepairBookReviewEvidenceAction::class)->handle($chapter->id, $run->id, $old->id, $old->checksum, $task->control_version, (string) Str::uuid(), false);
    $actions = app(GenerationIssueActions::class);

    $preview = $actions->preview($issue->fresh(), 'repair_book');
    expect($preview['available'])->toBeTrue();
    expect($preview['source_run_id'])->toBe($new->generation_run_id);
    expect($preview['request_contract']['book_stage'])->toBe('rewrite');
    expect($preview['request_contract']['input']['repair_findings'][0]['message'])->toBe('事实冲突');
    expect($actions->preview($issue->fresh(), 'repair_book_events')['request_contract']['book_stage'])->toBe('extract');
    expect($issue->fresh()->attempt_count)->toBe(1);
    $repair = $actions->execute($issue->id, 'repair_book', $issue->fingerprint, $preview['control_version'], (string) Str::uuid(), dispatch: false);
    expect($repair->execution_contract['book_stage'])->toBe('rewrite');
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($run->fresh()->error_code)->toBe('book_evidence_invalid');
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
});

test('corrected review cannot bypass its audit ownership or exhausted repair allowance', function (string $case) {
    [$task, $chapter, $issue, $run, $old] = evidenceRepairFixture(hard: true);
    $new = app(RepairBookReviewEvidenceAction::class)->handle($chapter->id, $run->id, $old->id, $old->checksum, $task->control_version, (string) Str::uuid(), false);
    $issue->refresh();
    if ($case === 'audit') {
        $issue->update(['evidence' => [...$issue->evidence, 'operations' => []]]);
    } elseif ($case === 'chain') {
        $current = $new->generationRun;
        $current->update(['execution_contract' => [...$current->execution_contract, 'repair_chain_issue_id' => 0]]);
    }
    $actions = app(GenerationIssueActions::class);

    if ($case === 'exhausted') {
        expect($actions->preview($issue, 'repair_book')['available'])->toBeFalse();
    } else {
        expect(fn () => $actions->preview($issue, 'repair_book'))->toThrow(LogicException::class, '修复链');
    }
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
})->with(['audit', 'chain', 'exhausted']);

test('offset repair rejects absent ambiguous or stale sources atomically', function (string $case) {
    [$task, $chapter, $issue, $run, $old] = evidenceRepairFixture($case === 'absent' ? '不存在的引文' : ($case === 'ambiguous' ? '。' : '林舟站在港口'));
    $version = $task->control_version;
    $checksum = $old->checksum;
    if ($case === 'stale') {
        $version--;
    }
    if ($case === 'checksum') {
        $checksum = str_repeat('0', 64);
    }
    if ($case === 'body') {
        $chapter->update(['current_draft_artifact_id' => null]);
    }
    $count = GenerationArtifact::count();
    expect(fn () => app(RepairBookReviewEvidenceAction::class)->handle($chapter->id, $run->id, $old->id, $checksum, $version, (string) Str::uuid(), false))->toThrow(LogicException::class);
    expect(GenerationArtifact::count())->toBe($count);
    expect($issue->fresh()->attempt_count)->toBe(2);
})->with(['absent', 'ambiguous', 'stale', 'checksum', 'body']);

test('authorized chapter page exposes and executes the deterministic evidence correction', function () {
    [$task, $chapter] = evidenceRepairFixture();
    $this->actingAs(User::factory()->create());
    Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => $chapter->id])
        ->assertSee('保存响应的证据定位修正')->call('repairEvidence')->assertSee('定位修正已保存并通过完整校验');
});
