<?php

use App\Actions\Generation\GenerateNextChapterAction;
use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Actions\Novels\ReviseNovelIntentAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiResponse;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\UsageRecorder;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Filament\Pages\GenerationIssues;
use App\Jobs\CommitChapterJob;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\NovelOutline;
use App\Models\Review;
use App\Models\StoryStateVersion;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\CanonicalCommitService;
use App\Services\GenerationInputIdentity;
use App\Services\GenerationIssueActions;
use App\Services\GenerationIssueService;
use App\Services\GenerationRunCoordinator;
use App\Services\NovelBookWorkflow;
use App\Services\NovelFoundationContract;
use App\Services\NovelOutlinePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** 只构造当前合同的固定响应，不导入历史小说或访问真实 Provider。 */
function bookFoundation(array $intent): array
{
    $fields = [];
    foreach (NovelFoundationContract::FIELDS as $key) {
        $fields[$key] = ['value' => '系统补充'.$key, 'source' => 'system', 'evidence' => []];
    }

    return ['length' => ['target_words' => $intent['target_words'], 'source' => 'system', 'evidence' => []], 'fields' => $fields, 'requirements' => array_map(fn ($item) => [...$item, 'treatment' => 'retained'], $intent['requirements']),
        'promises' => ['揭开信件来源'], 'characters' => [['name' => '林舟', 'role' => '主角', 'description' => '开篇是邮差']],
        'world_entities' => [['name' => '旧港', 'description' => '雨季港城']], 'conflicts' => []];
}
function bookRoutes(): void
{
    createAiProviderConnectionForTest();
    foreach ([AiStage::OutlineFoundation, AiStage::Planner, AiStage::Writer, AiStage::Extractor, AiStage::Reviewer, AiStage::Rewrite, AiStage::Summary] as $stage) {
        createAiModelRouteForTest($stage, 'book-fixture');
    }
    config(['horizon.defaults.supervisor-1.timeout' => 350, 'generation.execution.lease_seconds' => 390, 'queue.connections.redis.retry_after' => 480]);
}
function bookTask(array $options = []): NovelGenerationTask
{
    return app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '雨季的旧港，邮差寻找未来来信。主角不能游泳。', $options);
}
function bookGenerate(NovelGenerationTask $task, ?array $data = null, bool $pauseForHandoff = true): GenerationRun
{
    bookRoutes();
    Queue::fake([ExecuteGenerationRunJob::class]);
    $fake = new FakeAiProvider;
    $data ??= bookFoundation(GenerationArtifact::findOrFail($task->intent_artifact_id)->data);
    $fake->enqueue(new AiResponse(json_encode($data), $data, 100, 300, 0, 10, 'fixture-request', 'book-fixture'));
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));
    $preflight = app(NovelBookWorkflow::class)->advance($task->id, false);
    (new ExecuteGenerationRunJob($preflight->id))->handle(app(GenerationRunCoordinator::class));
    $run = GenerationRun::where('generation_task_id', $task->id)->where('stage', GenerationStage::FoundationBlueprint)->first();
    expect($run)->not->toBeNull();
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    Queue::assertPushed(ExecuteGenerationRunJob::class);
    expect($fake->requests())->toHaveCount(1);
    expect(UsageRecord::where('generation_run_id', $run->id)->count())->toBe(1);

    // R03 原子初始化夹具显式停在交接点；真实 R04 自动推进由独立全链测试覆盖。
    if ($pauseForHandoff && $task->fresh()->status === 'running') {
        GenerationRun::where('generation_task_id', $task->id)->where('status', 'queued')->update(['status' => 'cancelled', 'finished_at' => now()]);
        app(GenerationRunCoordinator::class)->control($task->id, $task->fresh()->control_version, 'pause');
        $task->refresh()->update(['pending_transition' => $run->fresh()->checkpoint['next']]);
    }

    return $run->fresh();
}

test('premise only creation is atomic and retry submission does not create another book', function () {
    $key = (string) Str::uuid();
    $first = app(CreateNovelGenerationTaskAction::class)->handle($key, '一个邮差的故事');
    $second = app(CreateNovelGenerationTaskAction::class)->handle($key, '一个邮差的故事');
    expect($first->id)->toBe($second->id)->and(Novel::count())->toBe(1)->and($first->target_words)->toBe(100000)
        ->and($first->mode)->toBe('full_book')->and(data_get($first->novel->settings, 'budget.novel_total_limit_enabled'))->toBeFalse()
        ->and($first->novel->storyEvents()->count())->toBe(0);
    expect(fn () => app(CreateNovelGenerationTaskAction::class)->handle($key, '不同故事'))->toThrow(LogicException::class);
});

test('blank and oversize premise are rejected without partial books', function (string $premise) {
    expect(fn () => app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), $premise))->toThrow(ValidationException::class);
    expect(Novel::count())->toBe(0);
})->with([' ', str_repeat('文', 10001)]);

test('explicit premise length and form conflict preserve both sources', function () {
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '全书20万字，邮差探案。', ['target_words' => 100000]);
    $data = GenerationArtifact::find($task->intent_artifact_id)->data;
    expect($data['target_conflict'])->toBeTrue()->and($data['premise_targets'])->toBe([200000])->and($data['target_source'])->toBe('user_form');
});

test('foundation is preserved and schedules R04 blueprint without canonical writes', function () {
    $task = bookTask();
    $run = bookGenerate($task, pauseForHandoff: false);
    expect($run->status->value)->toBe('succeeded')->and($task->fresh()->status)->toBe('running')
        ->and($task->novel->bibles()->count())->toBe(0)->and($task->novel->storyEvents()->count())->toBe(0);
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    expect(GenerationArtifact::where('generation_run_id', $run->id)->count())->toBe(1);
});

test('missing original requirement saves rejected response and opens issue', function () {
    $task = bookTask();
    $data = bookFoundation(GenerationArtifact::find($task->intent_artifact_id)->data);
    array_pop($data['requirements']);
    $run = bookGenerate($task, $data);
    app(GenerationIssueService::class)->reconcile();
    expect($run->error_code)->toBe('intent_hard_constraint_missing')->and($run->artifacts()->first()->validation_status->value)->toBe('rejected')
        ->and(GenerationIssue::count())->toBe(1)->and($task->novel->bibles()->count())->toBe(0);
});

test('material contradictions are consolidated and never initialize', function () {
    $task = bookTask();
    $data = bookFoundation(GenerationArtifact::find($task->intent_artifact_id)->data);
    $data['conflicts'] = [['id' => 'c1', 'question' => '结局需要选择', 'requirement_ids' => ['r1'], 'options' => ['保留开放结局', '解开谜底']]];
    $run = bookGenerate($task, $data);
    expect($task->fresh()->status)->toBe('needs_attention')->and($task->fresh()->stop_reason)->toBe('intent_conflict')
        ->and(data_get($task->fresh()->stop_detail, 'conflicts.0.id'))->toBe('c1')->and($run->result_artifact_id)->not->toBeNull();
});

test('editing input invalidates an in flight result and preserves previous intent', function () {
    $task = bookTask();
    $old = $task->intent_artifact_id;
    $run = app(NovelBookWorkflow::class)->advance($task->id, false);
    $lease = app(GenerationRunCoordinator::class)->claimExecution($run->id);
    app(ReviseNovelIntentAction::class)->handle($task->id, $task->fresh()->control_version, '改为旱季的山城邮差。', []);
    $artifact = app(GenerationRunCoordinator::class)->completeExecution($run->id, $lease['token'], $lease['control_version'], ArtifactType::OperationRecord, ['test' => true]);
    expect($artifact->validation_status->value)->toBe('rejected')->and($task->fresh()->intent_artifact_id)->not->toBe($old)
        ->and(GenerationArtifact::find($old))->not->toBeNull()->and($task->fresh()->status)->toBe('paused');
});

/** 固定 R04 交接产物只用于初始化验证，不替代真实蓝图生成。 */
function bookPlanningFixtures(NovelGenerationTask $task, GenerationRun $foundation): array
{
    $ids = [];
    foreach (['blueprint', 'goal_window'] as $kind) {
        $data = $kind === 'blueprint' ? ['foundation_artifact_id' => $foundation->result_artifact_id, 'mainline' => '找信', 'ending' => '揭开真相', 'turning_points' => ['发现信件']]
            : ['blueprint_id' => $ids[0], 'goals' => ['接受委托']];
        $hash = app(GenerationInputIdentity::class)->hash($data);
        $artifact = GenerationArtifact::create(['generation_run_id' => $foundation->id, 'type' => $kind === 'blueprint' ? ArtifactType::OutlineBlueprint : ArtifactType::GoalWindow, 'data' => $data, 'checksum' => $hash, 'validation_status' => 'validated']);
        $outline = NovelOutline::create(['novel_id' => $task->novel_id, 'kind' => $kind, 'version' => 1, 'status' => 'draft', 'source' => 'ai', 'schema_version' => 1, 'title' => $kind, 'summary' => '固定交接', 'checksum' => $hash, 'source_artifact_id' => $artifact->id]);
        $ids[] = $outline->id;
    }

    return $ids;
}

test('validated handoff initializes once without volume arc or future events', function () {
    $task = bookTask();
    $foundation = bookGenerate($task);
    [$blueprint, $window] = bookPlanningFixtures($task, $foundation);
    app(GenerationRunCoordinator::class)->control($task->id, $task->fresh()->control_version, 'resume');
    $version = $task->fresh()->control_version;
    $novel = app(ApplyNovelBlueprintAction::class)->initializeBook($task->id, $foundation->result_artifact_id, $blueprint, $window, $version);
    app(ApplyNovelBlueprintAction::class)->initializeBook($task->id, $foundation->result_artifact_id, $blueprint, $window, $version);
    expect($novel->bibles()->count())->toBe(1)->and($novel->storyStateVersions()->count())->toBe(1)->and($novel->characters()->count())->toBe(1)
        ->and($novel->storyEvents()->count())->toBe(0)->and($novel->volumes()->count())->toBe(0)->and($novel->current_blueprint_id)->toBe($blueprint);
});

test('initialization failure after entity creation rolls the entire adoption back', function () {
    $task = bookTask();
    $foundation = bookGenerate($task);
    [$blueprint, $window] = bookPlanningFixtures($task, $foundation);
    app(GenerationRunCoordinator::class)->control($task->id, $task->fresh()->control_version, 'resume');
    $this->mock(InitializeNovelStateAction::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('injected state failure'));
    expect(fn () => app(ApplyNovelBlueprintAction::class)->initializeBook($task->id, $foundation->result_artifact_id, $blueprint, $window, $task->fresh()->control_version))->toThrow(RuntimeException::class, 'injected state failure');
    expect($task->novel->bibles()->count())->toBe(0)->and($task->novel->characters()->count())->toBe(0)->and(NovelOutline::find($blueprint)->status->value)->toBe('draft')
        ->and($task->novel->fresh()->canonical_state_version_id)->toBeNull();
});

test('pause wins initialization race', function () {
    $task = bookTask();
    $foundation = bookGenerate($task);
    [$blueprint, $window] = bookPlanningFixtures($task, $foundation);
    $version = $task->fresh()->control_version;
    expect(fn () => app(ApplyNovelBlueprintAction::class)->initializeBook($task->id, $foundation->result_artifact_id, $blueprint, $window, $version))->toThrow(LogicException::class);
    expect($task->novel->storyStateVersions()->count())->toBe(0);
});

test('opening mode counts only canonical chapters with required summaries', function () {
    $task = bookTask(['mode' => 'opening', 'opening_chapter_count' => 1]);
    $task->update(['status' => 'running']);
    $chapter = Chapter::factory()->for($task->novel)->create(['status' => 'canonical', 'summary' => null]);
    expect(app(NovelBookWorkflow::class)->chapterBoundary($task->id))->toBe('continue');
    $chapter->update(['summary' => '正式章节摘要']);
    expect(app(NovelBookWorkflow::class)->chapterBoundary($task->id))->toBe('opening_complete')->and($task->fresh()->status)->toBe('paused');
    Queue::fake([ExecuteGenerationRunJob::class]);
    app(NovelBookWorkflow::class)->control($task->id, $task->fresh()->control_version, 'resume', false);
    expect($task->fresh()->mode)->toBe('full_book')->and($task->fresh()->policy_revision)->toBe(2);
    Queue::assertNothingPushed();
});

test('manual review mode never calls automatic canonical commit from duplicate old job', function () {
    $task = bookTask(['mode' => 'manual_review']);
    $task->update(['status' => 'running']);
    $chapter = Chapter::factory()->for($task->novel)->create();
    $task->update(['current_chapter_id' => $chapter->id]);
    $commit = $this->mock(CanonicalCommitService::class);
    $commit->shouldNotReceive('commit');
    (new CommitChapterJob($chapter->id, 999))->handle($commit);
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
});

test('configuration preflight can be explicitly rechecked without charging a request', function () {
    $task = bookTask();
    config(['horizon.defaults.supervisor-1.timeout' => 350, 'generation.execution.lease_seconds' => 390, 'queue.connections.redis.retry_after' => 480]);
    $run = app(NovelBookWorkflow::class)->advance($task->id, false);
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    app(GenerationIssueService::class)->reconcile();
    expect($task->fresh()->status)->toBe('needs_attention')->and(GenerationIssue::count())->toBe(1);
    bookRoutes();
    Queue::fake([ExecuteGenerationRunJob::class]);
    app(NovelBookWorkflow::class)->control($task->id, $task->fresh()->control_version, 'recheck', false);
    $new = GenerationRun::find($task->fresh()->pending_transition['run_id']);
    (new ExecuteGenerationRunJob($new->id))->handle(app(GenerationRunCoordinator::class));
    expect(GenerationIssue::first()->status)->toBe('resolved')->and(UsageRecord::count())->toBe(0)
        ->and($task->fresh()->current_phase)->toBe('foundation_blueprint');
    Queue::assertPushed(ExecuteGenerationRunJob::class);
});

test('all contradiction choices become an immutable new intent without changing original text', function () {
    $task = bookTask();
    $intent = GenerationArtifact::find($task->intent_artifact_id);
    $data = bookFoundation($intent->data);
    $data['conflicts'] = [['id' => 'c1', 'question' => '结局', 'requirement_ids' => ['r1'], 'options' => ['解开谜底', '保留悬念']]];
    bookGenerate($task, $data);
    expect(fn () => app(NovelBookWorkflow::class)->choose($task->id, $task->fresh()->control_version, ['不在选项中']))->toThrow(LogicException::class);
    $updated = app(NovelBookWorkflow::class)->choose($task->id, $task->fresh()->control_version, ['解开谜底']);
    $new = GenerationArtifact::find($updated->intent_artifact_id);
    expect($new->id)->not->toBe($intent->id)->and($new->data['premise'])->toBe($intent->data['premise'])
        ->and($new->data['clarifications'][0]['answer'])->toBe('解开谜底')->and(GenerationIssue::first()->verification['kind'])->toBe('source_superseded');
});

test('malformed foundation and fabricated evidence cannot be adopted', function (string $fault) {
    $task = bookTask();
    $data = bookFoundation(GenerationArtifact::find($task->intent_artifact_id)->data);
    if ($fault === 'schema') {
        unset($data['fields']['ending']);
    } else {
        $data['fields']['world'] = ['value' => '新世界', 'source' => 'user', 'evidence' => ['不存在的引用']];
    }
    $run = bookGenerate($task, $data);
    expect($run->status->value)->toBe('failed')->and($run->artifacts()->first()->validation_status->value)->toBe('rejected');
})->with(['schema', 'evidence']);

test('length contradiction presents one choice and persists user decision without rewriting premise', function () {
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '全书20万字，邮差探案。', ['target_words' => 100000]);
    config(['horizon.defaults.supervisor-1.timeout' => 350, 'generation.execution.lease_seconds' => 390, 'queue.connections.redis.retry_after' => 480]);
    $run = app(NovelBookWorkflow::class)->advance($task->id, false);
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    app(GenerationIssueService::class)->reconcile();
    expect(data_get($task->fresh()->stop_detail, 'conflicts.0.id'))->toBe('target_words');
    $updated = app(NovelBookWorkflow::class)->choose($task->id, $task->fresh()->control_version, ['采用 200000 字']);
    $intent = GenerationArtifact::find($updated->intent_artifact_id)->data;
    expect($intent['target_words'])->toBe(200000)->and($intent['target_conflict'])->toBeFalse()->and($intent['premise'])->toBe('全书20万字，邮差探案。');
});

test('natural language length can be resolved with exact evidence while preserving original default provenance', function () {
    $task = app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), '写一部二十万字的港城悬疑小说。');
    $intent = GenerationArtifact::find($task->intent_artifact_id)->data;
    $data = bookFoundation($intent);
    $data['length'] = ['target_words' => 200000, 'source' => 'user', 'evidence' => ['二十万字']];
    bookGenerate($task, $data);
    expect($task->fresh()->target_words)->toBe(200000)->and($intent['target_source'])->toBe('system_default');
});

test('same novel cannot fall back to legacy planning or chapter creation', function () {
    $task = bookTask();
    expect(fn () => app(NovelOutlinePipeline::class)->prepareBatch($task->novel, 1))->toThrow(LogicException::class);
    expect(fn () => app(GenerateNextChapterAction::class)->handle($task->novel))->toThrow(ValidationException::class);
    expect($task->novel->chapters()->count())->toBe(0);
});

test('whole book adoption failure remains a separate visible issue after successful model generation', function () {
    $task = bookTask();
    $foundation = bookGenerate($task);
    bookPlanningFixtures($task, $foundation);
    $this->mock(InitializeNovelStateAction::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('injected adoption failure'));
    expect(fn () => app(NovelBookWorkflow::class)->control($task->id, $task->fresh()->control_version, 'resume', false))->toThrow(RuntimeException::class);
    app(GenerationIssueService::class)->reconcile();
    expect($task->fresh()->status)->toBe('needs_attention')->and(GenerationIssue::first()->status)->toBe('awaiting_user')
        ->and(GenerationIssue::first()->error_code)->toBe('initialization_validation_failed')->and($task->novel->storyStateVersions()->count())->toBe(0)
        ->and($foundation->fresh()->status->value)->toBe('succeeded');
});

test('manual mode waits only for a PASS tied to the current draft and state', function () {
    $task = bookTask(['mode' => 'manual_review']);
    $task->update(['status' => 'running']);
    $state = StoryStateVersion::factory()->for($task->novel)->create(['version' => 0]);
    $task->novel->update(['canonical_state_version_id' => $state->id]);
    $chapter = Chapter::factory()->for($task->novel)->create();
    $task->update(['current_chapter_id' => $chapter->id]);
    $run = GenerationRun::factory()->create(['novel_id' => $task->novel_id, 'chapter_id' => $chapter->id, 'stage' => GenerationStage::Review, 'status' => 'succeeded', 'state_version' => 0]);
    $draft = GenerationArtifact::factory()->create(['generation_run_id' => $run->id, 'type' => ArtifactType::ChapterDraft]);
    $chapter->update(['current_draft_artifact_id' => $draft->id]);
    $result = GenerationArtifact::factory()->create(['generation_run_id' => $run->id, 'type' => ArtifactType::ReviewResult, 'data' => ['source_artifact_id' => $draft->id]]);
    Review::factory()->create(['generation_run_id' => $run->id, 'artifact_id' => $result->id, 'decision' => 'PASS']);
    expect(app(NovelBookWorkflow::class)->chapterBoundary($task->id))->toBe('awaiting_manual_commit')->and($task->fresh()->status)->toBe('paused');
    expect(fn () => app(NovelBookWorkflow::class)->control($task->id, $task->fresh()->control_version, 'resume', false))->toThrow(LogicException::class);
});

// 修复必须沿用已失败候选和用户原文，不能只把 Issue 改成 resolved。
test('foundation field repair keeps rejected evidence and verifies a new metered candidate once', function () {
    $task = bookTask();
    $data = bookFoundation(GenerationArtifact::findOrFail($task->intent_artifact_id)->data);
    $broken = $data;
    unset($broken['fields']['world']);
    $failed = bookGenerate($task, $broken);
    $issue = GenerationIssue::firstOrFail();
    $rejected = $failed->artifacts()->firstOrFail();
    // 异常页提供实际可执行的修复选项，人工说明仍独立保留。
    $this->actingAs(User::factory()->create());
    Livewire::test(GenerationIssues::class)->call('selectIssue', $issue->id)
        ->assertSee('请 AI 修复设定字段')->assertSee('保存人工处理说明');
    $fake = (new FakeAiProvider)->enqueue(new AiResponse(json_encode($data), $data, 100, 200, 0, 10, 'fixture-repaired', 'book-fixture'));
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));
    $actions = app(GenerationIssueActions::class);
    $key = (string) Str::uuid();
    $repair = $actions->execute($issue->id, 'repair_foundation', $issue->fingerprint, $task->fresh()->control_version, $key, '补齐 world，保留其他设定。', dispatch: false);
    expect($actions->execute($issue->id, 'repair_foundation', $issue->fingerprint, $task->fresh()->control_version, $key, '补齐 world，保留其他设定。', dispatch: false)->id)->toBe($repair->id);
    (new ExecuteGenerationRunJob($repair->id))->handle(app(GenerationRunCoordinator::class));
    (new ExecuteGenerationRunJob($repair->id))->handle(app(GenerationRunCoordinator::class));
    app(GenerationIssueService::class)->reconcile();
    expect($repair->fresh()->status->value)->toBe('succeeded')->and($issue->fresh()->status)->toBe('resolved')
        ->and($fake->requests())->toHaveCount(1)->and($rejected->fresh()->validation_status->value)->toBe('rejected')
        ->and($issue->fresh()->attempt_count)->toBe(1)->and($task->fresh()->status)->toBe('running');
    // 问题真实结案后不能继续显示旧的停止原因。
    expect($task->fresh()->stop_reason)->toBeNull();
    $this->assertDatabaseHas('usage_records', ['generation_run_id' => $repair->id, 'issue_id' => $issue->id, 'status' => 'settled']);
    expect($fake->requests()[0]->prompt)->toContain('candidate')->toContain('补齐 world');
});

// 失败修复没有获得用户裁决权，也不能借新 Prompt 消除来源门禁。
test('foundation repair cannot decide user conflicts', function () {
    $task = bookTask();
    $data = bookFoundation(GenerationArtifact::findOrFail($task->intent_artifact_id)->data);
    $data['conflicts'] = [['id' => 'choice', 'question' => '采用哪项？', 'requirement_ids' => ['r1'], 'options' => ['保留旧港', '改为山城']]];
    bookGenerate($task, $data);
    $issue = GenerationIssue::firstOrFail();
    expect(app(GenerationIssueActions::class)->preview($issue, 'repair_foundation')['available'])->toBeFalse();
    expect($issue->fresh()->status)->toBe('awaiting_user');
});

// 从概述提取的数值与显式表单遵守相同范围，禁止数据库写入之后才报错。
test('out of range premise length fails before creating a book', function (string $premise) {
    expect(fn () => app(CreateNovelGenerationTaskAction::class)->handle((string) Str::uuid(), $premise))->toThrow(ValidationException::class);
    expect(Novel::count())->toBe(0);
})->with(['全书0字，港城故事。', '全书99999999999字，港城故事。']);

test('explicit form length accepts premise evidence or no quote but rejects invented field citations', function (array $evidence, bool $valid) {
    $task = bookTask(['target_words' => 30000]);
    $data = bookFoundation(GenerationArtifact::findOrFail($task->intent_artifact_id)->data);
    $data['length'] = ['target_words' => 30000, 'source' => 'user', 'evidence' => $evidence];

    $run = bookGenerate($task, $data, pauseForHandoff: false);

    expect($run->status->value)->toBe($valid ? 'succeeded' : 'failed');
    expect($run->error_code)->toBe($valid ? null : 'foundation_evidence_length');
})->with([
    'form without fabricated prose' => [[], true],
    'original prose quote' => [['雨季的旧港'], true],
    'field path is not prose' => [['explicit.target_words: 30000'], false],
    'serialized field is not prose' => [['"target_words":30000'], false],
]);
