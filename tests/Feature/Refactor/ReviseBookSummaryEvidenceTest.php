<?php

use App\Actions\Chapters\ReviseBookSummaryEvidenceAction;
use App\AI\Exceptions\AiProviderException;
use App\Filament\Resources\Novels\Pages\ViewBookChapter;
use App\Jobs\ExecuteGenerationRunJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
require_once __DIR__.'/NovelPipelineFixtures.php';

function summaryEvidenceFixture(): array
{
    config(['generation.issues.max_attempts' => 0]);
    $task = pipelineTask(['mode' => 'full_book']);
    Queue::fake([ExecuteGenerationRunJob::class, UpdateMemoryJob::class]);
    pipelineUntil($task, 'review');
    pipelineStep($task);
    $run = pipelineStep($task, ['points' => [['text' => '林舟站在港口。', 'evidence' => ['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟飞在港口']]]]);
    $issue = GenerationIssue::sole();
    $issue->update(['attempt_count' => 2]);
    $action = app(ReviseBookSummaryEvidenceAction::class);
    $old = $action->candidate($run);
    $rows = $action->rows($old);
    $rows[0] = ['evidence' => ['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口'], 'reason' => '原引文错误；正式正文逐字支持所在位置。', 'supported' => true];

    return [$task->fresh(), Chapter::sole(), $issue, $run, $old, $rows];
}

test('manual summary evidence preserves point body history costs and attempts', function () {
    [$task, $chapter, $issue, $run, $old, $rows] = summaryEvidenceFixture();
    $usage = UsageRecord::count();
    $body = $chapter->canonical_artifact_id;
    $key = (string) Str::uuid();
    $action = app(ReviseBookSummaryEvidenceAction::class);
    $new = $action->handle($chapter->id, $run->id, $old->id, $old->checksum, $task->control_version, $rows, $key, false);
    expect($new->data['points'][0]['text'])->toBe($old->data['points'][0]['text']);
    expect($old->fresh()->data['points'][0]['evidence']['quote'])->toBe('林舟飞在港口');
    expect($chapter->fresh()->canonical_artifact_id)->toBe($body);
    expect($chapter->fresh()->summary_artifact_id)->toBe($new->id);
    expect($issue->fresh()->status)->toBe('resolved');
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect(UsageRecord::count())->toBe($usage);
    expect($action->handle($chapter->id, $run->id, $old->id, $old->checksum, $task->control_version, $rows, $key, false)->id)->toBe($new->id);
});

test('manual summary evidence refuses unsupported ungrounded and stale changes', function (string $case) {
    [$task, $chapter, $issue, $run, $old, $rows] = summaryEvidenceFixture();
    $version = $task->control_version;
    if ($case === 'support') {
        $rows[0]['supported'] = false;
    }
    if ($case === 'reason') {
        $rows[0]['reason'] = '';
    }
    if ($case === 'quote') {
        $rows[0]['evidence']['quote'] = '无真实依据';
    }
    if ($case === 'stale') {
        $version--;
    }
    if ($case === 'body') {
        $chapter->update(['canonical_artifact_id' => null]);
    }
    $count = GenerationArtifact::count();
    expect(fn () => app(ReviseBookSummaryEvidenceAction::class)->handle($chapter->id, $run->id, $old->id, $old->checksum, $version, $rows, (string) Str::uuid(), false))->toThrow($case === 'quote' ? AiProviderException::class : LogicException::class);
    expect(GenerationArtifact::count())->toBe($count);
    expect($issue->fresh()->status)->not->toBe('resolved');
})->with(['support', 'reason', 'quote', 'stale', 'body']);

test('summary evidence editor retains invalid input and saves corrected evidence', function () {
    [$task, $chapter, $issue, $run, $old, $rows] = summaryEvidenceFixture();
    $this->actingAs(User::factory()->create());
    $page = Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => $chapter->id]);
    $page->assertSee('人工修订摘要证据')->set('summaryRows.0.evidence.quote', '无依据')->call('saveSummaryEvidence')->assertSet('summaryRows.0.evidence.quote', '无依据');
    $page->set('summaryRows', $rows)->call('saveSummaryEvidence')->assertSee('摘要证据修订已保存并完整复验');
});
