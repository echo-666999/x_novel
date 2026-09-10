<?php

use App\Actions\Generation\CheckNextAction;
use App\Actions\Generation\PauseGenerationAction;
use App\Actions\Generation\StartSmokeRunAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\VolumeStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryStateVersion;
use App\Models\UsageRecord;
use App\Models\Volume;
use App\Services\ResumeResolver;
use App\Services\SmokeRunService;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** @return array{Novel, Volume} */
function smokeRunNovel(int $currentSequence = 0): array
{
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => $currentSequence ?: null,
    ]);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    if ($currentSequence > 0) {
        Chapter::factory()->for($novel)->for($volume)->create([
            'sequence' => $currentSequence,
            'status' => ChapterStatus::Canonical,
        ]);
    }

    return [$novel->fresh(), $volume];
}

test('starting a smoke run records a twenty chapter boundary and queues only the first chapter', function () {
    Queue::fake();
    [$novel] = smokeRunNovel(3);

    $chapter = app(StartSmokeRunAction::class)->handle($novel);
    $settings = $novel->fresh()->settings;

    expect($chapter->sequence)->toBe(4)
        ->and(data_get($settings, 'smoke_run.status'))->toBe('running')
        ->and(data_get($settings, 'smoke_run.start_sequence'))->toBe(4)
        ->and(data_get($settings, 'smoke_run.target_sequence'))->toBe(23)
        ->and(data_get($settings, 'auto_generate'))->toBeTrue()
        ->and($novel->chapters()->where('sequence', '>', 4)->doesntExist())->toBeTrue();

    Queue::assertPushed(PlanChapterJob::class, 1);
});

test('the twentieth canonical chapter completes the run without queuing chapter twenty one', function () {
    Queue::fake();
    [$novel, $volume] = smokeRunNovel();
    $novel->update(['current_chapter_sequence' => 20, 'settings' => [
        'auto_generate' => true,
        'smoke_run' => ['status' => 'running', 'start_sequence' => 1, 'target_sequence' => 20],
    ]]);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create([
        'sequence' => 20,
        'status' => ChapterStatus::Canonical,
    ]);

    $next = app(CheckNextAction::class)->handle($novel->fresh(), $chapter->getKey());

    expect($next)->toBeNull()
        ->and(data_get($novel->fresh()->settings, 'smoke_run.status'))->toBe('completed')
        ->and(data_get($novel->fresh()->settings, 'smoke_run.completed_at'))->not->toBeNull()
        ->and(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse()
        ->and($novel->chapters()->where('sequence', 21)->doesntExist())->toBeTrue();

    Queue::assertNothingPushed();
});

test('twenty sequential commit callbacks create no duplicate or skipped chapter', function () {
    Queue::fake();
    [$novel] = smokeRunNovel();
    $chapter = app(StartSmokeRunAction::class)->handle($novel);
    $state = $novel->fresh()->canonicalStateVersion->state;
    $checkNext = app(CheckNextAction::class);

    foreach (range(1, 20) as $sequence) {
        expect($chapter->sequence)->toBe($sequence);
        $chapter->update(['status' => ChapterStatus::Canonical]);
        $versionState = [...$state, 'timeline' => ['chapter' => $sequence]];
        $version = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
            'version' => $sequence,
            'state' => $versionState,
            'checksum' => app(StoryStateService::class)->checksum($versionState),
        ]);
        $novel->update([
            'current_chapter_sequence' => $sequence,
            'canonical_state_version_id' => $version->getKey(),
        ]);

        $next = $checkNext->handle($novel->fresh(), $chapter->getKey());
        $duplicateCallback = $checkNext->handle($novel->fresh(), $chapter->getKey());

        if ($sequence < 20) {
            expect($next?->sequence)->toBe($sequence + 1)
                ->and($duplicateCallback?->is($next))->toBeTrue();
            $chapter = $next;
        } else {
            expect($next)->toBeNull()->and($duplicateCallback)->toBeNull();
        }
    }

    expect($novel->chapters()->count())->toBe(20)
        ->and($novel->chapters()->orderBy('sequence')->pluck('sequence')->all())->toBe(range(1, 20))
        ->and($novel->storyStateVersions()->orderBy('version')->pluck('version')->all())->toBe(range(0, 20))
        ->and(data_get($novel->fresh()->settings, 'smoke_run.status'))->toBe('completed');

    Queue::assertPushed(PlanChapterJob::class, 20);
});

test('smoke run progress verifies twenty continuous chapters state versions and cost', function () {
    [$novel, $volume] = smokeRunNovel();
    $novel->update(['current_chapter_sequence' => 20, 'settings' => [
        'auto_generate' => false,
        'smoke_run' => ['status' => 'completed', 'start_sequence' => 1, 'target_sequence' => 20],
    ]]);
    $state = $novel->fresh()->canonicalStateVersion->state;
    $lastVersion = null;

    foreach (range(1, 20) as $sequence) {
        $chapter = Chapter::factory()->for($novel)->for($volume)->create([
            'sequence' => $sequence,
            'status' => ChapterStatus::Canonical,
        ]);
        $lastVersion = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
            'version' => $sequence,
            'state' => [...$state, 'timeline' => ['chapter' => $sequence]],
            'checksum' => app(StoryStateService::class)->checksum([...$state, 'timeline' => ['chapter' => $sequence]]),
        ]);
        $run = GenerationRun::factory()->for($novel)->for($chapter)->create();
        UsageRecord::factory()->for($run, 'generationRun')->create([
            'novel_id' => $novel->getKey(),
            'chapter_id' => $chapter->getKey(),
            'estimated_cost' => 0.05,
        ]);
    }
    $novel->update(['canonical_state_version_id' => $lastVersion->getKey()]);

    $progress = app(SmokeRunService::class)->progress($novel->fresh());

    expect($progress->canonicalChapters)->toBe(20)
        ->and($progress->percent())->toBe(100)
        ->and($progress->hasDuplicateCommit)->toBeFalse()
        ->and($progress->missingSequences)->toBe([])
        ->and($progress->stateContinuous)->toBeTrue()
        ->and($progress->cost)->toBe(1.0)
        ->and($progress->status)->toBe('completed');
});

test('a smoke run preserves its boundary across pause and resume', function () {
    Queue::fake();
    [$novel] = smokeRunNovel();
    app(StartSmokeRunAction::class)->handle($novel);

    app(PauseGenerationAction::class)->handle($novel->fresh());
    $paused = app(SmokeRunService::class)->progress($novel->fresh());
    app(ResumeResolver::class)->resume($novel->fresh());
    $resumed = app(SmokeRunService::class)->progress($novel->fresh());

    expect($paused->status)->toBe('paused')
        ->and($resumed->status)->toBe('running')
        ->and(data_get($novel->fresh()->settings, 'smoke_run.target_sequence'))->toBe(20)
        ->and($novel->chapters()->where('sequence', 1)->count())->toBe(1);
});

test('progress exposes sequence and state discontinuity', function () {
    [$novel, $volume] = smokeRunNovel();
    $novel->update(['settings' => [
        'auto_generate' => false,
        'smoke_run' => ['status' => 'running', 'start_sequence' => 1, 'target_sequence' => 20],
    ]]);
    Chapter::factory()->for($novel)->for($volume)->create(['sequence' => 1, 'status' => ChapterStatus::Canonical]);
    Chapter::factory()->for($novel)->for($volume)->create(['sequence' => 3, 'status' => ChapterStatus::Canonical]);

    $progress = app(SmokeRunService::class)->progress($novel->fresh());

    expect($progress->missingSequences)->toBe([2])
        ->and($progress->sequenceHealthy())->toBeFalse()
        ->and($progress->stateContinuous)->toBeFalse()
        ->and($progress->status)->toBe('stopped');
});
