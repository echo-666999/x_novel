<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\VolumeStatus;
use App\Filament\Pages\Dashboard;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\User;
use App\Models\Volume;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('generation.acceptance_tools_enabled', true);
});

test('dashboard shows the long run empty state and start action', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('20 章长跑进度')
        ->assertSee('Long Run Progress')
        ->assertSee('尚未启动 20 章长跑')
        ->assertActionExists(TestAction::make('startSmokeRun')->schemaComponent('acceptanceActions', 'content'));
});

test('dashboard starts and displays a tracked smoke run', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating, 'title' => '雾海长明']);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(Dashboard::class)
        ->callAction(TestAction::make('startSmokeRun')->schemaComponent('acceptanceActions', 'content'), data: ['novel_id' => $novel->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified('20 章长跑已启动')
        ->assertSee('雾海长明')
        ->assertSee('0/20 · 0%')
        ->assertSee('运行中');

    expect(data_get($novel->fresh()->settings, 'smoke_run.target_sequence'))->toBe(20);
});

test('dashboard reports an active chapter workflow instead of throwing when starting a smoke run', function () {
    Queue::fake();
    $novel = Novel::factory()->create([
        'status' => NovelStatus::Generating,
        'current_chapter_sequence' => 3,
    ]);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    $volume = Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);
    Chapter::factory()->for($novel)->create([
        'volume_id' => $volume->getKey(),
        'sequence' => 5,
        'status' => ChapterStatus::Generating,
    ]);

    Livewire::test(Dashboard::class)
        ->callAction(TestAction::make('startSmokeRun')->schemaComponent('acceptanceActions', 'content'), data: ['novel_id' => $novel->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified(
            Notification::make()
                ->title('无法启动 20 章长跑')
                ->body('该小说已有另一个活跃章节工作流。')
                ->danger(),
        );

    expect(data_get($novel->fresh()->settings, 'smoke_run'))->toBeNull()
        ->and(Chapter::query()->whereBelongsTo($novel)->count())->toBe(1);
    Queue::assertNothingPushed();
});
