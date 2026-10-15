<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Jobs\ContinueAutoGenerationJob;
use App\Jobs\GenerateCanonicalChapterSummaryJob;
use App\Jobs\GenerateNovelOutlineJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\RefreshNovelProjectionJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Services\AutomaticGenerationRecoveryService;
use App\Services\NovelOutlinePipeline;
use App\Services\StalledRunRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('automatic recovery renews and redispatches an expired queued outline lease', function () {
    Queue::fake();
    $novel = Novel::factory()->create();
    $run = GenerationRun::factory()->for($novel)->create([
        'chapter_id' => null,
        'scope_type' => NovelOutlinePipeline::BATCH_SCOPE,
        'scope_id' => $novel->getKey(),
        'status' => RunStatus::Queued,
        'prompt_version' => NovelOutlinePipeline::BATCH_CONTRACT_VERSION,
        'lease_expires_at' => now()->subSecond(),
        'context_snapshot' => ['requested_volume_count' => 2],
    ]);

    $result = app(AutomaticGenerationRecoveryService::class)->recover();

    expect($result['queued_lease'])->toBe(1)
        ->and($run->fresh()->status)->toBe(RunStatus::Queued)
        ->and($run->fresh()->lease_expires_at->isFuture())->toBeTrue()
        ->and(data_get($run->fresh()->context_snapshot, 'automatic_recovery.queued_lease_attempts'))->toBe(1);
    Queue::assertPushed(GenerateNovelOutlineJob::class, fn (GenerateNovelOutlineJob $job): bool => $job->novelId === $novel->getKey() && $job->volumeCount === 2);
});

test('automatic recovery resumes a persisted worker lost chapter stage', function () {
    Queue::fake();
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => null,
        'settings' => ['auto_generate' => true],
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 1, 'status' => ChapterStatus::Planned]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Failed,
        'error_code' => StalledRunRecoveryService::ERROR_CODE,
        'error_retryable' => false,
        'error_metadata' => ['category' => 'worker_lost'],
    ]);

    $result = app(AutomaticGenerationRecoveryService::class)->recover();

    expect($result['worker_lost'])->toBe(1)
        ->and(data_get($run->fresh()->context_snapshot, 'recovery.resumed_at'))->not->toBeNull()
        ->and($novel->fresh()->status)->toBe(NovelStatus::Generating);
    Queue::assertPushed(PlanChapterJob::class, 1);
});

test('automatic recovery dispatches the required summary chain after a post commit dispatch gap', function () {
    Queue::fake();
    [$novel, $chapter, $state] = canonicalChapterWithoutSummary();
    DB::table('chapters')->where('id', $chapter->getKey())->update(['updated_at' => now()->subMinutes(10)]);

    $result = app(AutomaticGenerationRecoveryService::class)->recover();

    expect($result['post_commit'])->toBe(1);
    Queue::assertPushedWithChain(GenerateCanonicalChapterSummaryJob::class, [
        ContinueAutoGenerationJob::class,
    ]);
    Queue::assertPushed(UpdateMemoryJob::class);
    Queue::assertPushed(RefreshNovelProjectionJob::class);
});

test('automatic recovery waits for a recently committed summary job before treating it as a dispatch gap', function () {
    Queue::fake();
    canonicalChapterWithoutSummary();

    $result = app(AutomaticGenerationRecoveryService::class)->recover();

    expect($result['post_commit'])->toBe(0);
    Queue::assertNothingPushed();
});

test('automatic recovery requeues an independently failed projection', function () {
    Queue::fake();
    [$novel, $chapter, $state] = canonicalChapterWithoutSummary('已有摘要');
    $novel->update(['settings' => [
        'auto_generate' => true,
        'post_commit_failures' => [
            'projection' => [
                'chapter_id' => $chapter->getKey(),
                'canonical_artifact_id' => $chapter->canonical_artifact_id,
                'state_version_id' => $state->getKey(),
                'recovery_attempts' => 0,
            ],
        ],
    ]]);

    $result = app(AutomaticGenerationRecoveryService::class)->recover();

    expect($result['post_commit'])->toBe(1)
        ->and(data_get($novel->fresh()->settings, 'post_commit_failures.projection.recovery_attempts'))->toBe(1);
    Queue::assertPushed(RefreshNovelProjectionJob::class, fn (RefreshNovelProjectionJob $job): bool => $job->stateVersionId === $state->getKey());
});

test('automatic recovery persists a stop after the post commit recovery ceiling', function () {
    Queue::fake();
    [$novel, $chapter, $state] = canonicalChapterWithoutSummary('已有摘要');
    $novel->update(['settings' => [
        'auto_generate' => true,
        'post_commit_failures' => [
            'projection' => [
                'chapter_id' => $chapter->getKey(),
                'canonical_artifact_id' => $chapter->canonical_artifact_id,
                'state_version_id' => $state->getKey(),
                'recovery_attempts' => 3,
            ],
        ],
    ]]);

    $result = app(AutomaticGenerationRecoveryService::class)->recover();

    expect($result['exhausted'])->toBe(1)
        ->and(data_get($novel->fresh()->settings, 'auto_generate'))->toBeFalse()
        ->and(data_get($novel->fresh()->settings, 'auto_stop.code'))->toBe('post_commit_recovery_exhausted')
        ->and(data_get($novel->fresh()->settings, 'post_commit_failures.projection.exhausted_at'))->not->toBeNull();
    Queue::assertNotPushed(RefreshNovelProjectionJob::class);

    $second = app(AutomaticGenerationRecoveryService::class)->recover();
    expect($second['exhausted'])->toBe(0);
});

test('post commit recovery does not consume another attempt while the recovered job is still pending', function () {
    Queue::fake();
    [$novel, $chapter, $state] = canonicalChapterWithoutSummary('已有摘要');
    $novel->update(['settings' => [
        'auto_generate' => true,
        'post_commit_failures' => [
            'projection' => [
                'chapter_id' => $chapter->getKey(),
                'canonical_artifact_id' => $chapter->canonical_artifact_id,
                'state_version_id' => $state->getKey(),
                'recovery_attempts' => 0,
                'failed_at' => now()->subMinute()->toISOString(),
            ],
        ],
    ]]);

    app(AutomaticGenerationRecoveryService::class)->recover();
    app(AutomaticGenerationRecoveryService::class)->recover();

    expect(data_get($novel->fresh()->settings, 'post_commit_failures.projection.recovery_attempts'))->toBe(1);
    Queue::assertPushed(RefreshNovelProjectionJob::class, 1);
});

test('automatic recovery ignores an obsolete worker lost run that has a newer run for the same scope', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Planned]);
    $old = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Failed,
        'error_code' => StalledRunRecoveryService::ERROR_CODE,
    ]);
    GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Succeeded,
    ]);

    $result = app(AutomaticGenerationRecoveryService::class)->recover();

    expect($result['worker_lost'])->toBe(0)
        ->and(data_get($old->fresh()->context_snapshot, 'automatic_recovery'))->toBeNull();
    Queue::assertNothingPushed();
});

test('automatic recovery records worker recovery exhaustion only once', function () {
    Queue::fake();
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'settings' => ['auto_generate' => true],
    ]);
    $chapter = Chapter::factory()->for($novel)->create(['status' => ChapterStatus::Planned]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Failed,
        'error_code' => StalledRunRecoveryService::ERROR_CODE,
        'error_metadata' => ['category' => 'worker_lost'],
        'context_snapshot' => ['automatic_recovery' => ['attempts' => 3]],
    ]);

    $first = app(AutomaticGenerationRecoveryService::class)->recover();
    $second = app(AutomaticGenerationRecoveryService::class)->recover();

    expect($first['exhausted'])->toBe(1)
        ->and($second['exhausted'])->toBe(0)
        ->and(data_get($run->fresh()->context_snapshot, 'automatic_recovery.exhausted_at'))->not->toBeNull()
        ->and(data_get($novel->fresh()->settings, 'auto_stop.code'))->toBe('worker_lost');
});

/** @return array{Novel, Chapter, StoryStateVersion} */
function canonicalChapterWithoutSummary(?string $summary = null): array
{
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => 1,
        'settings' => ['auto_generate' => true],
    ]);
    $chapter = Chapter::factory()->for($novel)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Canonical,
        'summary' => $summary,
    ]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::ChapterDraft]);
    $chapter->update(['canonical_artifact_id' => $artifact->getKey()]);
    $state = StoryStateVersion::factory()->for($novel)->for($chapter)->create(['version' => 1]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);

    return [$novel->fresh(), $chapter->fresh(), $state];
}
