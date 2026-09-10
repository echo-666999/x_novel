<?php

use App\Actions\Chapters\AcceptOverlengthChapterAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Data\CanonicalCommitData;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\FactStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\GenerationStage;
use App\Enums\MemoryStatus;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\CommitChapterJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Fact;
use App\Models\Foreshadowing;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\Review;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Models\Volume;
use App\Services\CanonicalCommitService;
use App\Services\EmergencyStopService;
use App\Services\LatestCanonicalChapterRollback;
use App\Services\MemoryInvalidator;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([UpdateMemoryJob::class]);
});

/** @return array<string, mixed> */
function canonicalCommitFixture(array $patchOverrides = [], ReviewDecision $decision = ReviewDecision::Pass): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1, 'status' => ChapterStatus::Review]);
    ChapterPlan::factory()->for($chapter)->create(['target_words' => 20]);
    $draftRun = GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(), 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::ChapterAssembly, 'status' => RunStatus::Succeeded,
    ]);
    $draftContent = '沈澜走进灯塔，终于看见了隐藏的星图。';
    $draft = GenerationArtifact::factory()->create([
        'generation_run_id' => $draftRun->getKey(), 'type' => ArtifactType::ChapterDraft,
        'content' => $draftContent, 'checksum' => hash('sha256', $draftContent),
    ]);
    $events = [[
        'event_type' => EventType::CharacterMoved->value,
        'subject_type' => null,
        'subject_id' => null,
        'payload' => ['to' => '灯塔'],
        'evidence' => [[
            'artifact_id' => $draft->getKey(), 'scene_id' => null, 'quote' => '沈澜走进灯塔',
            'start_offset' => 0, 'end_offset' => 7,
        ]],
        'story_time' => '第一日夜晚',
        'confidence' => 0.98,
    ]];
    $eventRun = GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(), 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::EventExtraction, 'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    $candidateData = ['status' => 'candidate', 'source_artifact_id' => $draft->getKey(), 'events' => $events];
    $candidate = GenerationArtifact::factory()->create([
        'generation_run_id' => $eventRun->getKey(), 'type' => ArtifactType::EventCandidate,
        'data' => $candidateData, 'content' => json_encode($events),
        'checksum' => hash('sha256', json_encode($candidateData)),
    ]);
    $patchData = [
        'expected_state_version' => 0,
        'operations' => [],
        'fact_changes' => [],
        'foreshadowing_changes' => [],
        'status' => 'candidate',
        'source_artifact_id' => $candidate->getKey(),
        'before_checksum' => $state->checksum,
        'after_checksum' => app(StoryStateService::class)->checksum($state->state),
        'changes' => [],
        ...$patchOverrides,
    ];
    $patch = GenerationArtifact::factory()->create([
        'generation_run_id' => $eventRun->getKey(), 'type' => ArtifactType::StatePatch,
        'version' => 1, 'data' => $patchData, 'content' => json_encode($patchData['operations']),
        'checksum' => hash('sha256', json_encode($patchData)),
    ]);
    $reviewRun = GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(), 'chapter_id' => $chapter->getKey(), 'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(), 'stage' => GenerationStage::Review, 'status' => RunStatus::Succeeded,
        'state_version' => 0,
    ]);
    $reviewData = ['decision' => $decision->value, 'source_artifact_id' => $draft->getKey(), 'findings' => []];
    $reviewArtifact = GenerationArtifact::factory()->create([
        'generation_run_id' => $reviewRun->getKey(), 'type' => ArtifactType::ReviewResult,
        'data' => $reviewData, 'content' => json_encode($reviewData), 'checksum' => hash('sha256', json_encode($reviewData)),
    ]);
    $review = Review::factory()->create([
        'generation_run_id' => $reviewRun->getKey(), 'artifact_id' => $reviewArtifact->getKey(), 'decision' => $decision,
    ]);
    $data = new CanonicalCommitData(
        $chapter->getKey(), $draft->getKey(), $review->getKey(), $candidate->getKey(), $patch->getKey(), 0, $draft->checksum,
    );

    return compact('novel', 'state', 'chapter', 'draft', 'candidate', 'patch', 'review', 'data');
}

test('canonical commit atomically persists events state and pointers', function () {
    $fixture = canonicalCommitFixture();

    $version = app(CanonicalCommitService::class)->commit($fixture['data']);

    expect($version->version)->toBe(1)
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBe($fixture['draft']->getKey())
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($version->getKey())
        ->and($fixture['novel']->fresh()->current_chapter_sequence)->toBe(1)
        ->and(StoryEvent::query()->count())->toBe(1)
        ->and(StoryEvent::query()->sole()->state_version)->toBe(1)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(2);
});

test('latest canonical chapter rollback restores pointers and invalidates derived data', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture(['fact_changes' => [[
        'action' => 'create', 'subject_type' => 'novel', 'subject_id' => 1,
        'predicate' => 'has_star_map', 'value' => true, 'source_event_index' => 0,
    ]]]);
    $committedState = app(CanonicalCommitService::class)->commit($fixture['data']);
    $event = StoryEvent::query()->sole();
    $memory = Memory::factory()->for($fixture['novel'])->create([
        'source_type' => 'story_event', 'source_id' => $event->getKey(), 'valid_from_chapter' => 1,
    ]);

    $result = app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '修正结尾事件');

    expect($result)->toMatchArray(['from_state_version' => 1, 'to_state_version' => 0, 'events' => 1, 'memories' => 1])
        ->and($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Void)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey())
        ->and($fixture['novel']->fresh()->current_chapter_sequence)->toBeNull()
        ->and($event->fresh()->status->value)->toBe('invalidated')
        ->and($event->fresh()->invalidated_at)->not->toBeNull()
        ->and($memory->fresh()->status)->toBe(MemoryStatus::Invalid)
        ->and(Fact::query()->sole()->status)->toBe(FactStatus::Invalidated)
        ->and(StoryStateVersion::query()->find($committedState->getKey()))->not->toBeNull();
});

test('rollback restores facts superseded by the latest canonical chapter', function () {
    $fixture = canonicalCommitFixture();
    $fact = Fact::factory()->for($fixture['novel'])->create(['status' => FactStatus::Active]);
    $patchData = $fixture['patch']->data;
    $patchData['fact_changes'] = [['action' => 'supersede', 'fact_id' => $fact->getKey(), 'source_event_index' => 0]];
    DB::table('generation_artifacts')->where('id', $fixture['patch']->getKey())->update([
        'data' => json_encode($patchData, JSON_THROW_ON_ERROR),
    ]);

    app(CanonicalCommitService::class)->commit($fixture['data']);
    expect($fact->fresh()->status)->toBe(FactStatus::Superseded);

    app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '恢复被替代的事实');

    expect($fact->fresh()->status)->toBe(FactStatus::Active);
});

test('rollback restores foreshadowing projection from the previous state', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    $foreshadowing = Foreshadowing::factory()->for($fixture['novel'])->create([
        'status' => ForeshadowingStatus::PaidOff,
        'reinforce_count' => 3,
        'payoff_chapter_id' => $fixture['chapter']->getKey(),
    ]);
    app(CanonicalCommitService::class)->commit($fixture['data']);
    $previous = $fixture['state']->state;
    $previous['foreshadowings'][(string) $foreshadowing->getKey()] = ['status' => 'reinforced', 'reinforce_count' => 2];
    DB::table('story_state_versions')->where('id', $fixture['state']->getKey())->update([
        'state' => json_encode($previous, JSON_THROW_ON_ERROR),
    ]);
    $event = StoryEvent::query()->sole();
    DB::table('story_events')->where('id', $event->getKey())->update([
        'event_type' => EventType::ForeshadowingPaidOff->value,
        'subject_type' => 'foreshadowing',
        'subject_id' => (string) $foreshadowing->getKey(),
    ]);

    app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '恢复伏笔状态');

    expect($foreshadowing->fresh()->status)->toBe(ForeshadowingStatus::Reinforced)
        ->and($foreshadowing->fresh()->reinforce_count)->toBe(2)
        ->and($foreshadowing->fresh()->payoff_chapter_id)->toBeNull();
});

test('a non latest canonical chapter cannot be rolled back', function () {
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);
    $latest = Chapter::factory()->for($fixture['novel'])->create(['sequence' => 2, 'status' => ChapterStatus::Canonical]);
    $latestState = StoryStateVersion::factory()->for($fixture['novel'])->for($latest)->create(['version' => 2]);
    $fixture['novel']->update(['current_chapter_sequence' => 2, 'canonical_state_version_id' => $latestState->getKey()]);

    expect(fn () => app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '不应允许'))
        ->toThrow(ValidationException::class, '只能回滚当前最新的正式章节');

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical);
});

test('recanonicalization after rollback uses a monotonically increasing state version', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    $service = app(CanonicalCommitService::class);
    $service->commit($fixture['data']);
    app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '重新提交这一章');

    $nextState = $service->commit($fixture['data']);

    expect($nextState->version)->toBe(2)
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($nextState->getKey())
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->pluck('version')->all())->toBe([0, 1, 2])
        ->and(StoryEvent::query()->where('status', 'active')->count())->toBe(1)
        ->and(StoryEvent::query()->where('status', 'invalidated')->count())->toBe(1);
});

test('rollback skips correction versions from the same chapter when restoring state', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    $committed = app(CanonicalCommitService::class)->commit($fixture['data']);
    $correction = StoryStateVersion::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'version' => 2,
        'state' => $committed->state,
        'checksum' => $committed->checksum,
    ]);
    $fixture['novel']->update(['canonical_state_version_id' => $correction->getKey()]);

    $result = app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], '撤销该章及其更正');

    expect($result['from_state_version'])->toBe(2)
        ->and($result['to_state_version'])->toBe(0)
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey());
});

test('latest canonical chapter rollback requires a reason', function () {
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);

    expect(fn () => app(LatestCanonicalChapterRollback::class)->rollback($fixture['chapter'], ' '))
        ->toThrow(ValidationException::class, '必须填写回滚原因');
});

test('rollback failure leaves canonical data unchanged', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);
    $event = StoryEvent::query()->sole();
    $failingInvalidator = new class extends MemoryInvalidator
    {
        public function invalidateForChapter(int $chapterId): int
        {
            throw new RuntimeException('模拟 Memory 失效失败');
        }
    };

    expect(fn () => (new LatestCanonicalChapterRollback($failingInvalidator))->rollback($fixture['chapter'], '验证事务回滚'))
        ->toThrow(RuntimeException::class, '模拟 Memory 失效失败');

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBe($fixture['draft']->getKey())
        ->and($fixture['novel']->fresh()->current_chapter_sequence)->toBe(1)
        ->and($event->fresh()->status->value)->toBe('active')
        ->and($event->fresh()->invalidated_at)->toBeNull();
});

test('latest canonical chapter can be rolled back from its workbench', function () {
    $this->actingAs(User::factory()->create());
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->assertActionVisible('rollbackLatestCanonical')
        ->callAction('rollbackLatestCanonical', data: ['reason' => '调整结尾节奏'])
        ->assertHasNoActionErrors()
        ->assertNotified('最新正式章节已回滚')
        ->assertActionHidden('rollbackLatestCanonical');

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Void);
});

test('canonical commit applies fact changes with story event provenance', function () {
    $fixture = canonicalCommitFixture(['fact_changes' => [[
        'action' => 'create', 'subject_type' => 'novel', 'subject_id' => 1, 'predicate' => 'has_star_map',
        'value' => true, 'source_event_index' => 0,
    ]]]);

    app(CanonicalCommitService::class)->commit($fixture['data']);

    $fact = Fact::query()->sole();

    expect($fact->source_event_id)->toBe(StoryEvent::query()->sole()->getKey())
        ->and($fact->predicate)->toBe('has_star_map')
        ->and($fact->value)->toBeTrue();
});

test('an identical duplicate canonical commit has exactly once effects', function () {
    $fixture = canonicalCommitFixture();
    $service = app(CanonicalCommitService::class);

    $first = $service->commit($fixture['data']);
    $duplicate = $service->commit($fixture['data']);

    expect($duplicate->is($first))->toBeTrue()
        ->and(StoryEvent::query()->count())->toBe(1)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(2);
});

test('canonical commit rejects a wrong expected state version', function () {
    $fixture = canonicalCommitFixture();
    $data = new CanonicalCommitData(
        $fixture['chapter']->getKey(), $fixture['draft']->getKey(), $fixture['review']->getKey(),
        $fixture['candidate']->getKey(), $fixture['patch']->getKey(), 9, $fixture['draft']->checksum,
    );

    expect(fn () => app(CanonicalCommitService::class)->commit($data))
        ->toThrow(ValidationException::class, 'Expected State Version');

    expect(StoryEvent::query()->count())->toBe(0)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull();
});

test('canonical commit requires a pass review', function () {
    $fixture = canonicalCommitFixture(decision: ReviewDecision::Rewrite);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, 'PASS Review');
});

test('canonical commit rejects a draft below the strict chapter minimum', function () {
    $fixture = canonicalCommitFixture();
    $fixture['chapter']->latestPlan->update(['target_words' => 100]);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, '少于严格下限 85 字');

    expect($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and(StoryEvent::query()->count())->toBe(0);
});

test('canonical commit rejects an overlength draft without an explicit recorded exception', function () {
    $fixture = canonicalCommitFixture();
    $content = $fixture['draft']->content.str_repeat('补', 6);
    $checksum = hash('sha256', $content);
    DB::table('generation_artifacts')->where('id', $fixture['draft']->getKey())->update([
        'content' => $content,
        'checksum' => $checksum,
    ]);
    $data = new CanonicalCommitData(
        $fixture['chapter']->getKey(), $fixture['draft']->getKey(), $fixture['review']->getKey(),
        $fixture['candidate']->getKey(), $fixture['patch']->getKey(), 0, $checksum,
    );

    expect(fn () => app(CanonicalCommitService::class)->commit($data))
        ->toThrow(ValidationException::class, '超过严格上限 23 字');

    expect($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and(StoryEvent::query()->count())->toBe(0);
});

test('canonical commit accepts an overlength draft only with its matching recorded exception', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    $content = $fixture['draft']->content.str_repeat('补', 6);
    $checksum = hash('sha256', $content);
    DB::table('generation_artifacts')->where('id', $fixture['draft']->getKey())->update([
        'content' => $content,
        'checksum' => $checksum,
    ]);
    $finding = [
        'code' => 'CHAPTER_LENGTH_TOO_LONG',
        'severity' => 'warning',
        'message' => '章节超过严格字数上限。',
    ];
    $fixture['review']->update([
        'decision' => ReviewDecision::NeedsAttention,
        'findings' => [$finding],
    ]);
    $accepted = app(AcceptOverlengthChapterAction::class)->execute(
        $fixture['chapter'],
        '该剧情节点不可拆分。',
        7,
    );
    $data = new CanonicalCommitData(
        $fixture['chapter']->getKey(), $fixture['draft']->getKey(), $accepted->getKey(),
        $fixture['candidate']->getKey(), $fixture['patch']->getKey(), 0, $checksum,
    );

    app(CanonicalCommitService::class)->commit($data);

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and($fixture['chapter']->fresh()->word_count)->toBe(24)
        ->and($accepted->fresh()->findings)->toContainEqual($finding)
        ->and(data_get($accepted->artifact->data, 'manual_length_exception'))->toBeTrue()
        ->and(StoryEvent::query()->count())->toBe(1);
});

test('a paused novel cannot create a new canonical commit', function () {
    $fixture = canonicalCommitFixture();
    $fixture['novel']->update(['status' => NovelStatus::Paused]);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, '只有生成中或收束中的小说可以提交正式章节');

    expect($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and(StoryEvent::query()->count())->toBe(0);
});

test('emergency stop blocks canonical commit without leaving partial state', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    app(EmergencyStopService::class)->setActive(true);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, 'Canonical Commit 已被阻止');

    expect($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and(StoryEvent::query()->count())->toBe(0)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('a transaction exception after event writes leaves no partial canonical state', function () {
    $fixture = canonicalCommitFixture(['fact_changes' => [[
        'action' => 'create', 'subject_type' => 'novel', 'subject_id' => 1, 'predicate' => 'has_map',
        'value' => true, 'source_event_index' => 99,
    ]]]);

    expect(fn () => app(CanonicalCommitService::class)->commit($fixture['data']))
        ->toThrow(ValidationException::class, '不存在的 Story Event');

    expect(StoryEvent::query()->count())->toBe(0)
        ->and(Fact::query()->count())->toBe(0)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(1)
        ->and($fixture['chapter']->fresh()->canonical_artifact_id)->toBeNull()
        ->and($fixture['novel']->fresh()->canonical_state_version_id)->toBe($fixture['state']->getKey());
});

test('an already canonical chapter rejects a conflicting artifact', function () {
    $fixture = canonicalCommitFixture();
    app(CanonicalCommitService::class)->commit($fixture['data']);
    $other = GenerationArtifact::factory()->create(['type' => ArtifactType::ChapterDraft]);
    $conflict = new CanonicalCommitData(
        $fixture['chapter']->getKey(), $other->getKey(), $fixture['review']->getKey(),
        $fixture['candidate']->getKey(), $fixture['patch']->getKey(), 0, $other->checksum,
    );

    expect(fn () => app(CanonicalCommitService::class)->commit($conflict))
        ->toThrow(ValidationException::class, '另一个 Artifact');
});

test('a pass review exposes a canonical commit preview and action', function () {
    $this->actingAs(User::factory()->create());
    $fixture = canonicalCommitFixture();

    Livewire::test(ViewNovelChapter::class, [
        'record' => $fixture['novel']->getRouteKey(),
        'chapter' => $fixture['chapter']->getRouteKey(),
    ])
        ->assertActionVisible('commitCanonical')
        ->assertActionEnabled('commitCanonical')
        ->callAction('commitCanonical')
        ->assertNotified('章节已提交为正式版本');

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical);
});

test('commit chapter job uses the canonical service and duplicate delivery has exactly once effects', function () {
    $fixture = canonicalCommitFixture();
    $job = new CommitChapterJob($fixture['chapter']->getKey(), $fixture['review']->getKey());

    $job->handle(app(CanonicalCommitService::class));
    $job->handle(app(CanonicalCommitService::class));

    expect($fixture['chapter']->fresh()->status)->toBe(ChapterStatus::Canonical)
        ->and(StoryEvent::query()->count())->toBe(1)
        ->and(StoryStateVersion::query()->where('novel_id', $fixture['novel']->getKey())->count())->toBe(2);
});

test('canonical commit dispatches memory update after the formal transaction', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();

    app(CanonicalCommitService::class)->commit($fixture['data']);

    Queue::assertPushed(UpdateMemoryJob::class, fn (UpdateMemoryJob $job): bool => $job->chapterId === $fixture['chapter']->getKey()
        && $job->queue === 'default');
});

test('canonical commit starts only the immediate next chapter when auto generation is enabled', function () {
    Queue::fake();
    $fixture = canonicalCommitFixture();
    NovelBible::factory()->for($fixture['novel'])->create();
    $fixture['novel']->update(['settings' => ['auto_generate' => true]]);
    Volume::factory()->for($fixture['novel'])->create(['status' => VolumeStatus::Active]);

    $service = app(CanonicalCommitService::class);
    $service->commit($fixture['data']);
    $service->commit($fixture['data']);

    $nextChapter = $fixture['novel']->chapters()->where('sequence', 2)->sole();

    expect($nextChapter->status)->toBe(ChapterStatus::Planned)
        ->and($fixture['novel']->chapters()->where('sequence', '>', 2)->doesntExist())->toBeTrue();

    Queue::assertPushed(PlanChapterJob::class, 1);
    Queue::assertPushed(PlanChapterJob::class, fn (PlanChapterJob $job): bool => $job->chapterId === $nextChapter->getKey());
});
