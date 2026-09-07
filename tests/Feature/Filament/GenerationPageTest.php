<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Filament\Pages\Generation;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->assertSee('USD 0.012345');
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

test('run inspector exposes l0 l1 token allocation and state version', function () {
    $run = GenerationRun::factory()->create([
        'state_version' => 7,
        'context_snapshot' => [
            'schema_version' => 1,
            'state_version' => 7,
            'l0' => ['bible_hard_constraints' => ['禁止复活死者']],
            'l1' => ['canonical_story_state' => ['timeline' => ['夜幕降临']]],
            'token_allocation' => ['budget' => 4000, 'used' => 1200, 'remaining' => 2800, 'sections' => ['l0' => 400, 'l1' => 800]],
        ],
    ]);

    Livewire::test(Generation::class)
        ->assertTableActionExists('inspect', fn ($action): bool => $action->isModalSlideOver())
        ->mountTableAction('inspect', $run)
        ->assertSchemaComponentExists('context_l0')
        ->assertSchemaComponentExists('context_l1')
        ->assertSchemaComponentExists('context_token_allocation');
});
