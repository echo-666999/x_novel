<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Actions\Story\RecoverCanonicalStoryStateAction;
use App\Enums\EventType;
use App\Jobs\RefreshNovelProjectionJob;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Services\StoryStateRebuilder;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('canonical recovery creates a new checked baseline without overwriting history', function () {
    Queue::fake();
    $novel = Novel::factory()->create();
    $baseline = app(InitializeNovelStateAction::class)->handle($novel);
    StoryEvent::factory()->for($novel)->create([
        'event_type' => EventType::CharacterMoved,
        'subject_id' => '42',
        'payload' => ['to' => '洛阳'],
        'state_version' => 1,
    ]);
    $chapter = Chapter::factory()->for($novel)->create();
    $drifted = [...$baseline->state, 'characters' => ['42' => ['location' => '长安']]];
    $current = StoryStateVersion::factory()->for($novel)->create([
        'version' => 1,
        'chapter_id' => $chapter->getKey(),
        'state' => $drifted,
        'checksum' => app(StoryStateService::class)->checksum($drifted),
    ]);
    $novel->update(['canonical_state_version_id' => $current->getKey()]);
    $report = app(StoryStateRebuilder::class)->rebuild($novel->fresh());

    $recovered = app(RecoverCanonicalStoryStateAction::class)->execute(
        $novel->fresh(),
        $report->currentVersion,
        $report->currentChecksum,
        $report->rebuiltChecksum,
    );

    expect($recovered->version)->toBe(2)
        ->and($recovered->chapter_id)->toBeNull()
        ->and($recovered->state['characters']['42']['location'])->toBe('洛阳')
        ->and($novel->fresh()->canonical_state_version_id)->toBe($recovered->getKey())
        ->and($novel->storyStateVersions()->whereKey($current)->exists())->toBeTrue();
    Queue::assertPushed(RefreshNovelProjectionJob::class);
});

test('canonical recovery rejects a stale version before writing', function () {
    Queue::fake();
    $novel = Novel::factory()->create();
    $baseline = app(InitializeNovelStateAction::class)->handle($novel);

    expect(fn () => app(RecoverCanonicalStoryStateAction::class)->execute(
        $novel->fresh(),
        99,
        $baseline->checksum,
        $baseline->checksum,
    ))->toThrow(ValidationException::class, 'State Version Conflict');

    expect($novel->storyStateVersions()->count())->toBe(1);
    Queue::assertNothingPushed();
});
