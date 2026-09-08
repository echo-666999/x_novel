<?php

use App\Actions\Generation\CheckNextAction;
use App\Actions\Generation\PauseGenerationAction;
use App\Actions\Generation\StartReliabilityRunAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\Review;
use App\Models\StoryEvent;
use App\Models\StoryStateVersion;
use App\Models\UsageRecord;
use App\Models\Volume;
use App\Services\ReliabilityRunService;
use App\Services\ResumeResolver;
use App\Services\StalledRunRecoveryService;
use App\Services\StoryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function reliabilityNovel(int $currentSequence = 0): array
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating, 'current_chapter_sequence' => $currentSequence ?: null]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    if ($currentSequence > 0) {
        Chapter::factory()->for($novel)->for($volume)->create(['sequence' => $currentSequence, 'status' => ChapterStatus::Canonical]);
    }

    return [$novel->fresh(), $volume];
}

test('starting a reliability run records fifty chapter boundary and queues only first chapter', function () {
    Queue::fake();
    [$novel] = reliabilityNovel(7);

    $chapter = app(StartReliabilityRunAction::class)->handle($novel);
    $settings = $novel->fresh()->settings;

    expect($chapter->sequence)->toBe(8)
        ->and(data_get($settings, 'reliability_run.status'))->toBe('running')
        ->and(data_get($settings, 'reliability_run.start_sequence'))->toBe(8)
        ->and(data_get($settings, 'reliability_run.target_sequence'))->toBe(57)
        ->and(data_get($settings, 'auto_generate'))->toBeTrue();
    Queue::assertPushed(PlanChapterJob::class, 1);
});

test('fifty sequential canonical callbacks stop exactly at reliability boundary', function () {
    Queue::fake();
    [$novel] = reliabilityNovel();
    $chapter = app(StartReliabilityRunAction::class)->handle($novel);
    $state = $novel->canonicalStateVersion->state;
    $checkNext = app(CheckNextAction::class);

    foreach (range(1, 50) as $sequence) {
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

        if ($sequence < 50) {
            expect($next?->sequence)->toBe($sequence + 1)->and($duplicate?->is($next))->toBeTrue();
            $chapter = $next;
        } else {
            expect($next)->toBeNull()->and($duplicate)->toBeNull();
        }
    }

    expect($novel->chapters()->orderBy('sequence')->pluck('sequence')->all())->toBe(range(1, 50))
        ->and(data_get($novel->fresh()->settings, 'reliability_run.status'))->toBe('completed')
        ->and(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse();
    Queue::assertPushed(PlanChapterJob::class, 50);
});

test('reliability run keeps its fifty chapter boundary across pause and resume', function () {
    Queue::fake();
    [$novel] = reliabilityNovel();
    app(StartReliabilityRunAction::class)->handle($novel);

    app(PauseGenerationAction::class)->handle($novel->fresh());
    $paused = app(ReliabilityRunService::class)->summary($novel->fresh());
    app(ResumeResolver::class)->resume($novel->fresh());
    $resumed = app(ReliabilityRunService::class)->summary($novel->fresh());

    expect($paused->status)->toBe('paused')
        ->and($resumed->status)->toBe('running')
        ->and(data_get($novel->fresh()->settings, 'reliability_run.target_sequence'))->toBe(50)
        ->and($novel->chapters()->where('sequence', 1)->count())->toBe(1);
});

test('reliability summary reports recovery memory story review cost and context metrics', function () {
    [$novel, $volume] = reliabilityNovel();
    $novel->update(['settings' => ['auto_generate' => false, 'reliability_run' => ['status' => 'completed', 'start_sequence' => 1, 'target_sequence' => 50]]]);
    $chapters = collect();
    $lastVersion = null;
    $baseState = $novel->canonicalStateVersion->state;

    foreach (range(1, 50) as $sequence) {
        $chapter = Chapter::factory()->for($novel)->for($volume)->create(['sequence' => $sequence, 'status' => ChapterStatus::Canonical]);
        $chapters->push($chapter);
        $state = [...$baseState, 'timeline' => ['chapter' => $sequence]];
        $lastVersion = StoryStateVersion::factory()->for($novel)->for($chapter)->create([
            'version' => $sequence, 'state' => $state, 'checksum' => app(StoryStateService::class)->checksum($state),
        ]);
        $run = GenerationRun::factory()->for($novel)->for($chapter)->create(['status' => RunStatus::Succeeded]);
        UsageRecord::factory()->for($run, 'generationRun')->create([
            'novel_id' => $novel->getKey(), 'chapter_id' => $chapter->getKey(),
            'input_tokens' => 1_000 + $sequence, 'output_tokens' => 500,
            'estimated_cost' => $sequence <= 25 ? 0.10 : 0.12,
        ]);
        Memory::factory()->for($novel)->create([
            'source_type' => 'chapter', 'source_id' => $chapter->getKey(), 'valid_from_chapter' => $sequence,
            'embedding' => $sequence <= 40 ? '[0.1,0.2,0.3]' : null,
        ]);
    }
    $novel->update(['current_chapter_sequence' => 50, 'canonical_state_version_id' => $lastVersion->getKey()]);

    $retry = GenerationRun::factory()->for($novel)->for($chapters[1])->create(['attempt' => 2]);
    GenerationRun::factory()->for($novel)->for($chapters[2])->create(['stage' => GenerationStage::Rewrite]);
    $crash = GenerationRun::factory()->for($novel)->for($chapters[3])->create([
        'status' => RunStatus::Failed, 'error_code' => StalledRunRecoveryService::ERROR_CODE,
        'context_snapshot' => ['recovery' => ['resumed_at' => now()->toISOString()]],
    ]);
    Review::factory()->for($retry, 'generationRun')->create(['decision' => ReviewDecision::Rewrite]);
    StoryEvent::factory()->for($novel)->for($chapters[4])->create(['event_type' => EventType::ForeshadowingPaidOff]);

    $summary = app(ReliabilityRunService::class)->summary($novel->fresh());

    expect($summary->canonicalChapters)->toBe(50)
        ->and($summary->sequenceContinuous)->toBeTrue()
        ->and($summary->stateContinuous)->toBeTrue()
        ->and($summary->retryRuns)->toBe(1)
        ->and($summary->workerCrashes)->toBe(1)
        ->and($summary->workerRecoveries)->toBe(1)
        ->and($summary->memories)->toBe(50)
        ->and($summary->embeddedMemories)->toBe(40)
        ->and($summary->foreshadowingEvents)->toBe(1)
        ->and($summary->reviews)->toBe(1)
        ->and($summary->rewrites)->toBe(1)
        ->and($summary->cost)->toBe(5.5)
        ->and($summary->costDriftPercent)->toBe(20.0)
        ->and($summary->averageContextTokens)->toBe(1026)
        ->and($summary->maximumContextTokens)->toBe(1050);
});
