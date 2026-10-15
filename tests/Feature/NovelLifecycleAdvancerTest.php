<?php

use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Enums\VolumeStatus;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryArc;
use App\Models\StoryStateVersion;
use App\Models\Volume;
use App\Services\AutomaticGenerationRecoveryService;
use App\Services\NovelLifecycleAdvancer;
use App\Services\StalledRunRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('lifecycle advancer completes the current volume and activates only the next main arc', function () {
    $novel = lifecycleNovel();
    $firstVolume = Volume::factory()->for($novel)->create(['sequence' => 1, 'status' => VolumeStatus::Active]);
    StoryArc::factory()->for($novel)->forVolume($firstVolume)->create([
        'sequence' => 1,
        'type' => StoryArcType::Main,
        'progress' => 1,
        'status' => StoryArcStatus::Completed,
        'completion_conditions' => [],
    ]);
    $secondVolume = Volume::factory()->for($novel)->create(['sequence' => 2, 'status' => VolumeStatus::Planned]);
    $firstMain = StoryArc::factory()->for($novel)->forVolume($secondVolume)->create([
        'sequence' => 1,
        'type' => StoryArcType::Main,
        'status' => StoryArcStatus::Planned,
    ]);
    $laterMain = StoryArc::factory()->for($novel)->forVolume($secondVolume)->create([
        'sequence' => 2,
        'type' => StoryArcType::Main,
        'status' => StoryArcStatus::Planned,
    ]);
    $subplot = StoryArc::factory()->for($novel)->forVolume($secondVolume)->create([
        'sequence' => 3,
        'type' => StoryArcType::Subplot,
        'status' => StoryArcStatus::Planned,
    ]);

    $result = app(NovelLifecycleAdvancer::class)->advance($novel);

    expect($result->state)->toBe('next_volume')
        ->and($result->canContinue)->toBeTrue()
        ->and($firstVolume->fresh()->status)->toBe(VolumeStatus::Completed)
        ->and($secondVolume->fresh()->status)->toBe(VolumeStatus::Active)
        ->and($firstMain->fresh()->status)->toBe(StoryArcStatus::Active)
        ->and($laterMain->fresh()->status)->toBe(StoryArcStatus::Planned)
        ->and($subplot->fresh()->status)->toBe(StoryArcStatus::Active);
});

test('lifecycle advancer enters ending audit and completes a fully closed novel', function () {
    $novel = lifecycleNovel();
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    StoryArc::factory()->for($novel)->forVolume($volume)->create([
        'type' => StoryArcType::Main,
        'progress' => 1,
        'status' => StoryArcStatus::Completed,
        'completion_conditions' => [],
    ]);

    $result = app(NovelLifecycleAdvancer::class)->advance($novel);

    expect($result->completed)->toBeTrue()
        ->and($result->canContinue)->toBeFalse()
        ->and($volume->fresh()->status)->toBe(VolumeStatus::Completed)
        ->and($novel->fresh()->status)->toBe(NovelStatus::Completed)
        ->and(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse();
});

test('a novel already in completing mode keeps generating until its active main arc is closed', function () {
    $novel = lifecycleNovel();
    $novel->update(['status' => NovelStatus::Completing]);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $arc = StoryArc::factory()->for($novel)->forVolume($volume)->create([
        'type' => StoryArcType::Main,
        'status' => StoryArcStatus::Active,
    ]);

    $result = app(NovelLifecycleAdvancer::class)->advance($novel);

    expect($result->canContinue)->toBeTrue()
        ->and($result->completed)->toBeFalse()
        ->and($arc->fresh()->status)->toBe(StoryArcStatus::Active)
        ->and($novel->fresh()->status)->toBe(NovelStatus::Completing);
});

test('two volumes with multiple main arcs recover a lost worker and advance deterministically to completion', function () {
    Queue::fake();
    $novel = lifecycleNovel();
    $firstVolume = Volume::factory()->for($novel)->create(['sequence' => 1, 'status' => VolumeStatus::Active]);
    $firstArc = StoryArc::factory()->for($novel)->forVolume($firstVolume)->create([
        'sequence' => 1, 'type' => StoryArcType::Main, 'status' => StoryArcStatus::Active, 'completion_conditions' => [],
    ]);
    $secondArc = StoryArc::factory()->for($novel)->forVolume($firstVolume)->create([
        'sequence' => 2, 'type' => StoryArcType::Main, 'status' => StoryArcStatus::Planned, 'completion_conditions' => [],
    ]);
    $secondVolume = Volume::factory()->for($novel)->create(['sequence' => 2, 'status' => VolumeStatus::Planned]);
    $thirdArc = StoryArc::factory()->for($novel)->forVolume($secondVolume)->create([
        'sequence' => 1, 'type' => StoryArcType::Main, 'status' => StoryArcStatus::Planned, 'completion_conditions' => [],
    ]);
    $fourthArc = StoryArc::factory()->for($novel)->forVolume($secondVolume)->create([
        'sequence' => 2, 'type' => StoryArcType::Main, 'status' => StoryArcStatus::Planned, 'completion_conditions' => [],
    ]);
    $chapter = Chapter::factory()->for($novel)->create([
        'volume_id' => $firstVolume->getKey(), 'sequence' => 1, 'status' => ChapterStatus::Planned,
    ]);
    GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter', 'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterPlanning, 'status' => RunStatus::Failed,
        'error_code' => StalledRunRecoveryService::ERROR_CODE,
        'error_metadata' => ['category' => 'worker_lost'],
    ]);

    $recovery = app(AutomaticGenerationRecoveryService::class)->recover();
    expect($recovery['worker_lost'])->toBe(1);
    Queue::assertPushed(PlanChapterJob::class, 1);

    $firstArc->update(['progress' => 1, 'status' => StoryArcStatus::Completed]);
    expect(app(NovelLifecycleAdvancer::class)->advance($novel->fresh())->state)->toBe('chapter_generation')
        ->and($secondArc->fresh()->status)->toBe(StoryArcStatus::Active);

    $secondArc->update(['progress' => 1, 'status' => StoryArcStatus::Completed]);
    expect(app(NovelLifecycleAdvancer::class)->advance($novel->fresh())->state)->toBe('next_volume')
        ->and($firstVolume->fresh()->status)->toBe(VolumeStatus::Completed)
        ->and($secondVolume->fresh()->status)->toBe(VolumeStatus::Active)
        ->and($thirdArc->fresh()->status)->toBe(StoryArcStatus::Active);

    $thirdArc->update(['progress' => 1, 'status' => StoryArcStatus::Completed]);
    expect(app(NovelLifecycleAdvancer::class)->advance($novel->fresh())->state)->toBe('chapter_generation')
        ->and($fourthArc->fresh()->status)->toBe(StoryArcStatus::Active);

    $fourthArc->update(['progress' => 1, 'status' => StoryArcStatus::Completed]);
    $completed = app(NovelLifecycleAdvancer::class)->advance($novel->fresh());

    expect($completed->completed)->toBeTrue()
        ->and($novel->fresh()->status)->toBe(NovelStatus::Completed)
        ->and(StoryArc::query()->where('novel_id', $novel->getKey())->where('type', StoryArcType::Main)->where('status', StoryArcStatus::Active)->count())->toBe(0);
});

function lifecycleNovel(): Novel
{
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['auto_generate' => true, 'auto_commit' => true, 'auto_commit_configured' => true],
    ]);
    NovelBible::factory()->for($novel)->create();
    $state = StoryStateVersion::factory()->for($novel)->create([
        'state' => [
            'schema_version' => 1,
            'characters' => [],
            'relationships' => [],
            'locations' => [],
            'items' => [],
            'world' => ['crises' => []],
            'timeline' => [],
            'open_threads' => [],
            'foreshadowings' => [],
            'reader_promises' => [],
            'character_arcs' => [['title' => '主角成长弧', 'status' => 'completed']],
        ],
    ]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);

    return $novel->fresh();
}
