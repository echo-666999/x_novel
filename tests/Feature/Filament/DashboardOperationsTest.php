<?php

use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Generation;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\Chapter;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Review;
use App\Models\UsageRecord;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('ai.cost.currency', 'USD');
});

test('dashboard shows live operations metrics recent generation and primary actions', function () {
    $novel = Novel::factory()->create(['title' => '潮声未眠', 'status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 7, 'title' => '归港', 'status' => ChapterStatus::Blocked]);
    $run = GenerationRun::factory()->for($novel)->create([
        'chapter_id' => $chapter,
        'scope_type' => 'chapter',
        'scope_id' => $chapter,
        'stage' => GenerationStage::Review,
        'status' => RunStatus::Failed,
        'started_at' => now()->subSeconds(12),
        'finished_at' => now(),
    ]);
    Review::factory()->for($run)->create(['decision' => ReviewDecision::NeedsAttention]);
    UsageRecord::factory()->for($run)->create([
        'novel_id' => $novel,
        'chapter_id' => $chapter,
        'input_tokens' => 1_000,
        'output_tokens' => 500,
        'estimated_cost' => 0.125,
    ]);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertActionExists('createNovel')
        ->assertActionExists('continueCurrentNovel')
        ->assertActionExists('openRecoveryCenter')
        ->assertSeeTextInOrder(['活跃小说', '1', '运行中章节', '0', '今日成本', 'USD 0.1250', '今日 Tokens', '1,500'])
        ->assertSeeTextInOrder(['Failed', '1', 'Blocked', '0', 'Needs Attention', '1'])
        ->assertSeeTextInOrder(['最近生成', '潮声未眠', '第 7 章', '审校', '失败', '12s', 'USD 0.1250'])
        ->assertSee('打开')
        ->assertDontSee('诊断与验收');
});

test('dashboard exposes acceptance tooling only when explicitly enabled', function () {
    Livewire::test(Dashboard::class)
        ->assertDontSee('诊断与验收')
        ->assertActionDoesNotExist('startSmokeRun');

    config()->set('generation.acceptance_tools_enabled', true);

    Livewire::test(Dashboard::class)
        ->assertSee('诊断与验收')
        ->assertActionExists(TestAction::make('startSmokeRun')->schemaComponent('acceptanceActions', 'content'))
        ->assertActionExists(TestAction::make('startReliabilityRun')->schemaComponent('acceptanceActions', 'content'))
        ->assertActionExists(TestAction::make('startMvpSoakRun')->schemaComponent('acceptanceActions', 'content'));
});

test('dashboard novel overview and recovery center agree on a retryable failure', function () {
    $novel = Novel::factory()->create(['title' => '一致性样本', 'status' => NovelStatus::Generating]);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 3, 'status' => ChapterStatus::Generating]);
    $run = GenerationRun::factory()->for($novel)->create([
        'chapter_id' => $chapter,
        'scope_type' => 'chapter',
        'scope_id' => $chapter,
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Failed,
        'error_code' => 'provider_timeout',
        'error_message' => 'Provider request timed out.',
        'error_retryable' => true,
        'error_metadata' => ['category' => 'external_temporary'],
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('一致性样本')
        ->assertSee('失败');

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('external_temporary')
        ->assertSee('重试失败阶段');

    Livewire::test(Generation::class)
        ->assertSee('provider_timeout')
        ->mountTableAction('inspect', $run)
        ->assertSchemaComponentExists('error_category', null, fn ($component): bool => $component->getState() === 'external_temporary')
        ->assertSchemaComponentExists('recommended_action', null, fn ($component): bool => $component->getState() === '重试');
});
