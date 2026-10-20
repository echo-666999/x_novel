<?php

use App\Actions\Chapters\ReviseBookChapterEventsAction;
use App\AI\Exceptions\AiProviderException;
use App\Enums\ArtifactType;
use App\Filament\Resources\Novels\Pages\ViewBookChapter;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
require_once __DIR__.'/NovelPipelineFixtures.php';

function manualEventFixture(): array
{
    $task = pipelineTask();
    pipelineUntil($task, 'extract');
    $id = (string) $task->novel->characters()->sole()->id;
    pipelineStep($task, ['events' => [['event_type' => 'character_moved', 'subject_type' => 'character', 'subject_id' => $id, 'payload' => '{"to":"邮局"}', 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]]]]);
    config(['generation.book.max_rewrites' => 0]);
    pipelineStep($task, ['findings' => [['rule_id' => 'arrival', 'severity' => 'important', 'message' => '尚未抵达', 'repair_instruction' => '修正事件位置', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]]);
    $issue = GenerationIssue::sole();
    $issue->update(['attempt_count' => 2]);
    $action = app(ReviseBookChapterEventsAction::class);
    $chapter = Chapter::sole();
    $source = $action->source($chapter);
    $candidate = GenerationArtifact::findOrFail($source->execution_contract['event_artifact_id']);
    $rows = $action->rows($candidate);
    $rows[0]['payload'] = '{"to":"港口"}';
    $rows[0]['reason'] = '正文仍站在港口，走向邮局不能当作抵达。';

    return [$task->fresh(), $issue, $chapter, $source, $candidate, $rows];
}

test('manual event revision preserves body history costs and limits until independent review passes', function () {
    [$task, $issue, $chapter, $source, $old, $rows] = manualEventFixture();
    $draft = $chapter->current_draft_artifact_id;
    $usage = UsageRecord::count();
    $action = app(ReviseBookChapterEventsAction::class);
    $key = (string) Str::uuid();
    $new = $action->handle($chapter->id, $source->id, $old->id, $old->checksum, $task->control_version, $rows, $key, false);
    $same = $action->handle($chapter->id, $source->id, $old->id, $old->checksum, $task->control_version, $rows, $key, false);
    expect($same->id)->toBe($new->id);
    expect($new->data['events'][0]['payload']['to'])->toBe('港口');
    expect($old->fresh()->data['events'][0]['payload']['to'])->toBe('邮局');
    expect($chapter->fresh()->current_draft_artifact_id)->toBe($draft);
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($issue->fresh()->status)->not->toBe('resolved');
    expect(UsageRecord::count())->toBe($usage);
    expect(data_get($issue->fresh()->evidence, 'operations.0.changes.0.reason'))->toBe($rows[0]['reason']);
    pipelineStep($task);
    expect($issue->fresh()->status)->toBe('resolved');
    expect(UsageRecord::where('issue_id', $issue->id)->count())->toBe(2);
});

test('manual event validation rejects unsafe or stale edits without writing candidates', function (string $case) {
    [$task, $issue, $chapter, $source, $old, $rows] = manualEventFixture();
    $version = $task->control_version;
    if ($case === 'reason') {
        $rows[0]['reason'] = '';
    }
    if ($case === 'json') {
        $rows[0]['payload'] = '{broken';
    }
    if ($case === 'no_change') {
        $rows[0]['payload'] = '{"to":"邮局"}';
    }
    if ($case === 'stale') {
        $version--;
    }
    if ($case === 'identity') {
        $source->id++;
    }
    if ($case === 'canonical') {
        $chapter->update(['canonical_artifact_id' => $chapter->current_draft_artifact_id]);
    }
    $count = GenerationArtifact::count();
    expect(fn () => app(ReviseBookChapterEventsAction::class)->handle($chapter->id, $source->id, $old->id, $old->checksum, $version, $rows, (string) Str::uuid(), false))->toThrow($case === 'json' ? JsonException::class : LogicException::class);
    expect(GenerationArtifact::count())->toBe($count);
    expect($issue->fresh()->attempt_count)->toBe(2);
})->with(['reason', 'json', 'no_change', 'stale', 'identity', 'canonical']);

test('manual event editor preserves invalid input and exposes the saved independent review handoff', function () {
    [$task, $issue, $chapter, $source, $old, $rows] = manualEventFixture();
    $this->actingAs(User::factory()->create());
    $page = Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => $chapter->id]);
    $page->assertSee('人工修订事件候选');
    $page->set('eventRows.0.payload', '{broken')->call('saveEvents')->assertSet('eventRows.0.payload', '{broken');
    $page->set('eventRows', $rows)->call('saveEvents')->assertSee('事件修订已保存');
    expect($issue->fresh()->status)->not->toBe('resolved');
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
});

test('manual event revision repairs a rejected extraction without replacing body or resetting attempts', function () {
    config(['generation.issues.max_attempts' => 0]);
    $task = pipelineTask();
    pipelineUntil($task, 'extract');
    $id = (string) $task->novel->characters()->sole()->id;
    $failed = pipelineStep($task, ['events' => [['event_type' => 'character_learned', 'subject_type' => 'character', 'subject_id' => $id, 'payload' => '{"key":"current_location"}', 'story_time' => '雨季首日', 'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]]]]);
    $issue = GenerationIssue::sole();
    $issue->update(['attempt_count' => 2]);
    $action = app(ReviseBookChapterEventsAction::class);
    $chapter = Chapter::sole();
    $source = $action->source($chapter);
    expect($source->id)->toBe($failed->id);
    $old = $action->candidate($source);
    $rows = $action->rows($old);
    $rows[0]['payload'] = '{"key":"current_location","value":"港口"}';
    $rows[0]['reason'] = '补充明确的地点知识值，不能省略 value 后默认为 true。';
    $draft = $chapter->current_draft_artifact_id;
    $this->actingAs(User::factory()->create());
    Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => $chapter->id])->assertSee('人工修订事件候选');
    $new = $action->handle($chapter->id, $source->id, $old->id, $old->checksum, $task->fresh()->control_version, $rows, (string) Str::uuid(), false);
    expect($new->data['events'][0]['payload']['value'])->toBe('港口');
    expect($old->fresh()->data['events'][0]['payload'])->toBe('{"key":"current_location"}');
    expect($chapter->fresh()->current_draft_artifact_id)->toBe($draft);
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($issue->fresh()->status)->toBe('repairing', (string) GenerationRun::latest('id')->first()->error_message);
    pipelineStep($task);
    expect($issue->fresh()->status)->toBe('resolved');
});

function omittedEventAddition(string $characterId): array
{
    return ['event' => ['event_type' => 'character_learned', 'subject_type' => 'character', 'subject_id' => $characterId,
        'payload' => '{"key":"observed_location","value":"港口"}', 'story_time' => '雨季首日',
        'evidence' => [['paragraph_key' => 'p1', 'offset' => 0, 'quote' => '林舟站在港口']]],
        'reason' => '按正文补充当前地点知识，保留原候选。'];
}

test('omitted event addition preserves original candidates and requires independent review without resetting costs or attempts', function () {
    [$task, $issue, $chapter, $source, $old, $rows] = manualEventFixture();
    $action = app(ReviseBookChapterEventsAction::class);
    $rows = $action->rows($old);
    $addition = omittedEventAddition((string) $task->novel->characters()->sole()->id);
    $key = (string) Str::uuid();
    $usage = UsageRecord::count();
    $draft = $chapter->current_draft_artifact_id;

    $new = $action->handle($chapter->id, $source->id, $old->id, $old->checksum, $task->control_version, $rows, $key, false, [$addition]);
    $same = $action->handle($chapter->id, $source->id, $old->id, $old->checksum, $task->control_version, $rows, $key, false, [$addition]);

    expect($same->id)->toBe($new->id);
    expect($new->data['events'])->toHaveCount(2);
    expect($new->data['events'][0])->toEqual($old->data['events'][0]);
    expect($old->fresh()->data['events'])->toHaveCount(1);
    expect($new->data['events'][1]['payload']['value'])->toBe('港口');
    expect($new->generationRun->execution_contract['changes'][0]['before'])->toBeNull();
    expect($new->generationRun->execution_contract['changes'][0]['reason'])->toBe($addition['reason']);
    expect($chapter->fresh()->current_draft_artifact_id)->toBe($draft);
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect(UsageRecord::count())->toBe($usage);
    expect(GenerationRun::latest('id')->first()->execution_contract['book_stage'])->toBe('review');
    $addition['reason'] = 'different';
    expect(fn () => $action->handle($chapter->id, $source->id, $old->id, $old->checksum, $task->control_version, $rows, $key, false, [$addition]))->toThrow(LogicException::class);
    pipelineStep($task);
    expect($issue->fresh()->status)->toBe('resolved');
});

test('omitted event validation rejects unsupported additions atomically', function (string $case) {
    [$task, $issue, $chapter, $source, $old, $rows] = manualEventFixture();
    $addition = omittedEventAddition((string) $task->novel->characters()->sole()->id);
    $exception = LogicException::class;
    if ($case === 'reason') {
        $addition['reason'] = '';
        $exception = ValidationException::class;
    }
    if ($case === 'new_entity') {
        $addition['event']['event_type'] = 'character_introduced';
    }
    if ($case === 'quote') {
        $addition['event']['evidence'][0]['quote'] = '正文并不存在的物证';
        $exception = AiProviderException::class;
    }
    if ($case === 'entity') {
        $addition['event']['subject_id'] = '999999';
        $exception = AiProviderException::class;
    }
    if ($case === 'schema') {
        $addition['event']['evidence'] = [];
    }
    $count = GenerationArtifact::count();
    $runs = GenerationRun::count();
    $usage = UsageRecord::count();

    expect(fn () => app(ReviseBookChapterEventsAction::class)->handle($chapter->id, $source->id, $old->id, $old->checksum, $task->control_version, $rows, (string) Str::uuid(), false, [$addition]))->toThrow($exception);

    expect(GenerationArtifact::count())->toBe($count);
    expect(GenerationRun::count())->toBe($runs);
    expect(UsageRecord::count())->toBe($usage);
    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($task->fresh()->control_version)->toBe($task->control_version);
})->with(['reason', 'new_entity', 'quote', 'entity', 'schema']);

test('omitted event editor retains malformed input and submits additions for review', function () {
    [$task, $issue, $chapter] = manualEventFixture();
    $this->actingAs(User::factory()->create());
    $addition = omittedEventAddition((string) $task->novel->characters()->sole()->id);
    $page = Livewire::test(ViewBookChapter::class, ['record' => $task->novel_id, 'chapter' => $chapter->id]);

    $page->assertSee('补充遗漏事件')->set('eventAdditions', '{broken')->call('saveEvents')->assertSet('eventAdditions', '{broken');
    $page->set('eventAdditions', json_encode([$addition], JSON_UNESCAPED_UNICODE))->call('saveEvents')->assertSee('事件修订已保存');

    expect($issue->fresh()->attempt_count)->toBe(2);
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
    expect(GenerationArtifact::where('type', ArtifactType::EventCandidate)->latest('id')->first()->data['events'])->toHaveCount(2);
});

test('omitted events can be added when extraction omitted every event', function () {
    $task = pipelineTask();
    pipelineUntil($task, 'extract');
    pipelineStep($task, ['events' => []]);
    config(['generation.book.max_rewrites' => 0]);
    pipelineStep($task, ['findings' => [['rule_id' => 'missing', 'severity' => 'important', 'message' => '遗漏地点知识', 'repair_instruction' => '补录', 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]], 'goal_checks' => [['goal_key' => 'chapter-1', 'fulfilled' => true, 'evidence' => [['paragraph_key' => 'p1', 'quote' => '林舟站在港口', 'offset' => 0]]]]]);
    $action = app(ReviseBookChapterEventsAction::class);
    $chapter = Chapter::sole();
    $source = $action->source($chapter);
    $old = $action->candidate($source);
    $addition = omittedEventAddition((string) $task->novel->characters()->sole()->id);

    $new = $action->handle($chapter->id, $source->id, $old->id, $old->checksum, $task->fresh()->control_version, [], (string) Str::uuid(), false, [$addition]);

    expect($old->fresh()->data['events'])->toBe([]);
    expect($new->data['events'])->toHaveCount(1);
    expect($chapter->fresh()->canonical_artifact_id)->toBeNull();
    expect(GenerationRun::latest('id')->first()->execution_contract['book_stage'])->toBe('review');
});
