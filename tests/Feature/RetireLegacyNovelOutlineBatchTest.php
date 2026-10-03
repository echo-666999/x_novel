<?php

use App\Actions\Novels\RetireLegacyNovelOutlineBatchAction;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Jobs\GenerateNovelOutlineSkeletonJob;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Services\NovelOutlinePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function legacyOutlineBatch(RunStatus $status = RunStatus::Running): GenerationRun
{
    $novel = Novel::factory()->create();

    return GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(),
        'scope_type' => NovelOutlinePipeline::BATCH_SCOPE,
        'scope_id' => $novel->getKey(),
        'status' => $status,
        'prompt_version' => NovelOutlinePipeline::LEGACY_BATCH_PROMPT_VERSION,
        'provider' => 'openai',
        'model_policy' => 'test-model',
        'context_snapshot' => ['requested_volume_count' => 3],
        'started_at' => now()->subMinute(),
    ]);
}

test('retires an unfinished legacy outline batch while preserving all history', function () {
    $batch = legacyOutlineBatch();
    $child = GenerationRun::factory()->create([
        'novel_id' => $batch->novel_id,
        'scope_type' => NovelOutlinePipeline::FOUNDATION_SCOPE,
        'scope_id' => $batch->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->create([
        'generation_run_id' => $child->getKey(),
        'type' => ArtifactType::OutlineFoundation,
    ]);
    $failed = GenerationRun::factory()->create([
        'novel_id' => $batch->novel_id,
        'scope_type' => NovelOutlinePipeline::SKELETON_SCOPE,
        'scope_id' => $batch->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'finished_at' => now(),
    ]);

    $retired = app(RetireLegacyNovelOutlineBatchAction::class)->handle($batch, 'OGR-007 release');

    expect($retired->status)->toBe(RunStatus::Cancelled)
        ->and($retired->error_code)->toBe(RetireLegacyNovelOutlineBatchAction::ERROR_CODE)
        ->and($retired->error_retryable)->toBeFalse()
        ->and(data_get($retired->error_metadata, 'retired_from_status'))->toBe('running')
        ->and(data_get($retired->error_metadata, 'preserved_child_run_count'))->toBe(2)
        ->and(data_get($retired->error_metadata, 'preserved_artifact_count'))->toBe(1)
        ->and(data_get($retired->context_snapshot, 'contract_upgrade.to_prompt_version'))->toBe(NovelOutlinePipeline::BATCH_PROMPT_VERSION)
        ->and(GenerationRun::query()->whereKey($child)->exists())->toBeTrue()
        ->and(GenerationRun::query()->whereKey($failed)->exists())->toBeTrue()
        ->and(GenerationArtifact::query()->whereKey($artifact)->exists())->toBeTrue();

    $again = app(RetireLegacyNovelOutlineBatchAction::class)->handle($retired, 'ignored duplicate');
    expect($again->status)->toBe(RunStatus::Cancelled)
        ->and(data_get($again->context_snapshot, 'contract_upgrade.reason'))->toBe('OGR-007 release');
});

test('refuses retirement while a child stage remains active', function () {
    $batch = legacyOutlineBatch();
    GenerationRun::factory()->create([
        'novel_id' => $batch->novel_id,
        'scope_type' => NovelOutlinePipeline::SKELETON_SCOPE,
        'scope_id' => $batch->getKey(),
        'status' => RunStatus::Running,
    ]);

    expect(fn () => app(RetireLegacyNovelOutlineBatchAction::class)->handle($batch, 'upgrade'))
        ->toThrow(ValidationException::class);
    expect($batch->fresh()->status)->toBe(RunStatus::Running);
});

test('refuses current or successful batches', function (string $promptVersion, RunStatus $status) {
    $batch = legacyOutlineBatch($status);
    $batch->update(['prompt_version' => $promptVersion]);

    expect(fn () => app(RetireLegacyNovelOutlineBatchAction::class)->handle($batch, 'upgrade'))
        ->toThrow(ValidationException::class);
})->with([
    'current contract' => [NovelOutlinePipeline::BATCH_PROMPT_VERSION, RunStatus::Running],
    'successful legacy contract' => [NovelOutlinePipeline::LEGACY_BATCH_PROMPT_VERSION, RunStatus::Succeeded],
]);

test('legacy retirement command is dry run by default and executes explicitly', function () {
    $batch = legacyOutlineBatch(RunStatus::Failed);

    $this->artisan('novel:outline-retire-legacy-batch', ['batch' => $batch->getKey()])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();
    expect($batch->fresh()->status)->toBe(RunStatus::Failed);

    $this->artisan('novel:outline-retire-legacy-batch', [
        'batch' => $batch->getKey(),
        '--execute' => true,
        '--reason' => 'test release',
    ])->assertSuccessful();
    expect($batch->fresh()->status)->toBe(RunStatus::Cancelled)
        ->and($batch->fresh()->error_code)->toBe(RetireLegacyNovelOutlineBatchAction::ERROR_CODE);
});

test('a retained legacy skeleton payload is a no-op after batch retirement', function () {
    $batch = legacyOutlineBatch(RunStatus::Cancelled);
    $batch->update(['error_code' => RetireLegacyNovelOutlineBatchAction::ERROR_CODE]);

    $pipeline = Mockery::mock(NovelOutlinePipeline::class);
    $pipeline->shouldNotReceive('assertLegacyBatch');
    $pipeline->shouldNotReceive('generateSkeleton');
    $pipeline->shouldNotReceive('dispatchNext');

    (new GenerateNovelOutlineSkeletonJob($batch->getKey()))->handle($pipeline);

    expect($batch->fresh()->status)->toBe(RunStatus::Cancelled);
});
