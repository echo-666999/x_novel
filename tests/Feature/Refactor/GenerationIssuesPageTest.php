<?php

use App\Filament\Pages\GenerationIssues;
use App\Models\User;
use App\Services\GenerationRunCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
require_once __DIR__.'/GenerationIssueFixtures.php';

test('issue center requires login', function () {
    $this->get(GenerationIssues::getUrl())->assertRedirect();
    Livewire::test(GenerationIssues::class)->assertForbidden();
});

test('persistent issue exposes both paths with unavailable AI reason and escaped evidence', function () {
    $this->actingAs(User::factory()->create());
    [$task, $run, $issue] = issueFixture('unknown_error', false);
    $evidence = $issue->evidence;
    $evidence['occurrences'][0]['message'] = '<script>alert(1)</script> Bearer hidden-token';
    $issue->update(['evidence' => $evidence]);
    Livewire::test(GenerationIssues::class)->call('selectIssue', $issue->id)->assertOk()
        ->assertSee('人工处理说明')->assertSee('请 AI 分析原因')->assertSee('模型路由')
        ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('hidden-token');
    Livewire::test(GenerationIssues::class)->call('selectIssue', $issue->id)->assertSee($issue->user_message);
});

test('stale action keeps human input and creates no operation artifact', function () {
    $this->actingAs(User::factory()->create());
    [$task, $run, $issue] = issueFixture('unknown_error', false);
    $page = Livewire::test(GenerationIssues::class)->call('selectIssue', $issue->id)->set('instructions', '我已经检查过连接，保留此说明。')->call('prepare', 'save_note');
    app(GenerationRunCoordinator::class)->control($task->id, $task->control_version, 'pause');
    $page->call('submit')->assertSet('instructions', '我已经检查过连接，保留此说明。')->assertSee('预览已过期');
    expect($issue->fresh()->evidence['operations'])->toBe([]);
});

test('manual note can be saved and reloaded without closing an unresolved issue', function () {
    $this->actingAs(User::factory()->create());
    [$task, $run, $issue] = issueFixture('unknown_error', false);
    Livewire::test(GenerationIssues::class)->call('selectIssue', $issue->id)->set('instructions', '数据库连接已经人工核对。')
        ->call('prepare', 'save_note')->call('submit')->assertSee('操作已记录');
    expect($issue->fresh()->evidence['operations'])->toHaveCount(1);
    expect($issue->fresh()->status)->toBe('awaiting_user');
    Livewire::test(GenerationIssues::class)->call('selectIssue', $issue->id)->assertSee('save_note');
});

test('diagnostic download and resolved history retain evidence without exposing credentials', function () {
    $this->actingAs(User::factory()->create());
    [$task, $run, $issue] = issueFixture('unknown_error', false);
    Livewire::test(GenerationIssues::class)->call('selectIssue', $issue->id)->call('downloadDiagnostic')
        ->assertFileDownloaded('issue-'.$issue->id.'-diagnostic.json');
    $issue->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_reason' => '同来源复验通过。']);
    Livewire::test(GenerationIssues::class)->set('filter', 'resolved')->call('selectIssue', $issue->id)
        ->assertSee('同来源复验通过')->assertDontSee('wire:click="prepare(', false);
    Livewire::withQueryParams([])->test(GenerationIssues::class)->set('filter', 'awaiting_user')->set('issue', null)->assertSee('此范围没有问题');
});
