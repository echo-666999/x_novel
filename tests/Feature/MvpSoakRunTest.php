<?php

use App\Actions\Generation\CheckNextAction;
use App\Actions\Generation\PauseGenerationAction;
use App\Actions\Generation\StartMvpSoakRunAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Models\UsageRecord;
use App\Models\Volume;
use App\Services\MvpSoakRunService;
use App\Services\ResumeResolver;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function mvpSoakNovel(int $currentSequence = 0): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating, 'current_chapter_sequence' => $currentSequence ?: null]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    if ($currentSequence > 0) {
        Chapter::factory()->for($novel)->for($volume)->create(['sequence' => $currentSequence, 'status' => ChapterStatus::Canonical]);
    }

    return [$novel->fresh(), $volume];
}

test('starting an mvp soak run records one hundred chapter boundary and queues only first chapter', function () {
    Queue::fake();
    [$novel] = mvpSoakNovel(12);

    $chapter = app(StartMvpSoakRunAction::class)->handle($novel);
    $settings = $novel->fresh()->settings;

    expect($chapter->sequence)->toBe(13)
        ->and(data_get($settings, 'soak_run.status'))->toBe('running')
        ->and(data_get($settings, 'soak_run.start_sequence'))->toBe(13)
        ->and(data_get($settings, 'soak_run.target_sequence'))->toBe(112)
        ->and(data_get($settings, 'auto_generate'))->toBeTrue();
    Queue::assertPushed(PlanChapterJob::class, 1);
});

test('one hundred sequential canonical callbacks produce no duplicates or skipped chapters', function () {
    Queue::fake();
    [$novel] = mvpSoakNovel();
    $chapter = app(StartMvpSoakRunAction::class)->handle($novel);
    $state = $novel->canonicalStateVersion->state;
    $checkNext = app(CheckNextAction::class);

    foreach (range(1, 100) as $sequence) {
        expect($chapter->sequence)->toBe($sequence);
        $chapter->update(['status' => ChapterStatus::Canonical]);
        $versionState = [...$state, 'timeline' => ['chapter' => $sequence]];
        $version = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
            'version' => $sequence,
            'state' => $versionState,
            'checksum' => app(StoryStateService::class)->checksum($versionState),
        ]);
        $novel->update(['current_chapter_sequence' => $sequence, 'canonical_state_version_id' => $version->getKey()]);

        $next = $checkNext->handle($novel->fresh(), $chapter->getKey());
        $duplicate = $checkNext->handle($novel->fresh(), $chapter->getKey());

        if ($sequence < 100) {
            expect($next?->sequence)->toBe($sequence + 1)->and($duplicate?->is($next))->toBeTrue();
            $chapter = $next;
        } else {
            expect($next)->toBeNull()->and($duplicate)->toBeNull();
        }
    }

    $summary = app(MvpSoakRunService::class)->summary($novel->fresh());

    expect($novel->chapters()->orderBy('sequence')->pluck('sequence')->all())->toBe(range(1, 100))
        ->and($summary->canonicalChapters)->toBe(100)
        ->and($summary->duplicateCanonicalCommits)->toBe(0)
        ->and($summary->missingCanonicalChapters)->toBe(0)
        ->and($summary->stateIntegrityIssues)->toBe(0)
        ->and(data_get($novel->fresh()->settings, 'soak_run.status'))->toBe('completed')
        ->and(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse();
    Queue::assertPushed(PlanChapterJob::class, 100);
});

test('mvp readiness audits state checksums pointer and provider cost ownership', function () {
    [$novel, $volume] = mvpSoakNovel();
    $novel->update(['settings' => ['auto_generate' => false, 'soak_run' => ['status' => 'completed', 'start_sequence' => 1, 'target_sequence' => 100]]]);
    $baseState = $novel->canonicalStateVersion->state;
    $lastVersion = null;
    $lastChapter = null;

    foreach (range(1, 100) as $sequence) {
        $lastChapter = Chapter::factory()->for($novel)->for($volume)->create(['sequence' => $sequence, 'status' => ChapterStatus::Canonical]);
        $state = [...$baseState, 'timeline' => ['chapter' => $sequence]];
        $lastVersion = StoryStateVersion::factory()->for($novel)->for($lastChapter)->create([
            'version' => $sequence, 'state' => $state, 'checksum' => app(StoryStateService::class)->checksum($state),
        ]);
        $run = GenerationRun::factory()->for($novel)->for($lastChapter)->create(['status' => RunStatus::Succeeded]);
        UsageRecord::factory()->for($run, 'generationRun')->create([
            'novel_id' => $novel->getKey(), 'chapter_id' => $lastChapter->getKey(),
            'input_tokens' => 1_000, 'output_tokens' => 500, 'estimated_cost' => 0.01,
        ]);
    }
    $novel->update(['current_chapter_sequence' => 100, 'canonical_state_version_id' => $lastVersion->getKey()]);

    $healthy = app(MvpSoakRunService::class)->summary($novel->fresh());

    expect($healthy->isReady())->toBeTrue()
        ->and($healthy->untraceableUsageRecords)->toBe(0)
        ->and($healthy->trackedCost)->toBe(1.0);

    DB::table($lastVersion->getTable())->where('id', $lastVersion->getKey())->update(['checksum' => str_repeat('0', 64)]);
    $wrongRun = GenerationRun::factory()->for($novel)->create(['chapter_id' => null]);
    UsageRecord::factory()->for($wrongRun, 'generationRun')->create([
        'novel_id' => $novel->getKey(), 'chapter_id' => $lastChapter->getKey(),
    ]);
    $unhealthy = app(MvpSoakRunService::class)->summary($novel->fresh());

    expect($unhealthy->isReady())->toBeFalse()
        ->and($unhealthy->stateIntegrityIssues)->toBe(1)
        ->and($unhealthy->untraceableUsageRecords)->toBe(1);
});

test('mvp soak run resumes at persisted chapter without changing its boundary', function () {
    Queue::fake();
    [$novel] = mvpSoakNovel();
    app(StartMvpSoakRunAction::class)->handle($novel);

    app(PauseGenerationAction::class)->handle($novel->fresh());
    app(ResumeResolver::class)->resume($novel->fresh());

    expect(data_get($novel->fresh()->settings, 'soak_run.target_sequence'))->toBe(100)
        ->and($novel->chapters()->where('sequence', 1)->count())->toBe(1)
        ->and($novel->chapters()->where('sequence', 2)->doesntExist())->toBeTrue();
});
