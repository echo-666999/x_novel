<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Filament\Pages\Generation;
use App\Filament\Resources\Novels\NovelResource;
use App\Jobs\AssembleChapterJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\ReviewChapterJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\Review;
use App\Models\Scene;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('generation page lists traceable run fields', function () {
    $novel = Novel::factory()->create(['title' => '雾海长明']);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 7]);
    $run = GenerationRun::factory()->for($novel)->create([
        'chapter_id' => $chapter->getKey(),
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterPlanning,
        'status' => RunStatus::Succeeded,
        'model_policy' => 'gpt-test-model',
        'started_at' => now()->subMilliseconds(850),
        'finished_at' => now(),
    ]);
    UsageRecord::factory()->create([
        'generation_run_id' => $run->getKey(),
        'estimated_cost' => 0.012345,
    ]);

    Livewire::test(Generation::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$run])
        ->assertSee('雾海长明')
        ->assertSee('第 7 章')
        ->assertSee('章节规划')
        ->assertSee('已成功')
        ->assertSee('gpt-test-model')
        ->assertSee('USD 0.0123');
});

test('generation run inspector shows context artifacts errors and usage', function () {
    $run = GenerationRun::factory()->create([
        'status' => RunStatus::Failed,
        'context_snapshot' => ['state_version' => 3, 'fact_ids' => [8]],
        'error_code' => 'provider_timeout',
        'error_message' => 'Provider request timed out.',
    ]);
    GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::Context,
        'content' => 'Context payload',
        'checksum' => str_repeat('a', 64),
    ]);
    UsageRecord::factory()->create([
        'generation_run_id' => $run->getKey(),
        'provider' => 'openai',
        'model' => 'gpt-test-model',
        'request_id' => 'request-debug-1',
    ]);

    Livewire::test(Generation::class)
        ->assertTableActionExists('inspect', fn ($action): bool => $action->isModalSlideOver());

    $run->load(['artifacts', 'usageRecords']);

    expect($run->context_snapshot)->toBe(['state_version' => 3, 'fact_ids' => [8]])
        ->and($run->artifacts)->toHaveCount(1)
        ->and($run->artifacts->first()->content)->toBe('Context payload')
        ->and($run->error_code)->toBe('provider_timeout')
        ->and($run->usageRecords)->toHaveCount(1)
        ->and($run->usageRecords->first()->request_id)->toBe('request-debug-1');
});

test('generation page has a useful empty state', function () {
    Livewire::test(Generation::class)
        ->assertOk()
        ->assertSee('暂无生成记录')
        ->assertSee('每个流水线阶段');
});

test('recovery dashboard summarizes actionable failures and review gates', function () {
    $novel = Novel::factory()->create();
    $retryChapter = Chapter::factory()->for($novel)->create();
    $resumeChapter = Chapter::factory()->for($novel)->create();
    $blockedChapter = Chapter::factory()->for($novel)->create();
    $attentionChapter = Chapter::factory()->for($novel)->create();

    GenerationRun::factory()->for($novel)->for($retryChapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $retryChapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'context_snapshot' => ['state_version' => 3],
    ]);
    GenerationRun::factory()->for($novel)->for($resumeChapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $resumeChapter->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Failed,
        'error_code' => 'worker_interrupted',
    ]);

    foreach ([[$blockedChapter, ReviewDecision::Block], [$attentionChapter, ReviewDecision::NeedsAttention]] as [$chapter, $decision]) {
        $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
            'scope_type' => 'chapter',
            'scope_id' => $chapter->getKey(),
            'stage' => GenerationStage::Review,
            'status' => RunStatus::Succeeded,
        ]);
        $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::ReviewResult]);
        Review::query()->create([
            'generation_run_id' => $run->getKey(),
            'artifact_id' => $artifact->getKey(),
            'decision' => $decision,
            'score' => 70,
            'continuity_score' => 70,
            'plan_score' => 70,
            'character_score' => 70,
            'progress_score' => 70,
            'repetition_score' => 70,
            'pacing_score' => 70,
            'style_score' => 70,
            'findings' => [],
        ]);
    }

    Livewire::test(Generation::class)
        ->assertSchemaComponentExists('recovery_failed', null, fn ($component): bool => $component->getState() === 2)
        ->assertSchemaComponentExists('recovery_blocked', null, fn ($component): bool => $component->getState() === 1)
        ->assertSchemaComponentExists('recovery_recoverable', null, fn ($component): bool => $component->getState() === 2)
        ->assertSchemaComponentExists('recovery_needs_attention', null, fn ($component): bool => $component->getState() === 1)
        ->assertActionVisible('resumeNext')
        ->assertActionVisible('retryNext')
        ->assertActionVisible('openContext')
        ->assertActionHasUrl('openReview', App\Filament\Pages\Review::getUrl());
});

test('recovery dashboard quick actions reuse retry resume and context inspection', function () {
    Queue::fake();
    $novel = Novel::factory()->create();
    $retryChapter = Chapter::factory()->for($novel)->create();
    $resumeChapter = Chapter::factory()->for($novel)->create();
    $retryRun = GenerationRun::factory()->for($novel)->for($retryChapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $retryChapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'context_snapshot' => ['state_version' => 4],
    ]);
    GenerationRun::factory()->for($novel)->for($resumeChapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $resumeChapter->getKey(),
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Failed,
        'error_code' => 'worker_interrupted',
    ]);

    Livewire::test(Generation::class)
        ->callAction('retryNext')
        ->assertNotified('失败阶段已重新排队')
        ->callAction('resumeNext')
        ->assertNotified('恢复任务已排队')
        ->assertActionVisible('openContext');

    Queue::assertPushed(AssembleChapterJob::class, fn (AssembleChapterJob $job): bool => $job->chapterId === $retryChapter->getKey() && $job->regenerate);
    Queue::assertPushed(ReviewChapterJob::class, fn (ReviewChapterJob $job): bool => $job->chapterId === $resumeChapter->getKey() && ! $job->regenerate);
    expect($retryRun->context_snapshot)->toBe(['state_version' => 4]);
});

test('run inspector exposes all context layers tokens truncation and selected memory', function () {
    $novel = Novel::factory()->create();
    $memory = Memory::factory()->for($novel)->create([
        'summary' => '旧都密钥属于主角。',
        'valid_from_chapter' => 6,
    ]);
    $run = GenerationRun::factory()->create([
        'novel_id' => $novel->getKey(),
        'state_version' => 7,
        'context_snapshot' => [
            'schema_version' => 2,
            'state_version' => 7,
            'bible_version' => 4,
            'style_contract_checksum' => 'style-checksum-123',
            'l0' => ['bible_hard_constraints' => ['禁止复活死者']],
            'l1' => ['canonical_story_state' => ['timeline' => ['夜幕降临']]],
            'l2' => ['recent_chapters' => [['sequence' => 6, 'summary' => '风暴逼近']]],
            'l3' => ['memories' => [['id' => $memory->getKey(), 'summary' => '旧都密钥属于主角。', 'final_score' => 0.88]]],
            'l4' => ['style' => '冷峻克制'],
            'memory_ids' => [$memory->getKey()],
            'truncated_sections' => ['l3.long_term_memory'],
            'token_allocation' => ['budget' => 4000, 'used' => 1200, 'remaining' => 2800, 'sections' => ['l0' => 400, 'l1' => 500, 'l2' => 200, 'l3' => 100]],
        ],
    ]);

    Livewire::test(Generation::class)
        ->assertTableActionExists('inspect', fn ($action): bool => $action->isModalSlideOver())
        ->mountTableAction('inspect', $run)
        ->assertSchemaComponentExists('context_l0')
        ->assertSchemaComponentExists('context_l1')
        ->assertSchemaComponentExists('context_l2')
        ->assertSchemaComponentExists('context_l3')
        ->assertSchemaComponentExists('context_l4')
        ->assertSchemaComponentExists('context_bible_version', null, fn ($component): bool => $component->getState() === 'v4')
        ->assertSchemaComponentExists('context_style_contract_checksum', null, fn ($component): bool => $component->getState() === 'style-checksum-123')
        ->assertSchemaComponentExists('context_token_allocation')
        ->assertSchemaComponentExists('context_truncated_sections')
        ->assertSchemaComponentExists('context_selected_memories');
});

test('failed run explains retryability and recommended action', function () {
    $run = GenerationRun::factory()->create([
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Failed,
        'error_code' => 'state_version_conflict',
        'error_message' => 'Canonical Story State 已变化。',
        'finished_at' => now(),
    ]);

    Livewire::test(Generation::class)
        ->mountTableAction('inspect', $run)
        ->assertSchemaComponentExists('error_code', null, fn ($component): bool => $component->getState() === 'state_version_conflict')
        ->assertSchemaComponentExists('failure_stage', null, fn ($component): bool => $component->getState() === '场景生成')
        ->assertSchemaComponentExists('failed_at')
        ->assertSchemaComponentExists('retryable', null, fn ($component): bool => $component->getState() === '否')
        ->assertSchemaComponentExists('recommended_action', null, fn ($component): bool => $component->getState() === '重建 Context 后重试')
        ->assertSchemaComponentExists('error_message', null, fn ($component): bool => $component->getState() === 'Canonical Story State 已变化。')
        ->unmountAction()
        ->assertTableActionHidden('retry', $run)
        ->assertTableActionHidden('resume', $run);
});

test('retry requeues a supported failed stage and keeps the chapter link', function () {
    Queue::fake();

    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $scene = Scene::factory()->for($chapter)->create();
    $run = GenerationRun::factory()->for($novel)->for($chapter)->for($scene)->create([
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
    ]);

    Livewire::test(Generation::class)
        ->assertTableActionVisible('retry', $run)
        ->assertTableActionHidden('resume', $run)
        ->assertTableActionHasUrl('openChapter', NovelResource::getUrl('chapter', [
            'record' => $novel,
            'chapter' => $chapter,
        ]), $run)
        ->callTableAction('retry', $run);

    Queue::assertPushed(GenerateSceneJob::class, fn (GenerateSceneJob $job): bool => $job->sceneId === $scene->getKey()
        && $job->cascade
        && $job->regenerationBatchId !== null);
});

test('worker interruption offers resume from persisted state', function () {
    Queue::fake();

    $novel = Novel::factory()->create();
    $chapter = Chapter::factory()->for($novel)->create();
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scene_id' => null,
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Failed,
        'error_code' => 'worker_interrupted',
    ]);

    Livewire::test(Generation::class)
        ->assertTableActionHidden('retry', $run)
        ->assertTableActionVisible('resume', $run)
        ->callTableAction('resume', $run);

    Queue::assertPushed(AssembleChapterJob::class, fn (AssembleChapterJob $job): bool => $job->chapterId === $chapter->getKey() && ! $job->regenerate);
});

test('stalled run is shown as worker lost and can recover from its persisted artifact', function () {
    Queue::fake();
    config()->set('generation.stalled_run_after_seconds', 60);

    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => null,
    ]);
    app(InitializeNovelStateAction::class)->handle($novel);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    $chapter = Chapter::factory()->for($novel)->for($volume)->create([
        'sequence' => 1,
        'status' => ChapterStatus::Review,
    ]);
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'scope_type' => 'chapter',
        'scope_id' => $chapter->getKey(),
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Running,
        'updated_at' => now()->subMinutes(2),
    ]);
    GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::ChapterDraft]);

    Livewire::test(Generation::class)
        ->assertSee('已停滞')
        ->assertTableActionVisible('recover', $run)
        ->assertTableActionHidden('retry', $run)
        ->callTableAction('recover', $run)
        ->assertNotified('恢复任务已排队')
        ->assertSee('Worker 丢失');

    expect($run->fresh()->error_code)->toBe('worker_lost');
    Queue::assertPushed(ReviewChapterJob::class, 1);
});
