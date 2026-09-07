<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Data\CanonicalCommitData;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\Pages\ViewNovelChapter;
use App\Jobs\CommitChapterJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\User;
use App\Services\CanonicalCommitService;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function canonicalCommitFixture(array $patchOverrides = [], ReviewDecision $decision = ReviewDecision::Pass): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1, 'status' => ChapterStatus::Review]);
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
