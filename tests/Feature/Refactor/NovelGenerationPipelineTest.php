<?php

use App\Actions\Chapters\ReviseBookChapterDraftAction;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\TrackingAiProvider;
use App\AI\UsageRecorder;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Filament\Pages\GenerationIssues;
use App\Filament\Resources\Novels\Pages\ViewBookChapter;
use App\Filament\Resources\Novels\Pages\ViewNovelPlanning;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\NovelOutline;
use App\Models\Review;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\BookChapterRepair;
use App\Services\ChapterDraftService;
use App\Services\GenerationIssueActions;
use App\Services\GenerationIssueService;
use App\Services\GenerationRequestBudget;
use App\Services\GenerationRequestContract;
use App\Services\GenerationRunCoordinator;
use App\Services\GenerationStageGraph;
use App\Services\NovelBookWorkflow;
use App\Services\NovelChapterPipeline;
use App\Services\NovelPlanningService;
use App\Services\NovelStageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/NovelPipelineFixtures.php';

test('one premise reaches independent review without scenes or canonical writes', function () {
    $task = pipelineTask();
    foreach (range(1, 7) as $i) {
        $run = pipelineStep($task);
        expect($run->status->value)->toBe('succeeded', $run->error_message ?? '');
        if ($i < 7) {
            expect($task->fresh()->status)->toBe('running', mb_substr((string) GenerationRun::where('generation_task_id', $task->id)->where('status', 'failed')->latest('id')->value('error_message'), 0, 400));
        }
    }
    $chapter = Chapter::sole();
    expect($chapter->title)->toBe('雨中的未来来信');
    expect($chapter->scenes()->count())->toBe(0);
    expect($chapter->current_draft_artifact_id)->not->toBeNull();
    expect($chapter->canonical_artifact_id)->toBeNull();
    expect($task->novel->storyEvents()->count())->toBe(0);
    expect(Review::sole()->decision->value)->toBe('PASS');
    expect(Review::sole()->score)->toBeNull();
    expect($task->fresh()->stop_reason)->toBe('awaiting_manual_commit');
    Queue::assertPushed(ExecuteGenerationRunJob::class);
});

test('duplicate delivery neither calls provider nor replaces draft', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'write');
    $run = pipelineStep($task);
    $draft = Chapter::sole()->current_draft_artifact_id;
    $fake = new FakeAiProvider;
    app()->instance(AiProvider::class, $fake);
    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    expect($fake->requests())->toBe([]);
    expect(Chapter::sole()->current_draft_artifact_id)->toBe($draft);
});

test('invalid extraction evidence repairs only extraction and preserves body', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'extract');
    $draft = Chapter::sole()->current_draft_artifact_id;
    $run = pipelineStep($task, ['events' => [['wrong' => 'schema']]]);
    expect($run->status->value)->toBe('failed');
    $repair = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    expect($repair->execution_contract['book_stage'])->toBe('extract');
    expect($repair->execution_contract['issue_action'])->toBe('repair_book');
    expect($repair->execution_contract['input']['repair']['validation_message'])->toBe($run->error_message);
    pipelineStep($task);
    expect(Chapter::sole()->current_draft_artifact_id)->toBe($draft);
    expect(GenerationIssue::sole()->status)->toBe('resolved');
    expect(GenerationRun::where('execution_contract->book_stage', 'write')->count())->toBe(1);
});

test('provider rejected writer response is retained and repaired within the original issue budget', function () {
    $task = pipelineTask();
    $run = pipelineUntil($task, 'write');
    $data = ['body' => '林舟站在港口。', 'complete' => false, 'temporary_state' => [], 'additionalProperties' => false];
    $response = new AiResponse(json_encode($data, JSON_UNESCAPED_UNICODE), $data, 100, 200, 0, 10, 'rejected-writer', 'pipeline-fixture');
    $fake = (new FakeAiProvider)->enqueue(new AiProviderException('provider_schema_validation_failed', 'invalid schema', false, 200, usageResponse: $response));
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));

    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));

    expect($fake->requests())->toHaveCount(1);
    expect($run->fresh()->error_code)->toBe('provider_schema_validation_failed');
    $saved = $run->artifacts()->sole();
    expect($saved->validation_status->value)->toBe('rejected');
    expect($saved->content)->toBe($response->content);
    expect($saved->data['candidate'])->toEqual($data);
    expect(Chapter::sole()->current_draft_artifact_id)->toBeNull();
    expect(UsageRecord::where('generation_run_id', $run->id)->sole()->output_tokens)->toBe(200);
    $repair = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    expect(data_get($repair->execution_contract, 'input.repair.candidate'))->toBe($response->content);
    expect(GenerationIssue::sole()->attempt_count)->toBe(1);
    pipelineStep($task);
    expect(GenerationIssue::sole()->status)->toBe('resolved');
    expect(Chapter::sole()->canonical_artifact_id)->toBeNull();
});

test('provider schema failure without saved response cannot offer a blind repair', function () {
    $task = pipelineTask();
    $run = pipelineUntil($task, 'write');
    $fake = (new FakeAiProvider)->enqueue(new AiProviderException('provider_schema_validation_failed', 'invalid schema', false));
    app()->instance(AiProvider::class, new TrackingAiProvider($fake, app(UsageRecorder::class)));

    (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));

    expect($fake->requests())->toHaveCount(1);
    expect($run->artifacts()->count())->toBe(0);
    expect(GenerationIssue::sole()->attempt_count)->toBe(0);
    expect(app(GenerationIssueActions::class)->preview(GenerationIssue::sole(), 'repair_book')['available'])->toBeFalse();
});

test('important paragraph repair is bounded and requires new independent review', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    $old = GenerationArtifact::findOrFail(Chapter::sole()->current_draft_artifact_id);
    pipelineStep($task, ['findings' => [['rule_id' => 'motivation', 'severity' => 'important', 'message' => '缺少动机', 'repair_instruction' => '增加动机', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]]);
    $repair = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    expect($repair->execution_contract['book_stage'])->toBe('rewrite');
    $repaired = pipelineStep($task, ['replacements' => [['paragraph_key' => 'p1', 'expected_hash' => $old->data['paragraph_index'][0]['hash'], 'text' => '林舟站在港口，为了找回父亲的消息，他决定沿岸追查来信。']]]);
    expect($repaired->status->value)->toBe('succeeded', $repaired->error_message ?? '');
    expect($task->fresh()->status)->toBe('running', (string) GenerationRun::where('status', 'failed')->latest('id')->value('error_message'));
    $new = GenerationArtifact::findOrFail(Chapter::sole()->current_draft_artifact_id);
    expect($new->id)->not->toBe($old->id);
    expect(explode("\n\n", $new->content)[1])->toBe(explode("\n\n", $old->content)[1]);
    expect(GenerationIssue::sole()->status)->not->toBe('resolved');
    pipelineStep($task);
    pipelineStep($task);
    expect(GenerationIssue::sole()->status)->toBe('resolved');
    // 改写后的提取与独立审校仍归属原问题，既不能漏计费用，也不能刷新修复次数。
    $issue = GenerationIssue::sole();
    expect($issue->attempt_count)->toBe(1);
    expect(GenerationRun::where('issue_id', $issue->id)->where('execution_contract->repair_chain_issue_id', $issue->id)->count())->toBe(3);
    expect(DB::table('usage_records')->where('issue_id', $issue->id)->count())->toBe(4); // 首次失败审校加三次修复/复验请求。
    expect(Chapter::sole()->canonical_artifact_id)->toBeNull();
});

test('a successful review response cannot resolve the content issue it just created', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    $run = pipelineStep($task, ['findings' => [['rule_id' => 'timeline', 'severity' => 'uncertain', 'message' => '时间先后需要核对', 'repair_instruction' => '核对原文', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]]);
    expect($run->status->value)->toBe('succeeded');
    $issue = GenerationIssue::sole();
    foreach (range(1, 3) as $attempt) {
        expect(app(GenerationIssueService::class)->verifySuccess($run->fresh()))->toBeFalse();
        app(NovelBookWorkflow::class)->advance($task->id, false);
    }
    expect($issue->fresh()->status)->not->toBe('resolved');
    expect(GenerationIssue::count())->toBe(1);
    expect($task->fresh()->status)->toBe('needs_attention');
    expect(Chapter::sole()->canonical_artifact_id)->toBeNull();
});

test('manual edit preserves history and invalidates old review with CAS', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    pipelineStep($task);
    $chapter = Chapter::sole();
    $old = GenerationArtifact::findOrFail($chapter->current_draft_artifact_id);
    $version = $task->fresh()->control_version;
    $key = (string) Str::uuid();
    $action = app(ReviseBookChapterDraftAction::class);
    $body = $old->content."\n\n邮局门前，他发现第二封信。";
    $new = $action->handle($chapter->id, $old->id, $old->checksum, $version, $body, $key, false);
    expect($new->content)->toBe($body);
    expect($action->handle($chapter->id, $old->id, $old->checksum, $version, $body, $key, false)->id)->toBe($new->id);
    expect(fn () => $action->handle($chapter->id, $old->id, $old->checksum, $version, $body.'新', (string) Str::uuid(), false))->toThrow(LogicException::class);
    expect(Review::count())->toBe(1);
    expect(GenerationRun::findOrFail($task->fresh()->pending_transition['run_id'])->execution_contract['book_stage'])->toBe('extract');
    expect($old->fresh()->content)->not->toBe($body);
});

test('duplicate quotes use exact paragraph offsets and local hash guard', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'write');
    pipelineStep($task, ['body' => "雨落下了。\n\n雨落下了。林舟走进邮局。", 'complete' => true, 'temporary_state' => []]);
    $draft = GenerationArtifact::findOrFail(Chapter::sole()->current_draft_artifact_id);
    $service = app(ChapterDraftService::class);
    expect($service->evidence($draft, ['paragraph_key' => 'p2', 'offset' => 0, 'quote' => '雨落下了。'])['start_offset'])->toBe(7);
    expect(fn () => $service->evidence($draft, ['paragraph_key' => 'p2', 'offset' => 1, 'quote' => '雨落下了。']))->toThrow(AiProviderException::class);
    expect(fn () => $service->replace($draft, [['paragraph_key' => 'p1', 'expected_hash' => 'wrong', 'text' => '改文']], ['p1']))->toThrow(AiProviderException::class);
});

test('locked fact conflict blocks even when independent narrative checks are satisfied', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'plan');
    $character = $task->novel->characters()->sole();
    Fact::factory()->for($task->novel)->create(['subject_type' => 'character', 'subject_id' => $character->id, 'predicate' => 'status', 'value' => ['value' => 'dead'], 'locked' => true, 'status' => 'active']);
    app(NovelPlanningService::class)->reviseGoal($task->id, $task->fresh()->control_version, $task->novel->fresh()->current_goal_window_id, 'chapter-1', '遵循锁定事实追查线索', true);
    app(NovelBookWorkflow::class)->advance($task->id, false);
    pipelineUntil($task, 'extract');
    pipelineStep($task, ['events' => [['event_type' => 'character_status_changed', 'subject_type' => 'character', 'subject_id' => (string) $character->id, 'payload' => '{"status":"alive"}', 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]]]]);
    pipelineStep($task);
    expect(Review::sole()->decision->value)->toBe('BLOCK');
    expect($task->novel->storyEvents()->count())->toBe(0);
    expect($task->novel->storyStateVersions()->count())->toBe(1);
});

test('style suggestions do not force rewrite', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    pipelineStep($task, ['findings' => [['rule_id' => 'style', 'severity' => 'suggestion', 'message' => '可调整语气', 'repair_instruction' => '可选润色', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]]);
    expect(Review::sole()->decision->value)->toBe('PASS');
    expect(GenerationRun::where('execution_contract->book_stage', 'rewrite')->count())->toBe(0);
});

test('truncated writing retains complete prefix and resumes only missing suffix', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'write');
    $first = pipelineStep($task, ['body' => '林舟站在港口。他打开信封。未完整', 'complete' => true, 'temporary_state' => []], ['finish_reason' => 'length']);
    expect(Chapter::sole()->current_draft_artifact_id)->toBeNull();
    $next = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    expect($next->execution_contract['input']['previous_tail'])->toBe('林舟站在港口。他打开信封。');
    pipelineStep($task, ['body' => '信中写着明日洪水将至。', 'complete' => true, 'temporary_state' => []]);
    $body = GenerationArtifact::findOrFail(Chapter::sole()->current_draft_artifact_id)->content;
    expect($body)->toBe("林舟站在港口。他打开信封。\n\n信中写着明日洪水将至。");
    expect($first->fresh()->status->value)->toBe('succeeded');
});

test('writer pauses after in flight response and resumes without repeating provider', function () {
    $task = pipelineTask();
    $run = pipelineUntil($task, 'write');
    $coordinator = app(GenerationRunCoordinator::class);
    $lease = $coordinator->claimExecution($run->id);
    $response = ['body' => '林舟站在港口。', 'complete' => true, 'temporary_state' => []];
    $fake = (new FakeAiProvider)->enqueue(new AiResponse(json_encode($response), $response, 1, 1, 0, 1, 'pause-fixture', 'pipeline-fixture'));
    $inFlight = new class($fake, $task) implements AiProvider
    {
        public function __construct(private FakeAiProvider $fake, private NovelGenerationTask $task) {}

        public function generate(AiRequest $request): AiResponse
        {
            app(GenerationRunCoordinator::class)->control($this->task->id, $this->task->fresh()->control_version, 'pause');

            return $this->fake->generate($request);
        }
    };
    app()->instance(AiProvider::class, new TrackingAiProvider($inFlight, app(UsageRecorder::class)));
    app(NovelStageContract::class)->execute($run, $lease);
    expect(app(NovelBookWorkflow::class)->advance($task->id, false))->toBeNull();
    expect(Chapter::sole()->current_draft_artifact_id)->toBeNull();
    app(NovelBookWorkflow::class)->control($task->id, $task->fresh()->control_version, 'resume', false);
    expect(Chapter::sole()->current_draft_artifact_id)->not->toBeNull();
    expect($fake->requests())->toHaveCount(1);
});

test('reviewer capacity blocks before writing and rebuild reuses successful planner', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'plan');
    createAiModelRouteForTest(AiStage::Reviewer, 'small-reviewer');
    AIModelPrice::where('model', 'small-reviewer')->update(['context_window_tokens' => 1000, 'max_output_tokens' => 500]);
    pipelineStep($task);
    expect(GenerationRun::where('execution_contract->book_stage', 'write')->count())->toBe(0);
    $issue = GenerationIssue::sole();
    expect($issue->category)->toBe('capacity');
    createAiModelRouteForTest(AiStage::Reviewer, 'pipeline-fixture');
    $actions = app(GenerationIssueActions::class);
    // 暂停不能被人工容量重建绕过；恢复后仍复用已完成规划。
    app(GenerationRunCoordinator::class)->control($task->id, $task->fresh()->control_version, 'pause');
    expect($actions->preview($issue, 'rebuild_book_context')['available'])->toBeFalse();
    $task->refresh()->update(['status' => 'needs_attention']);
    $preview = $actions->preview($issue, 'rebuild_book_context');
    $actions->execute($issue->id, 'rebuild_book_context', $issue->fingerprint, $preview['control_version'], (string) Str::uuid(), dispatch: false);
    expect($issue->fresh()->status)->toBe('resolved');
    expect(GenerationRun::where('execution_contract->book_stage', 'plan')->count())->toBe(1);
    expect(GenerationRun::where('execution_contract->book_stage', 'write')->count())->toBe(1);
});

test('future goal revision preserves active draft identity while current revision invalidates plan', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'extract');
    $chapter = Chapter::sole();
    $draftId = $chapter->current_draft_artifact_id;
    $window = NovelOutline::findOrFail($task->novel->fresh()->current_goal_window_id);
    $service = app(NovelPlanningService::class);
    $candidate = $service->reviseGoal($task->id, $task->fresh()->control_version, $window->id, 'chapter-2', '追查邮局暗号');
    expect($task->novel->fresh()->current_goal_window_id)->toBe($window->id);
    expect($candidate->sourceArtifact->data['goals'][0]['content_hash'])->toBe($window->sourceArtifact->data['goals'][0]['content_hash']);
    expect($chapter->fresh()->current_draft_artifact_id)->toBe($draftId);
    $next = $service->reviseGoal($task->id, $task->fresh()->control_version, $window->id, 'chapter-3', '查明邮局失踪者');
    expect($next->sourceArtifact->data['goals'][1]['objective'])->toBe('追查邮局暗号');
    expect($next->sourceArtifact->data['goals'][2]['objective'])->toBe('查明邮局失踪者');
    expect(fn () => $service->reviseGoal($task->id, $task->fresh()->control_version, $window->id, 'chapter-1', '查明信封来源'))->toThrow(LogicException::class);
    $service->reviseGoal($task->id, $task->fresh()->control_version, $window->id, 'chapter-1', '查明信封来源', true);
    expect($chapter->fresh()->current_plan_id)->toBeNull();
    expect(GenerationArtifact::find($draftId))->not->toBeNull();
});

test('planning and draft pages expose real sources and preserve failed manual input', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    pipelineStep($task);
    $this->actingAs(User::factory()->create());
    Livewire::test(ViewNovelPlanning::class, ['record' => $task->novel_id])->assertOk()->assertSee('全书蓝图')->assertSee('近期章节目标')->assertSee('雨中的未来来信');
    $page = Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => Chapter::sole()->id]);
    $page->assertOk()->assertSee('尚未正式采用')->assertSee('保存新稿并复验');
    $page->set('body', '')->call('saveDraft')->assertSee('请填写新稿正文，原稿仍然保留。')
        ->assertDispatched('book-edit-feedback', target: 'book-save-draft');
    $page->set('body', '需要保留的人工输入');
    $task->refresh()->increment('control_version');
    $page->call('saveDraft')->assertSet('body', '需要保留的人工输入')->assertSee('当前稿或任务状态已变化')
        ->assertDispatched('book-edit-feedback', target: 'book-save-draft');
    // 冲突不能自动重绑；用户明确读取最新来源后，输入仍在且可以建立独立新稿。
    $oldDraftId = Chapter::sole()->current_draft_artifact_id;
    $page->call('refreshEditingSource')->assertSet('body', '需要保留的人工输入')
        ->assertSet('controlVersion', $task->fresh()->control_version)->assertSee('请对照上方正文确认')
        ->assertDispatched('book-edit-feedback', target: 'book-refresh-source');
    expect(Chapter::sole()->current_draft_artifact_id)->toBe($oldDraftId);
    $page->call('saveDraft')->assertSee('新稿已保存')->assertDispatched('book-edit-feedback', target: 'book-save-draft');
    expect(Chapter::sole()->current_draft_artifact_id)->not->toBe($oldDraftId);
    expect(Chapter::sole()->canonical_artifact_id)->toBeNull();
});

test('planning editors keep independent versions and explicitly refresh without losing input', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    pipelineStep($task);
    $this->actingAs(User::factory()->create());
    $chapter = Chapter::sole();
    $blueprintId = $task->novel->fresh()->current_blueprint_id;
    $windowId = $task->novel->fresh()->current_goal_window_id;
    $version = $task->fresh()->control_version;
    $outlineCount = NovelOutline::count();
    $page = Livewire::test(ViewNovelPlanning::class, ['record' => $task->novel_id]);
    $page->call('editDirection')->set('futureDirection', '调查港口失踪案');
    $task->refresh()->increment('control_version');
    // 打开目标编辑器不能替方向编辑器接受新的控制版本。
    $page->call('editGoal', 'chapter-2')->set('goalText', '追查邮局暗号')
        ->assertSet('directionTaskVersion', $version)->assertSet('taskVersion', $version + 1)
        ->call('saveDirection')->assertSee('蓝图来源已改变')->assertSet('futureDirection', '调查港口失踪案');
    expect(NovelOutline::count())->toBe($outlineCount);
    $page->call('editDirection')->assertSet('futureDirection', '调查港口失踪案')
        ->call('editGoal', 'chapter-3')->assertSet('goalKey', 'chapter-2')->assertSet('goalText', '追查邮局暗号');
    $page->call('refreshDirectionSource')->assertSet('futureDirection', '调查港口失踪案')
        ->assertSet('directionTaskVersion', $version + 1)->assertSee('本次读取未修改蓝图或正文');
    expect(NovelOutline::count())->toBe($outlineCount);
    $page->call('saveDirection')->assertSet('editingDirection', false)->assertSee('未来方向候选已保存');
    expect(NovelOutline::count())->toBe($outlineCount + 1);

    // 方向保存产生新控制版本后，目标仍须显式刷新，不能共享方向的版本。
    $page->call('saveGoal')->assertSee('来源版本已改变')->assertSet('goalText', '追查邮局暗号');
    expect(NovelOutline::count())->toBe($outlineCount + 1);
    $page->call('refreshGoalSource')->assertSet('goalText', '追查邮局暗号')
        ->assertSet('taskVersion', $task->fresh()->control_version)->assertSee('本次读取未修改计划或正文');
    expect(NovelOutline::count())->toBe($outlineCount + 1);
    $page->call('saveGoal')->assertSet('goalKey', '')->assertSee('修订候选已保存');
    expect(NovelOutline::count())->toBe($outlineCount + 2);
    expect($task->novel->fresh()->current_blueprint_id)->toBe($blueprintId);
    expect($task->novel->fresh()->current_goal_window_id)->toBe($windowId);
    expect($chapter->fresh()->current_draft_artifact_id)->toBe($chapter->current_draft_artifact_id);
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
    expect($task->novel->storyEvents()->count())->toBe(0);
});

test('planning refresh preserves input and old identity when goal is no longer in current window', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    $this->actingAs(User::factory()->create());
    $page = Livewire::test(ViewNovelPlanning::class, ['record' => $task->novel_id]);
    $page->call('editGoal', 'chapter-2')->set('goalText', '需要保留的手工修订');
    $version = $task->fresh()->control_version;
    $window = NovelOutline::findOrFail($task->novel->fresh()->current_goal_window_id);
    // 用新不可变窗口模拟进度推进，不篡改用户开始编辑时的旧产物。
    $data = $window->sourceArtifact->data;
    $data['goals'] = array_values(array_filter($data['goals'], fn ($goal) => $goal['key'] !== 'chapter-2'));
    $artifact = $window->sourceArtifact->replicate();
    $artifact->version++;
    $artifact->source_artifact_id = $window->source_artifact_id;
    $artifact->data = $data;
    $artifact->content = json_encode($data, JSON_UNESCAPED_UNICODE);
    $artifact->checksum = hash('sha256', $artifact->content);
    $artifact->save();
    $next = $window->replicate();
    $next->source_artifact_id = $artifact->id;
    $next->checksum = $artifact->checksum;
    $next->version++;
    $window->update(['status' => 'superseded']);
    $next->save();
    $task->novel->update(['current_goal_window_id' => $next->id]);
    $task->refresh()->increment('control_version');
    $count = NovelOutline::count();
    $page->call('refreshGoalSource')->assertSee('此目标已不在当前窗口')
        ->assertSet('goalText', '需要保留的手工修订')->assertSet('goalKey', 'chapter-2')
        ->assertSet('windowVersion', $window->id)->assertSet('taskVersion', $version);
    expect(NovelOutline::count())->toBe($count);
    expect($window->fresh()->sourceArtifact->data['goals'])->toHaveCount(count($data['goals']) + 1);
    expect(Chapter::sole()->canonical_artifact_id)->toBeNull();
});

test('chapter reader rejects cross novel chapter identity', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'write');
    $other = Novel::factory()->create();
    $chapter = Chapter::factory()->for($other)->create();
    $this->actingAs(User::factory()->create());
    Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => $chapter->id])->assertNotFound();
});

test('necessary sequential fragments reuse completed prefix and assemble exactly once', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'plan');
    createAiModelRouteForTest(AiStage::Writer, 'small-writer');
    AIModelPrice::where('model', 'small-writer')->update(['max_output_tokens' => 1300]);
    setGenerationSettingsForTest(['chapter_request_budgets.writer.initial.reasoning_reserve_tokens' => 100]);
    pipelineStep($task);
    $plan = ChapterPlan::sole();
    expect($plan->admission_snapshot['writer_strategy'])->toBe('segments');
    expect($plan->admission_snapshot['fragment_count'])->toBe(3);
    foreach (['林舟站在港口，雨打湿了信封。', '他沿街向前，推开邮局门。', '门后有人将第二封信交到他手中。'] as $text) {
        $run = pipelineStep($task, ['body' => $text, 'complete' => true, 'temporary_state' => ['已推进当前段']]);
        (new ExecuteGenerationRunJob($run->id))->handle(app(GenerationRunCoordinator::class));
    }
    $draft = GenerationArtifact::findOrFail(Chapter::sole()->current_draft_artifact_id);
    expect($draft->source_manifest)->toHaveCount(3);
    expect($draft->data['paragraph_index'])->toHaveCount(3);
    expect(GenerationArtifact::where('type', 'chapter_draft')->count())->toBe(1);
});

test('late AI response after manual edit cannot overwrite or block the new draft', function () {
    $task = pipelineTask();
    $run = pipelineUntil($task, 'review');
    $coordinator = app(GenerationRunCoordinator::class);
    $lease = $coordinator->claimExecution($run->id);
    $chapter = Chapter::sole();
    $old = GenerationArtifact::findOrFail($chapter->current_draft_artifact_id);
    $new = app(ReviseBookChapterDraftAction::class)->handle($chapter->id, $old->id, $old->checksum, $task->fresh()->control_version, $old->content."\n\n他在门前停下。", (string) Str::uuid(), false);
    $late = $coordinator->completeExecution($run->id, $lease['token'], $lease['control_version'], ArtifactType::ReviewResult, ['decision' => 'PASS']);
    expect($late->validation_status->value)->toBe('rejected');
    expect($run->fresh()->status->value)->toBe('cancelled');
    expect($chapter->fresh()->current_draft_artifact_id)->toBe($new->id);
    app(NovelBookWorkflow::class)->advance($task->id, false);
    expect(GenerationRun::findOrFail($task->fresh()->pending_transition['run_id'])->execution_contract['book_stage'])->toBe('extract');
});

test('new locked fact invalidates frozen chapter input before another provider call', function () {
    $task = pipelineTask();
    $run = pipelineUntil($task, 'write');
    $character = $task->novel->characters()->sole();
    Fact::factory()->for($task->novel)->create(['subject_type' => 'character', 'subject_id' => $character->id, 'predicate' => 'status', 'value' => ['value' => 'dead'], 'locked' => true, 'status' => 'active']);
    expect(fn () => app(GenerationRunCoordinator::class)->assertSources($task->fresh(), $run->source_manifest, $run->execution_contract))->toThrow(LogicException::class, '锁定事实');
});

test('future blueprint revision is immutable and waits for chapter boundary', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'write');
    $old = NovelOutline::findOrFail($task->novel->fresh()->current_blueprint_id);
    $candidate = app(NovelPlanningService::class)->reviseBlueprint($task->id, $task->fresh()->control_version, $old->id, '未来转向调查港口失踪案');
    expect($candidate->kind)->toBe('blueprint');
    expect($task->novel->fresh()->current_blueprint_id)->toBe($old->id);
    expect($old->fresh()->sourceArtifact->data['mainline'])->toBe('追查来信');
    expect(app(NovelPlanningService::class)->adoptPendingBlueprint($task->fresh()))->toBeNull();
});

test('R04 schema keeps Chinese metadata and refuses lossy rollback', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    pipelineStep($task);
    $rows = DB::select("SELECT col_description(c.oid,a.attnum) AS description FROM pg_class c JOIN pg_attribute a ON a.attrelid=c.oid WHERE (c.relname='x_reviews' AND a.attname='score') OR (c.relname='x_chapter_plans' AND a.attname='primary_outline_arc_id')");
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect(preg_match('/[\x{4e00}-\x{9fff}]/u', $row->description))->toBe(1);
    }
    $migration = require database_path('migrations/2026_10_18_115324_allow_unscored_narrative_reviews.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, '未评分');
    $migration = require database_path('migrations/2026_10_18_115535_allow_three_layer_chapter_plan_sources.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, '三层规划');
});

test('actual reviewer overflow preserves body and re-admits without another writer call', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'plan');
    createAiModelRouteForTest(AiStage::Reviewer, 'limited-review');
    AIModelPrice::where('model', 'limited-review')->update(['context_window_tokens' => 60000]);
    pipelineStep($task);
    $body = '林舟站在港口。'.str_repeat('雨落在信封上。', 5000);
    pipelineStep($task, ['body' => $body, 'complete' => true, 'temporary_state' => []]);
    $draft = Chapter::sole()->current_draft_artifact_id;
    pipelineStep($task);
    $issue = GenerationIssue::sole();
    expect($issue->category)->toBe('capacity');
    expect(GenerationArtifact::findOrFail($draft)->content)->toBe($body);
    AIModelPrice::where('model', 'limited-review')->update(['context_window_tokens' => 300000]);
    $actions = app(GenerationIssueActions::class);
    $preview = $actions->preview($issue, 'rebuild_book_context');
    $actions->execute($issue->id, 'rebuild_book_context', $issue->fingerprint, $preview['control_version'], (string) Str::uuid(), dispatch: false);
    expect(Chapter::sole()->current_draft_artifact_id)->toBe($draft);
    expect(ChapterPlan::count())->toBe(2);
    expect(GenerationRun::where('execution_contract->book_stage', 'write')->count())->toBe(1);
    expect(GenerationRun::findOrFail($task->fresh()->pending_transition['run_id'])->execution_contract['book_stage'])->toBe('extract');
});

test('no progress local rewrite stops without resetting attempts', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    $old = GenerationArtifact::findOrFail(Chapter::sole()->current_draft_artifact_id);
    pipelineStep($task, ['findings' => [['rule_id' => 'motivation', 'severity' => 'important', 'message' => '缺动机', 'repair_instruction' => '补充动机', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]]);
    $repaired = pipelineStep($task, ['replacements' => [['paragraph_key' => 'p1', 'expected_hash' => $old->data['paragraph_index'][0]['hash'], 'text' => explode("\n\n", $old->content)[0]]]]);
    expect($repaired->error_code)->toBe('book_repair_no_progress');
    expect(Chapter::sole()->current_draft_artifact_id)->toBe($old->id);
    $issue = GenerationIssue::sole();
    expect($issue->attempt_count)->toBe(1);
    expect(app(GenerationIssueActions::class)->preview($issue, 'repair_book')['available'])->toBeFalse();
    expect($task->fresh()->status)->toBe('needs_attention');
});

test('stage graph covers all enum cases and whole-chapter rewrite invalidates extraction', function () {
    $graph = app(GenerationStageGraph::class);
    foreach (GenerationStage::cases() as $stage) {
        expect($graph->next($stage))->toBeArray();
        expect($graph->downstream($stage))->toBeArray();
        expect($graph->artifactsProducedBy($stage))->toBeArray();
    }
    expect($graph->next(GenerationStage::Rewrite))->toContain(GenerationStage::EventExtraction);
});

test('successful UI save immediately replaces visible body and old PASS', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    pipelineStep($task);
    $this->actingAs(User::factory()->create());
    $page = Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => Chapter::sole()->id]);
    $page->assertSee('独立审校：PASS');
    $page->set('body', '林舟站在港口。新的人工版本仅用于复验。')->call('saveDraft')->assertSee('新稿已保存')->assertSee('等待当前稿独立审校')->assertDontSee('独立审校：PASS');
    expect(Chapter::sole()->canonical_artifact_id)->toBeNull();
});

test('book requests preserve separate visible and reasoning budgets through frozen routes', function () {
    $task = pipelineTask();
    $service = app(NovelStageContract::class);
    $request = $service->request($task, 'write', [], 9500);
    $contract = app(GenerationRequestContract::class)->freeze($request);
    $configured = app(GenerationRequestBudget::class)->configured(AiStage::Writer)['initial'];

    expect($contract['output_tokens'])->toBe(9500);
    expect($contract['reasoning_reserve_tokens'])->toBe($configured['reasoning_reserve_tokens']);
    expect($request->maxTokens)->toBe(9500 + $configured['reasoning_reserve_tokens']);
    $contract['prompt']['reasoning_effort'] = 'high';
    $contract['reasoning_reserve_tokens'] = 17000;
    $frozen = $service->request($task, 'write', ['frozen_route' => $contract], 9500);

    expect($frozen->reasoningEffort)->toBe('high');
    expect($frozen->maxTokens)->toBe(26500);
    expect($frozen->metadata['reasoning_reserve_tokens'])->toBe(17000);
});

test('book extraction rejects payloads that lose knowledge or invent ability direction', function (string $type, string $payload) {
    $task = pipelineTask();
    pipelineUntil($task, 'extract');
    $id = (string) $task->novel->characters()->sole()->id;
    $run = pipelineStep($task, ['events' => [['event_type' => $type, 'subject_type' => 'character', 'subject_id' => $id, 'payload' => $payload, 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]]]]);
    expect($run->error_code)->toBe('book_schema_invalid');
    expect($run->artifacts()->where('type', ArtifactType::StatePatch)->exists())->toBeFalse();
})->with([
    ['character_learned', '{"fact":"收到来信"}'],
    ['character_learned', '{"value":"收到来信"}'],
    ['character_ability_changed', '{"ability":"读心术","change":"失去"}'],
]);

test('book location updates preserve initialized entity fields', function () {
    $task = pipelineTask();
    $run = pipelineUntil($task, 'extract');
    $id = (string) $task->novel->worldEntities()->sole()->id;
    $state = $run->execution_contract['input']['context']['state'];
    $original = $state['locations'][$id];
    $event = ['event_type' => 'location_state_changed', 'subject_type' => 'location', 'subject_id' => $id, 'payload' => '{"change":"下雨"}', 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]];
    expect(fn () => app(NovelChapterPipeline::class)->validateOutput($run, ['events' => [$event]]))->toThrow(AiProviderException::class, '删除已有对象字段');
    $event['payload'] = json_encode(['state' => [...$original, 'current_state' => ['weather' => '雨季']]], JSON_UNESCAPED_UNICODE);
    $completed = pipelineStep($task, ['events' => [$event]]);
    expect($completed->status->value)->toBe('succeeded');
    $patch = $completed->artifacts()->where('type', ArtifactType::StatePatch)->sole();
    expect($patch->data['operations'][0]['path'])->toBe('locations.'.$id);
    expect($patch->data['operations'][0]['value']['name'])->toBe($original['name']);
    expect($task->novel->fresh()->canonicalStateVersion->state)->toBe($state);
});

test('book admission refuses a model whose output cannot fit its reasoning reserve', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'plan');
    createAiModelRouteForTest(AiStage::Writer, 'reserve-too-large');
    AIModelPrice::where('model', 'reserve-too-large')->update(['max_output_tokens' => 1200]);

    pipelineStep($task);

    expect($task->fresh()->stop_reason)->toBe('book_capacity_exceeded');
    expect(ChapterPlan::count())->toBe(0);
    expect(GenerationRun::where('generation_task_id', $task->id)->where('stage', GenerationStage::ChapterWrite)->count())->toBe(0);
});

test('evidence requests pair stable paragraph identities with exact unicode source text', function () {
    $task = pipelineTask();
    $body = "相同句子。\n\n第二段的第一行。\n第二行保留。\n\n相同句子。";
    $index = app(ChapterDraftService::class)->index($body);
    $request = app(NovelStageContract::class)->request($task, 'review', ['body' => $body, 'paragraph_index' => $index]);
    $input = json_decode($request->prompt, true, flags: JSON_THROW_ON_ERROR);

    expect($input)->not->toHaveKeys(['body', 'paragraph_index']);
    expect($input['body_paragraphs'])->toHaveCount(3);
    expect($input['body_paragraphs'][1])->toBe([...$index[1], 'text' => "第二段的第一行。\n第二行保留。"]);
    expect($input['body_paragraphs'][0]['key'])->toBe('p1');
    expect($input['body_paragraphs'][2]['key'])->toBe('p3');
    expect($input['body_paragraphs'][2]['text'])->toBe('相同句子。');
});

test('invalid event subject combinations retain rejected evidence and offer bounded schema repair', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'extract');

    $run = pipelineStep($task, ['events' => [['event_type' => 'world_rule_revealed', 'subject_type' => 'location', 'subject_id' => '1', 'payload' => '{}', 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]]]]);

    expect($run->error_code)->toBe('book_schema_invalid');
    expect($run->artifacts()->where('type', ArtifactType::EventCandidate)->first()->validation_status->value)->toBe('rejected');
    $issue = GenerationIssue::where('first_run_id', $run->id)->firstOrFail();
    expect($issue->category)->toBe('schema');
    expect($issue->attempt_count)->toBe(1);
    expect($task->novel->storyEvents()->count())->toBe(0);

    $run->update(['error_code' => 'stage_execution_failed']);
    $issue->update(['error_code' => 'stage_execution_failed']);
    $contract = app(BookChapterRepair::class)->prepare($issue->fresh(), '修正主体类型');
    expect($contract['issue_action'])->toBe('repair_book');
    expect($run->fresh()->error_code)->toBe('stage_execution_failed');
    $page = app(GenerationIssues::class);
    $page->instructions = '使用新的主体类型映射复验旧候选';
    expect(collect($page->options($issue->fresh()))->pluck('action'))->not->toContain('repair_book');
    GenerationRun::where('generation_task_id', $task->id)->where('status', 'queued')->update(['status' => 'failed']);
    expect(collect($page->options($issue->fresh()))->pluck('action'))->toContain('repair_book');
    $run->artifacts()->where('type', ArtifactType::EventCandidate)->update(['data' => ['events' => []]]);
    expect(fn () => app(BookChapterRepair::class)->prepare($issue->fresh(), '重试'))->toThrow(LogicException::class);
    expect(collect($page->options($issue->fresh()))->pluck('action'))->not->toContain('repair_book');
});

test('event repair follows the current owned review without rewriting or resetting attempts', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    $finding = ['findings' => [['rule_id' => 'state_path', 'severity' => 'important', 'message' => '事件位置不符', 'repair_instruction' => '只修事件', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]];
    pipelineStep($task, $finding);
    $old = GenerationArtifact::findOrFail(Chapter::sole()->current_draft_artifact_id);
    pipelineStep($task, ['replacements' => [['paragraph_key' => 'p1', 'expected_hash' => $old->data['paragraph_index'][0]['hash'], 'text' => '林舟站在港口，为了找回父亲的消息，他决定沿岸追查来信。']]]);
    pipelineStep($task);
    pipelineStep($task, $finding);
    $issue = GenerationIssue::sole();
    expect($issue->status)->toBe('awaiting_user');
    $currentReview = GenerationRun::findOrFail($issue->latest_run_id);
    expect(Review::where('generation_run_id', $currentReview->id)->exists())->toBeTrue();
    app(GenerationIssueService::class)->observe($currentReview);
    expect($issue->fresh()->status)->toBe('awaiting_user');
    $draft = Chapter::sole()->current_draft_artifact_id;
    $actions = app(GenerationIssueActions::class);
    Chapter::sole()->update(['current_draft_artifact_id' => $old->id]);
    expect(fn () => $actions->preview($issue, 'repair_book_events'))->toThrow(LogicException::class);
    Chapter::sole()->update(['current_draft_artifact_id' => $draft]);
    $preview = $actions->preview($issue, 'repair_book_events');
    expect($preview['available'])->toBeTrue($preview['reason'] ?? '');
    $repair = $actions->execute($issue->id, 'repair_book_events', $issue->fingerprint, $preview['control_version'], (string) Str::uuid(), dispatch: false);
    expect($repair->stage)->toBe(GenerationStage::EventExtraction);
    expect($task->fresh()->stop_reason)->toBeNull();
    pipelineStep($task);
    expect($issue->fresh()->status)->not->toBe('resolved');
    pipelineStep($task);
    expect($issue->fresh()->status)->toBe('resolved');
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect(Chapter::sole()->current_draft_artifact_id)->toBe($draft);
    expect(GenerationRun::where('execution_contract->book_stage', 'rewrite')->count())->toBe(1);
});

test('schema failure after a rewrite repairs the current extraction within the same issue', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    pipelineStep($task, ['findings' => [['rule_id' => 'motivation', 'severity' => 'important', 'message' => '缺动机', 'repair_instruction' => '补充动机', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]]);
    $old = GenerationArtifact::findOrFail(Chapter::sole()->current_draft_artifact_id);
    pipelineStep($task, ['replacements' => [['paragraph_key' => 'p1', 'expected_hash' => $old->data['paragraph_index'][0]['hash'], 'text' => '林舟站在港口，为了找回父亲的消息，他决定沿岸追查来信。']]]);
    $failed = pipelineStep($task, ['events' => [['event_type' => 'world_rule_revealed', 'subject_type' => 'location', 'subject_id' => '1', 'payload' => '{}', 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]]]]);
    $issue = GenerationIssue::sole();
    $actions = app(GenerationIssueActions::class);
    $draftId = Chapter::sole()->current_draft_artifact_id;
    Chapter::sole()->update(['current_draft_artifact_id' => $old->id]);
    expect(fn () => $actions->preview($issue, 'repair_book', '修当前事件结构'))->toThrow(LogicException::class);
    Chapter::sole()->update(['current_draft_artifact_id' => $draftId]);
    $preview = $actions->preview($issue, 'repair_book', '修当前事件结构');
    expect(app(GenerationIssues::class)->details($issue)['source_error'])->toBeNull();
    expect($preview['source_run_id'])->toBe($failed->id);
    expect($preview['available'])->toBeTrue($preview['reason'] ?? '');
    $repair = $actions->execute($issue->id, 'repair_book', $issue->fingerprint, $preview['control_version'], (string) Str::uuid(), '修当前事件结构', dispatch: false);
    expect($repair->stage)->toBe(GenerationStage::EventExtraction);
    expect(data_get($repair->execution_contract, 'input.repair.error'))->toBe('book_schema_invalid');
    expect($issue->fresh()->attempt_count)->toBe(2);
    pipelineStep($task);
    expect($issue->fresh()->status)->toBe('repairing');
    pipelineStep($task);
    expect($issue->fresh()->status)->toBe('resolved');
    expect(Chapter::sole()->current_draft_artifact_id)->toBe($draftId);
});

test('coverage failure after a rewrite repairs the current review without rewriting again', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    pipelineStep($task, ['findings' => [['rule_id' => 'motivation', 'severity' => 'important', 'message' => '缺动机', 'repair_instruction' => '补充动机', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]]);
    $old = GenerationArtifact::findOrFail(Chapter::sole()->current_draft_artifact_id);
    pipelineStep($task, ['replacements' => [['paragraph_key' => 'p1', 'expected_hash' => $old->data['paragraph_index'][0]['hash'], 'text' => '林舟站在港口，为了找回父亲的消息，他决定沿岸追查来信。']]]);
    expect(app(GenerationIssues::class)->details(GenerationIssue::sole())['source_error'])->toBeNull();
    pipelineStep($task);
    $failed = pipelineStep($task, ['findings' => [], 'goal_checks' => []]);
    $issue = GenerationIssue::sole();
    $actions = app(GenerationIssueActions::class);
    $draftId = Chapter::sole()->current_draft_artifact_id;
    Chapter::sole()->update(['current_draft_artifact_id' => $old->id]);
    expect(fn () => $actions->preview($issue, 'repair_book', '补齐当前审校目标覆盖'))->toThrow(LogicException::class);
    Chapter::sole()->update(['current_draft_artifact_id' => $draftId]);
    $preview = $actions->preview($issue, 'repair_book', '补齐当前审校目标覆盖');
    expect(app(GenerationIssues::class)->details($issue)['source_error'])->toBeNull();
    expect($preview['source_run_id'])->toBe($failed->id);
    expect($preview['available'])->toBeTrue($preview['reason'] ?? '');
    $repair = $actions->execute($issue->id, 'repair_book', $issue->fingerprint, $preview['control_version'], (string) Str::uuid(), '补齐当前审校目标覆盖', dispatch: false);
    expect($repair->stage)->toBe(GenerationStage::Review);
    expect(data_get($repair->execution_contract, 'input.repair.error'))->toBe('book_review_coverage_invalid');
    expect($issue->fresh()->attempt_count)->toBe(2);
    pipelineStep($task);
    expect($issue->fresh()->status)->toBe('resolved');
    expect(Chapter::sole()->current_draft_artifact_id)->toBe($draftId);
});

test('lost worker in direct schema repair resumes the owned frozen request', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'extract');
    pipelineStep($task, ['events' => [['event_type' => 'world_rule_revealed', 'subject_type' => 'location', 'subject_id' => '1', 'payload' => '{}', 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]]]]);
    $source = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    $coordinator = app(GenerationRunCoordinator::class);
    $lease = $coordinator->claimExecution($source->id);
    $coordinator->failExecution($source->id, $lease['token'], 'worker_lost', 'worker lost', true);
    $issue = GenerationIssue::sole();
    $actions = app(GenerationIssueActions::class);
    expect(data_get($source->execution_contract, 'repair_chain_issue_id'))->toBeNull();
    $source->update(['issue_id' => null]);
    expect(fn () => $actions->preview($issue, 'retry_transient'))->toThrow(LogicException::class);
    $source->update(['issue_id' => $issue->id]);
    $preview = $actions->preview($issue, 'retry_transient');
    expect($preview['available'])->toBeTrue($preview['reason'] ?? '');
    $retry = $actions->execute($issue->id, 'retry_transient', $issue->fingerprint, $preview['control_version'], (string) Str::uuid(), dispatch: false);
    expect($retry->stage)->toBe(GenerationStage::EventExtraction);
    expect($retry->execution_contract)->toBe($source->execution_contract);
    expect($retry->state_version)->toBe($source->state_version);
    expect($retry->input_hash)->toBe($source->input_hash);
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($retry->retry_of_run_id)->toBe($source->id);
    pipelineStep($task);
    expect($issue->fresh()->status)->toBe('resolved');
    expect($issue->fresh()->resolved_by_run_id)->toBe($retry->id);
    expect($task->fresh()->status)->toBe('running');
});

test('lost worker inside a repair resumes its frozen stage with the original cumulative limit', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'review');
    pipelineStep($task, ['findings' => [['rule_id' => 'motivation', 'severity' => 'important', 'message' => '缺动机', 'repair_instruction' => '补充动机', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]]);
    $source = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    $coordinator = app(GenerationRunCoordinator::class);
    $lease = $coordinator->claimExecution($source->id);
    $coordinator->failExecution($source->id, $lease['token'], 'worker_lost', 'worker lost', true);
    $issue = GenerationIssue::sole();
    $actions = app(GenerationIssueActions::class);
    $preview = $actions->preview($issue, 'retry_transient');
    expect($preview['available'])->toBeTrue($preview['reason'] ?? '');
    $retry = $actions->execute($issue->id, 'retry_transient', $issue->fingerprint, $preview['control_version'], (string) Str::uuid(), dispatch: false);
    expect($retry->stage)->toBe(GenerationStage::Rewrite);
    expect($retry->input_hash)->toBe($source->input_hash);
    expect($source->state_version)->not->toBeNull();
    expect($retry->state_version)->toBe($source->state_version);
    expect($retry->execution_contract)->toBe($source->execution_contract);
    expect($issue->fresh()->attempt_count)->toBe(2);
    app(GenerationIssueService::class)->observe($source->fresh());
    expect($task->fresh()->status)->toBe('running');
    expect($issue->fresh()->latest_run_id)->toBe($retry->id);
    $lease = $coordinator->claimExecution($retry->id);
    $coordinator->failExecution($retry->id, $lease['token'], 'worker_lost', 'worker lost again', true);
    expect($actions->preview($issue->fresh(), 'retry_transient')['available'])->toBeFalse();
    $count = GenerationRun::count();
    app(NovelBookWorkflow::class)->control($task->id, $task->fresh()->control_version, 'pause', false);
    expect(fn () => app(NovelBookWorkflow::class)->control($task->id, $task->fresh()->control_version, 'resume', false))->toThrow(LogicException::class);
    expect(GenerationRun::count())->toBe($count);
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($task->fresh()->status)->toBe('needs_attention');
});

test('transient provider failure in direct schema repair resumes only its owned frozen request', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'extract');
    pipelineStep($task, ['events' => [['event_type' => 'world_rule_revealed', 'subject_type' => 'location', 'subject_id' => '1', 'payload' => '{}', 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]]]]);
    $source = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    $coordinator = app(GenerationRunCoordinator::class);
    $lease = $coordinator->claimExecution($source->id);
    $coordinator->failExecution($source->id, $lease['token'], 'provider_timeout', 'timeout', true);
    $issue = GenerationIssue::sole();
    $actions = app(GenerationIssueActions::class);
    expect(data_get($source->execution_contract, 'repair_chain_issue_id'))->toBeNull();
    $source->update(['issue_id' => null]);
    expect(fn () => $actions->preview($issue, 'retry_transient'))->toThrow(LogicException::class);
    $source->update(['issue_id' => $issue->id]);
    $source->update(['error_code' => 'provider_quota_exceeded', 'error_retryable' => false]);
    expect($actions->isRetryable($issue))->toBeFalse();
    $source->update(['error_code' => 'provider_timeout', 'error_retryable' => false]);
    expect($actions->isRetryable($issue))->toBeFalse();
    $source->update(['error_retryable' => true]);
    expect($actions->isRetryable($issue))->toBeTrue();
    $preview = $actions->preview($issue, 'retry_transient');
    expect($preview['available'])->toBeTrue($preview['reason'] ?? '');
    app(NovelBookWorkflow::class)->control($task->id, $task->fresh()->control_version, 'pause', false);
    app(NovelBookWorkflow::class)->control($task->id, $task->fresh()->control_version, 'resume', false);
    $retry = GenerationRun::findOrFail($task->fresh()->pending_transition['run_id']);
    expect($retry->stage)->toBe(GenerationStage::EventExtraction);
    expect($retry->execution_contract)->toBe($source->execution_contract);
    expect($retry->state_version)->toBe($source->state_version);
    expect($retry->input_hash)->toBe($source->input_hash);
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($retry->retry_of_run_id)->toBe($source->id);
    pipelineStep($task);
    expect($issue->fresh()->status)->toBe('resolved');
    expect($issue->fresh()->resolved_by_run_id)->toBe($retry->id);
    expect($task->fresh()->status)->toBe('running');
});
