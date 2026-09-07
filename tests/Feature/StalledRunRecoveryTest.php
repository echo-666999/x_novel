<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Jobs\ReviewChapterJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Volume;
use App\Services\StalledRunRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('generation.stalled_run_after_seconds', 60);
});

test('stalled chapter workflow runs are marked worker lost without touching recent or derived runs', function () {
    $stalled = GenerationRun::factory()->create([
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Running,
        'updated_at' => now()->subMinutes(2),
    ]);
    $recent = GenerationRun::factory()->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Running,
        'updated_at' => now(),
    ]);
    $embedding = GenerationRun::factory()->create([
        'stage' => GenerationStage::Embedding,
        'status' => RunStatus::Running,
        'updated_at' => now()->subMinutes(2),
    ]);

    $count = app(StalledRunRecoveryService::class)->markStalledRuns();

    expect($count)->toBe(1)
        ->and($stalled->fresh()->status)->toBe(RunStatus::Failed)
        ->and($stalled->fresh()->error_code)->toBe(StalledRunRecoveryService::ERROR_CODE)
        ->and($stalled->fresh()->finished_at)->not->toBeNull()
        ->and($recent->fresh()->status)->toBe(RunStatus::Running)
        ->and($embedding->fresh()->status)->toBe(RunStatus::Running);
});

test('worker crash after artifact persistence resumes the next database stage once', function () {
    Queue::fake();
    [$novel, $chapter] = workerRecoveryChapter(ChapterStatus::Review);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Running,
        'started_at' => now()->subMinutes(3),
        'updated_at' => now()->subMinutes(2),
    ]);
    GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::ChapterDraft]);

    $recovery = app(StalledRunRecoveryService::class);
    $point = $recovery->recover($run);

    expect($point->key)->toBe('review')
        ->and($novel->fresh()->status)->toBe(NovelStatus::Generating)
        ->and($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->error_code)->toBe(StalledRunRecoveryService::ERROR_CODE)
        ->and(data_get($run->fresh()->context_snapshot, 'recovery.resumed_at'))->not->toBeNull()
        ->and(fn () => $recovery->recover($run))->toThrow(ValidationException::class, '已经恢复或正在恢复');

    Queue::assertPushed(ReviewChapterJob::class, 1);
});

test('the maintenance command marks a simulated worker crash', function () {
    $run = GenerationRun::factory()->create([
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Running,
        'updated_at' => now()->subMinutes(2),
    ]);

    $this->artisan('generation:mark-stalled')
        ->expectsOutput('已标记 1 个 Worker 丢失的 Generation Run。')
        ->assertSuccessful();

    expect($run->fresh()->error_code)->toBe(StalledRunRecoveryService::ERROR_CODE);
});

/** @return array{Novel, Chapter} */
function workerRecoveryChapter(ChapterStatus $status): array
{
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => null,
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create([
        'sequence' => 1,
        'status' => $status,
    ]);

    return [$novel->refresh(), $chapter];
}
