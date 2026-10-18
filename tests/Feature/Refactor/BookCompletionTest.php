<?php

use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeEmbeddingProvider;
use App\AI\Providers\TrackingEmbeddingProvider;
use App\AI\UsageRecorder;
use App\Data\CanonicalCommitData;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Generation;
use App\Filament\Resources\Novels\Pages\ManageNovelChapters;
use App\Filament\Resources\Novels\Pages\ViewBookChapter;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Filament\Resources\Novels\Pages\ViewNovelEndingAudit;
use App\Jobs\ExecuteGenerationRunJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\AIModelRoute;
use App\Models\Chapter;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\Review;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\SystemSetting;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\BookEnding;
use App\Services\BookExportService;
use App\Services\BookLifecycle;
use App\Services\BookMemoryService;
use App\Services\CanonicalCommitService;
use App\Services\GenerationInputIdentity;
use App\Services\GenerationIssueActions;
use App\Services\GenerationRunCoordinator;
use App\Services\LatestCanonicalChapterRollback;
use App\Services\NovelBookWorkflow;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
require_once __DIR__.'/NovelPipelineFixtures.php';

/** 单章短夹具仅验证正式链合同，不作为真实小说质量或篇幅验收。 */
function completionReady(array $options = []): NovelGenerationTask
{
    $task = pipelineTask($options);
    Queue::fake([ExecuteGenerationRunJob::class, UpdateMemoryJob::class]);
    pipelineUntil($task, 'review');
    pipelineStep($task);

    return $task->fresh();
}

test('manual submission commits once and persists summary handoff', function () {
    $task = completionReady();
    $version = $task->control_version;
    $this->actingAs(User::factory()->create());
    // 提交成功的同次响应应撤下提交入口，并显示刚刚可用的正式导出和暂停。
    Livewire::test(ViewNovel::class, ['record' => $task->novel_id])
        ->callAction('submitBookChapter')
        ->assertActionHidden('submitBookChapter')
        ->assertActionVisible('exportBook')
        ->assertActionVisible('book_pause');
    expect(Chapter::sole()->status->value)->toBe('canonical');
    expect($task->novel->fresh()->canonicalStateVersion->version)->toBe(1);
    expect($task->fresh()->current_phase)->toBe('memory_summary');
    expect(fn () => app(BookLifecycle::class)->submit($task->id, $version, Review::sole()->id))->toThrow(LogicException::class);
    expect($task->novel->storyStateVersions()->count())->toBe(2);
    Queue::assertPushed(ExecuteGenerationRunJob::class);
});

test('automatic canonical summary ending and export finish one frozen book', function () {
    Storage::fake('local');
    $task = completionReady(['mode' => 'full_book']);
    expect(Chapter::sole()->status->value)->toBe('canonical');
    $task->update(['target_words' => 1]);
    $summary = pipelineStep($task, ['points' => [['text' => '林舟在港口收到信后决定追查。', 'evidence' => ['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]);
    expect($summary->status->value)->toBe('succeeded', $summary->error_message ?? '');
    expect($task->fresh()->current_phase)->toBe('ending_audit', json_encode($task->fresh()->stop_detail, JSON_UNESCAPED_UNICODE));
    $run = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    $checks = array_map(fn ($r) => ['key' => $r['key'], 'fulfilled' => true, 'reason' => '固定响应核验', 'evidence' => [['chapter_id' => Chapter::sole()->id, 'paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]], $run->execution_contract['input']['requirements']);
    pipelineStep($task, ['checks' => $checks]);
    $export = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    expect($export->stage->value)->toBe('export', json_encode($task->fresh()->stop_detail, JSON_UNESCAPED_UNICODE));
    (new ExecuteGenerationRunJob($export->id))->handle(app(GenerationRunCoordinator::class));
    expect($task->fresh()->status)->toBe('completed', json_encode($task->fresh()->stop_detail, JSON_UNESCAPED_UNICODE));
    $manifest = GenerationArtifact::findOrFail($task->fresh()->export_manifest_id);
    Storage::disk('local')->assertExists($manifest->data['path']);
    expect(Storage::disk('local')->get($manifest->data['path']))->toContain('雨中的未来来信')->toContain('林舟站在港口')->not->toContain('goal_checks');
    Queue::assertPushed(UpdateMemoryJob::class);
});

/** 同一提交凭据的重放只能返回已存在的正式效果。 */
function completionData($task): CanonicalCommitData
{
    $review = Review::sole();
    $r = $review->artifact->data;
    $draft = GenerationArtifact::findOrFail($r['source_artifact_id']);

    return new CanonicalCommitData(Chapter::sole()->id, $draft->id, $review->id, $r['event_artifact_id'], $r['patch_artifact_id'], 0, $draft->checksum, $task->id, $task->control_version);
}

test('canonical replay has one effect and stale or paused control cannot commit', function () {
    $task = completionReady();
    $data = completionData($task);
    expect(fn () => app(CanonicalCommitService::class)->commit($data))->toThrow(ValidationException::class);
    expect($task->novel->storyStateVersions()->count())->toBe(1);
    $task->update(['status' => 'running']);
    app(CanonicalCommitService::class)->commit($data);
    app(CanonicalCommitService::class)->commit($data);
    expect($task->novel->storyStateVersions()->count())->toBe(2);
    expect(GenerationRun::where('stage', 'commit')->count())->toBe(1);
    $task->increment('control_version');
    expect(fn () => app(CanonicalCommitService::class)->commit($data))->toThrow(LogicException::class);
});

test('every formal write failure rolls back all canonical effects', function (string $model, string $event) {
    $task = completionReady();
    $listener = 'eloquent.'.$event.': '.$model;
    Event::listen($listener, function ($record): void {
        if ($record instanceof Chapter && $record->status->value !== 'canonical') {
            return;
        }
        if ($record instanceof Novel && ! $record->isDirty('canonical_state_version_id')) {
            return;
        }
        if ($record instanceof GenerationRun && $record->stage->value !== 'commit') {
            return;
        }
        throw new RuntimeException('R05 injected transaction fault');
    });
    try {
        expect(fn () => app(BookLifecycle::class)->submit($task->id, $task->control_version, Review::sole()->id))->toThrow(RuntimeException::class, 'R05 injected transaction fault');
    } finally {
        Event::forget($listener);
    }
    expect(Chapter::sole()->canonical_artifact_id)->toBeNull();
    expect($task->novel->storyStateVersions()->count())->toBe(1);
    expect($task->novel->storyEvents()->count())->toBe(0);
    expect($task->fresh()->status)->toBe('paused');
})->with([
    [StoryStateVersion::class, 'creating'],
    [Chapter::class, 'updating'],
    [Novel::class, 'updating'],
    [GenerationRun::class, 'creating'],
    [GenerationArtifact::class, 'creating'],
]);

test('summary admission failure preserves canonical chapter and recoverable checkpoint', function () {
    $task = pipelineTask(['mode' => 'full_book']);
    Queue::fake();
    pipelineUntil($task, 'review');
    AIModelRoute::where('role', 'summary')->delete();
    pipelineStep($task);
    expect(Chapter::sole()->status->value)->toBe('canonical');
    expect($task->novel->fresh()->canonicalStateVersion->version)->toBe(1);
    expect($task->fresh()->pending_transition['canonical_chapter_id'])->toBe(Chapter::sole()->id);
    expect(GenerationIssue::where('status', '!=', 'resolved')->exists())->toBeTrue();
    // 异常中心应定位到已提交章的摘要阶段，不能把保留正文的故障显示成规划失败。
    $issue = GenerationIssue::where('status', '!=', 'resolved')->sole();
    expect($issue->source_identity['stage'])->toBe('memory_summary');
    expect($issue->chapter_id)->toBe(Chapter::sole()->id);
});

test('rollback appends state history and removes all recalled chapter memory', function () {
    $task = completionReady(['mode' => 'full_book']);
    $task->update(['mode' => 'opening', 'opening_chapter_count' => 1]);
    Queue::fake();
    pipelineStep($task, ['points' => [['text' => '林舟收到信。', 'evidence' => ['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]);
    app(BookMemoryService::class)->update(Chapter::sole()->id);
    expect(app(BookMemoryService::class)->retrieve($task->novel->fresh())['memories'])->not->toBeEmpty();
    expect($task->fresh()->status)->toBe('paused');
    $oldState = $task->novel->fresh()->canonical_state_version_id;
    app(LatestCanonicalChapterRollback::class)->rollback(Chapter::sole(), '测试撤回最近章');
    expect($task->novel->storyStateVersions()->count())->toBe(3);
    expect(StoryStateVersion::find($oldState))->not->toBeNull();
    expect($task->novel->fresh()->current_chapter_sequence)->toBe(0);
    expect(app(BookMemoryService::class)->retrieve($task->novel->fresh())['memories'])->toBeEmpty();
    expect($task->fresh()->status)->toBe('paused');
});

test('missing required canonical summary blocks optional memory fallback', function () {
    $task = completionReady(['mode' => 'full_book']);
    expect(fn () => app(BookMemoryService::class)->retrieve($task->novel->fresh()))->toThrow(AiProviderException::class);
});

test('unfinished export freezes canonical content without changing generation and rebuilds missing file', function () {
    Storage::fake('local');
    $task = completionReady(['mode' => 'full_book']);
    $pending = $task->pending_transition;
    $export = app(BookExportService::class);
    $manifest = $export->snapshot($task);
    expect($export->snapshot($task)->id)->toBe($manifest->id);
    expect($task->fresh()->pending_transition)->toBe($pending);
    $path = $export->file($manifest);
    expect(Storage::disk('local')->get($path))->toContain('（未完结）')->not->toContain('goal_checks');
    Storage::disk('local')->delete($path);
    $export->file($manifest);
    Storage::disk('local')->assertExists($path);
    $this->get(route('book-exports.download', $manifest))->assertRedirect();
});

test('ending debt never completes and finite extensions stop visibly', function () {
    $task = completionReady(['mode' => 'full_book']);
    $task->update(['target_words' => 1, 'progress_snapshot' => [...$task->progress_snapshot, 'closing_extensions' => 3]]);
    pipelineStep($task, ['points' => [['text' => '收到信。', 'evidence' => ['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]);
    $run = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    $checks = array_map(fn ($r) => ['key' => $r['key'], 'fulfilled' => false, 'reason' => '承诺未兑现', 'evidence' => []], $run->execution_contract['input']['requirements']);
    pipelineStep($task, ['checks' => $checks]);
    expect($task->fresh()->status)->toBe('needs_attention');
    expect($task->novel->fresh()->status->value)->not->toBe('completed');
    expect(GenerationIssue::where('error_code', 'book_closure_debt')->exists())->toBeTrue();
});

test('workbench reader task list and completed download use actual persisted sources', function () {
    Storage::fake('local');
    $task = completionReady(['mode' => 'full_book']);
    $this->actingAs(User::factory()->create());
    Livewire::test(Dashboard::class)->assertOk()->assertSee('最近创作');
    Livewire::test(Generation::class)->assertOk()->assertSee('整书生成任务')->assertSee($task->novel->title);
    Livewire::test(ViewNovel::class, ['record' => $task->novel_id])->assertOk()->assertSee('进度与费用')->assertSee('正式正文');
    Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => Chapter::sole()->id])->assertOk()->assertSee('正式章节')->assertSee('章节目录')->assertSee('林舟站在港口');
    Livewire::test(ViewNovelEndingAudit::class, ['record' => $task->novel_id])->assertOk()->assertSee('尚未通过完结核验');
    Livewire::test(ManageNovelChapters::class, ['record' => $task->novel_id])->assertOk()->assertDontSee('创建计划章节');
    $artifact = app(BookExportService::class)->snapshot($task);
    $this->get(route('book-exports.download', $artifact))->assertOk()->assertDownload('novel-'.$task->novel_id.'-'.$artifact->id.'.txt');
});

test('post commit configuration recovery rechecks summary admission without rewriting canonical body', function () {
    $task = pipelineTask(['mode' => 'full_book']);
    Queue::fake();
    pipelineUntil($task, 'review');
    AIModelRoute::where('role', 'summary')->delete();
    pipelineStep($task);
    $chapter = Chapter::sole();
    $bodyId = $chapter->canonical_artifact_id;
    $issue = GenerationIssue::where('status', '!=', 'resolved')->latest('id')->firstOrFail();
    createAiModelRouteForTest(AiStage::Summary, 'pipeline-fixture');
    $preview = app(GenerationIssueActions::class)->preview($issue, 'rebuild_book_context');
    expect($preview['available'])->toBeTrue($preview['reason'] ?? '');
    $run = app(GenerationIssueActions::class)->execute($issue->id, 'rebuild_book_context', $issue->fingerprint, $task->fresh()->control_version, (string) Str::uuid(), dispatch: false);
    expect($run->execution_contract['book_stage'])->toBe('summary');
    expect($chapter->fresh()->canonical_artifact_id)->toBe($bodyId);
    expect($task->novel->storyStateVersions()->count())->toBe(2);
});

test('summary and derived dispatch gaps are reconciled without a second current chapter', function () {
    $task = completionReady();
    Queue::fake();
    $task->update(['status' => 'running', 'mode' => 'opening', 'opening_chapter_count' => 1]);
    app(CanonicalCommitService::class)->commit(completionData($task));
    app(GenerationRunCoordinator::class)->recoverTasks();
    $task->refresh();
    expect($task->current_phase)->toBe('memory_summary');
    pipelineStep($task, ['points' => [['text' => '收到信。', 'evidence' => ['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]);
    $task->refresh()->update(['status' => 'running']);
    app(BookMemoryService::class)->reconcile();
    Queue::assertPushed(UpdateMemoryJob::class);
    app(BookMemoryService::class)->update(Chapter::sole()->id);
    app(BookMemoryService::class)->update(Chapter::sole()->id);
    expect(Memory::where('source_type', 'chapter')->count())->toBe(1);
    expect(Chapter::count())->toBe(1);
});

test('worker and scheduler health expire without inventing online state', function () {
    foreach (['worker', 'scheduler'] as $name) {
        SystemSetting::updateOrCreate(['key' => 'generation_health_'.$name], ['value' => ['observed_at' => now()->subHour()->toISOString(), 'code_version' => 'fixture']]);
    }
    $checks = app(SystemHealthService::class)->checks()->keyBy('key');
    expect($checks['worker']->status)->toBe('stale');
    expect($checks['scheduler']->status)->toBe('stale');
    expect($checks['queue']->status)->not->toBe('healthy');
    expect($checks->has('docs'))->toBeFalse();
});

// 请求账本没有 updated_at；健康观测必须使用真实发送/结算时间，不能被打开页面刷新。
test('provider health uses persisted request observation times', function (string $status, int $minutes, bool $settled, string $expected) {
    $this->freezeTime();
    $observedAt = now()->subMinutes($minutes);
    UsageRecord::factory()->create([
        'status' => $status,
        'sent_at' => $settled ? now()->subHour() : $observedAt,
        'settled_at' => $settled ? $observedAt : null,
    ]);

    $check = app(SystemHealthService::class)->checks()->keyBy('key')['provider'];

    expect($check->status)->toBe($expected);
    expect($check->observedAt)->toBe($observedAt->startOfSecond()->toISOString());
    expect($check->detail)->toContain('仅代表最近这条请求');
})->with([
    'recent settlement' => ['settled', 1, true, 'healthy'],
    'stale settlement' => ['settled', 16, true, 'stale'],
    'request in flight' => ['in_flight', 1, false, 'warning'],
    'unknown response' => ['unknown', 1, false, 'warning'],
    'stale unresolved request' => ['unknown', 16, false, 'stale'],
]);

// 预留费用和发送前失败均不证明服务商收到了请求。
test('provider health does not treat unsent reservations as observations', function () {
    UsageRecord::factory()->create(['status' => 'prepared', 'sent_at' => now()]);
    UsageRecord::factory()->create(['status' => 'not_sent', 'sent_at' => now()]);

    $check = app(SystemHealthService::class)->checks()->keyBy('key')['provider'];

    expect($check->status)->toBe('manual');
    expect($check->observedAt)->toBeNull();
});

test('derived embedding uses its own frozen request ledger and does not mutate canonical state', function () {
    $task = completionReady(['mode' => 'full_book']);
    Queue::fake();
    $task->update(['mode' => 'opening', 'opening_chapter_count' => 1]);
    pipelineStep($task, ['points' => [['text' => '收到信。', 'evidence' => ['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]);
    $task->refresh()->update(['status' => 'completed']);
    configureEmbeddingForTest('book-vector', 1536);
    app(BookMemoryService::class)->update(Chapter::sole()->id);
    $memory = Memory::where('source_type', 'chapter')->sole();
    $fake = (new FakeEmbeddingProvider)->enqueue(new EmbeddingResponse(array_fill(0, 1536, .1), 12, 7, 'derived-fixture', 'book-vector'));
    app()->instance(EmbeddingProvider::class, new TrackingEmbeddingProvider($fake, app(UsageRecorder::class)));
    $state = $task->novel->fresh()->canonical_state_version_id;
    app(BookMemoryService::class)->embed($memory->id);
    app(BookMemoryService::class)->embed($memory->id);
    expect($fake->requests())->toHaveCount(1);
    expect($memory->fresh()->embedding_model)->toBe('book-vector');
    $run = GenerationRun::where('pipeline_version', 'book-derived-v1')->where('scope_key', 'memory:'.$memory->id)->sole();
    expect($run->status->value)->toBe('succeeded', $run->error_message ?? '');
    expect(UsageRecord::where('generation_run_id', $run->id)->sole()->status)->toBe('settled');
    expect($task->novel->fresh()->canonical_state_version_id)->toBe($state);
});

test('whole book advances across canonical versions and pauses opening after required summaries', function () {
    $task = completionReady(['mode' => 'opening', 'opening_chapter_count' => 2]);
    Queue::fake();
    pipelineStep($task, ['points' => [['text' => '收到信。', 'evidence' => ['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]);
    expect($task->fresh()->status)->toBe('running');
    expect(Chapter::count())->toBe(2);
    pipelineUntil($task, 'review');
    pipelineStep($task);
    expect(Chapter::where('status', 'canonical')->count())->toBe(2);
    expect($task->novel->fresh()->canonicalStateVersion->version)->toBe(2);
    pipelineStep($task, ['points' => [['text' => '继续追查。', 'evidence' => ['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]);
    expect($task->fresh()->stop_reason)->toBe('opening_complete');
    expect($task->fresh()->status)->toBe('paused');
    app(GenerationRunCoordinator::class)->recoverTasks();
    expect(Chapter::count())->toBe(2);
});

// 自动补章次数耗尽后，只有用户的新授权能扩大有限额度，旧预览不可重复使用。
test('unresolved ending requires finite explicit extension and preserves canonical state', function () {
    config(['generation.book.max_closing_extensions' => 0]);
    $task = completionReady(['mode' => 'full_book']);
    Queue::fake();
    $task->update(['target_words' => 1]);
    pipelineStep($task, ['points' => [['text' => '收到信。', 'evidence' => ['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]);
    $run = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    $checks = array_map(fn ($r) => ['key' => $r['key'], 'fulfilled' => false, 'reason' => '尚未找到正式兑现证据', 'evidence' => []], $run->execution_contract['input']['requirements']);
    pipelineStep($task, ['checks' => $checks]);
    $task->refresh();
    expect($task->status)->toBe('needs_attention');
    expect($task->stop_reason)->toBe('book_closure_debt');
    $version = $task->control_version;
    $state = $task->novel->fresh()->canonical_state_version_id;
    app(BookEnding::class)->extend($task->id, $version, 1, '揭示来信者并兑现港城危机的结局');
    $task->refresh();
    expect($task->status)->toBe('running');
    expect($task->progress_snapshot['closing_extension_limit'])->toBe(1);
    expect($task->progress_snapshot['closing_extensions'])->toBe(1);
    expect(GenerationIssue::where('error_code', 'book_closure_debt')->sole()->status)->toBe('repairing');
    expect($task->novel->fresh()->canonical_state_version_id)->toBe($state);
    expect(fn () => app(BookEnding::class)->extend($task->id, $version, 1, '重复授权'))->toThrow(LogicException::class);
    pipelineStep($task);
    expect($task->fresh()->current_phase)->toBe('chapter_planning');
});

// 导出损坏不能让任务成功；按同一冻结清单重建即可，不重新生成正文。
test('corrupted export cannot complete task and frozen content can rebuild it', function () {
    Storage::fake('local');
    $task = completionReady(['mode' => 'full_book']);
    $export = app(BookExportService::class);
    $artifact = $export->snapshot($task);
    $path = $export->file($artifact);
    $manifest = [...$artifact->data, 'complete' => true, 'path' => $path, 'file_checksum' => hash('sha256', Storage::disk('local')->get($path))];
    // 隔离伪造存储故障，不修改不可变正文；专门验证最后的文件完整性门禁。
    $artifact = GenerationArtifact::create(['generation_run_id' => $artifact->generation_run_id, 'type' => $artifact->type, 'version' => 2, 'schema_version' => 'book-export-v1', 'data' => $manifest, 'checksum' => app(GenerationInputIdentity::class)->hash($manifest)]);
    $artifact->generationRun->update(['result_artifact_id' => $artifact->id]);
    $task->novel->update(['status' => 'completed']);
    Storage::disk('local')->put($path, 'truncated');
    expect(fn () => $export->adopt($task, $artifact->generationRun))->toThrow(RuntimeException::class, '导出文件校验失败');
    expect($task->fresh()->status)->not->toBe('completed');
    expect(Chapter::sole()->status->value)->toBe('canonical');
    $rebuilt = $export->file($artifact->fresh());
    expect(Storage::disk('local')->get($rebuilt))->toContain('林舟站在港口')->not->toContain('truncated');
});

// 真实事件和事实写入失败时也必须整体回滚，不能只验证空事件章。
test('event and fact insertion faults leave no partial formal state', function (string $model) {
    $task = pipelineTask();
    Queue::fake();
    pipelineUntil($task, 'write');
    pipelineStep($task, ['body' => '林舟已经死去。邮差生前留下的信被放在旧港石碑前。', 'complete' => true, 'temporary_state' => []]);
    $character = $task->novel->characters()->sole();
    // 当前提取器默认只生成状态操作。此夹具显式加入合法事实候选，覆盖提交器支持的事实写入边界。
    $fixtureEvent = 'eloquent.creating: '.GenerationArtifact::class;
    Event::listen($fixtureEvent, function (GenerationArtifact $artifact) use ($model, $character): void {
        if ($model !== Fact::class || $artifact->type !== ArtifactType::StatePatch) {
            return;
        }
        $data = $artifact->data;
        $data['fact_changes'] = [['action' => 'create', 'subject_type' => 'character', 'subject_id' => $character->id, 'predicate' => 'status', 'value' => ['value' => 'dead'], 'source_event_index' => 0]];
        $artifact->data = $data;
        $artifact->checksum = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    });
    try {
        pipelineStep($task, ['events' => [['event_type' => 'character_status_changed', 'subject_type' => 'character', 'subject_id' => (string) $character->id, 'payload' => '{"status":"dead"}', 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟已经死去']]]]]);
    } finally {
        Event::forget($fixtureEvent);
    }
    pipelineStep($task, ['findings' => [], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟已经死去', 'offset' => 0]]]]]);
    $task->refresh();
    $event = 'eloquent.creating: '.$model;
    Event::listen($event, fn () => throw new RuntimeException('R05 formal insert fault'));
    try {
        expect(fn () => app(BookLifecycle::class)->submit($task->id, $task->control_version, Review::sole()->id))->toThrow(RuntimeException::class, 'R05 formal insert fault');
    } finally {
        Event::forget($event);
    }
    expect(Chapter::sole()->canonical_artifact_id)->toBeNull();
    expect($task->novel->storyEvents()->count())->toBe(0);
    expect($task->novel->storyStateVersions()->count())->toBe(1);
    expect($task->novel->facts()->where('source_type', 'story_event')->count())->toBe(0);
})->with([StoryEvent::class, Fact::class]);

// 文件在生成成功与任务收尾之间丢失时，人工入口必须仍能只补导出。
test('lost export after successful execution exposes usable recovery without model calls', function () {
    Storage::fake('local');
    $task = completionReady(['mode' => 'full_book']);
    Queue::fake();
    $task->update(['target_words' => 1]);
    pipelineStep($task, ['points' => [['text' => '收到信。', 'evidence' => ['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]);
    $audit = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    $checks = array_map(fn ($r) => ['key' => $r['key'], 'fulfilled' => true, 'reason' => '固定证据', 'evidence' => [['chapter_id' => Chapter::sole()->id, 'paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]], $audit->execution_contract['input']['requirements']);
    pipelineStep($task, ['checks' => $checks]);
    $run = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    $lease = app(GenerationRunCoordinator::class)->claimExecution($run->id);
    app(BookExportService::class)->execute($run, $lease);
    $artifact = GenerationArtifact::findOrFail($run->fresh()->result_artifact_id);
    Storage::disk('local')->delete($artifact->data['path']);
    expect(fn () => app(NovelBookWorkflow::class)->advance($task->id, false))->toThrow(LogicException::class);
    $issue = GenerationIssue::where('category', 'export')->sole();
    $preview = app(GenerationIssueActions::class)->preview($issue, 'retry_export');
    expect($preview['available'])->toBeTrue($preview['reason'] ?? '');
    $usage = UsageRecord::count();
    $retry = app(GenerationIssueActions::class)->execute($issue->id, 'retry_export', $issue->fingerprint, $task->fresh()->control_version, (string) Str::uuid(), dispatch: false);
    (new ExecuteGenerationRunJob($retry->id))->handle(app(GenerationRunCoordinator::class));
    expect($task->fresh()->status)->toBe('completed', json_encode($task->fresh()->stop_detail, JSON_UNESCAPED_UNICODE));
    expect($issue->fresh()->status)->toBe('resolved');
    expect(UsageRecord::count())->toBe($usage);
    expect(Chapter::where('status', 'canonical')->count())->toBe(1);
});

// 撤回后恢复同一章必须采用新状态身份，不能复用旧 PASS 或重复正式序号。
test('rollback resume replans the same chapter against appended restoration state', function () {
    $task = completionReady(['mode' => 'full_book']);
    Queue::fake();
    app(GenerationRunCoordinator::class)->control($task->id, $task->fresh()->control_version, 'pause');
    $chapter = Chapter::sole();
    $old = $chapter->canonical_artifact_id;
    app(LatestCanonicalChapterRollback::class)->rollback($chapter, '重新处理本章');
    app(GenerationRunCoordinator::class)->control($task->id, $task->fresh()->control_version, 'resume');
    app(NovelBookWorkflow::class)->advance($task->id, false);
    pipelineUntil($task, 'review');
    pipelineStep($task);
    expect(Chapter::count())->toBe(1);
    expect($chapter->fresh()->canonical_artifact_id)->not->toBe($old);
    expect($task->novel->fresh()->canonicalStateVersion->version)->toBe(3);
    expect(GenerationArtifact::find($old))->not->toBeNull();
});

// 相同章集合也可能有不同书名/完结标记，文件路径必须绑定整个导出身份。
test('different export headers do not overwrite the earlier frozen snapshot', function () {
    Storage::fake('local');
    $task = completionReady(['mode' => 'full_book']);
    $export = app(BookExportService::class);
    $first = $export->snapshot($task);
    $firstPath = $export->file($first);
    $original = Storage::disk('local')->get($firstPath);
    $task->novel->update(['title' => '重命名后的来信']);
    $second = $export->snapshot($task);
    $secondPath = $export->file($second);
    expect($secondPath)->not->toBe($firstPath);
    expect(Storage::disk('local')->get($firstPath))->toBe($original);
    expect(Storage::disk('local')->get($secondPath))->toStartWith('重命名后的来信（未完结）');
    expect(Storage::disk('local')->allFiles('novel-exports'))->toHaveCount(2);
});

// 部署前置缺失必须明确展示，不能从新页面查询不存在的业务表。
test('dashboard and task page explain missing schema before deployment', function () {
    $this->actingAs(User::factory()->create());
    Schema::partialMock()->shouldReceive('hasTable')->with('novel_generation_tasks')->andReturn(false);
    Livewire::test(Dashboard::class)->assertOk()->assertSee('新版生成尚未部署');
    Livewire::test(Generation::class)->assertOk()->assertSee('新版生成尚未部署');
});
