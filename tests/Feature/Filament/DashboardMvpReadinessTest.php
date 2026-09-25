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

test('dashboard shows mvp readiness empty state and soak action', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('MVP Readiness')
        ->assertSee('尚未启动 100 章 MVP 浸泡测试')
        ->assertActionExists(TestAction::make('startMvpSoakRun')->schemaComponent('acceptanceActions', 'content'));
});

test('dashboard starts and displays mvp soak progress', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating, 'title' => '星河长卷']);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(Dashboard::class)
        ->callAction(TestAction::make('startMvpSoakRun')->schemaComponent('acceptanceActions', 'content'), data: ['novel_id' => $novel->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified('100 章 MVP 浸泡测试已启动')
        ->assertSee('星河长卷')
        ->assertSee('0/100 · 0%')
        ->assertSee('验收中');

    expect(data_get($novel->fresh()->settings, 'soak_run.target_sequence'))->toBe(100);
});

test('dashboard reports an active chapter workflow instead of throwing when starting an mvp soak run', function () {
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
        ->callAction(TestAction::make('startMvpSoakRun')->schemaComponent('acceptanceActions', 'content'), data: ['novel_id' => $novel->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified(
            Notification::make()
                ->title('无法启动 100 章 MVP 浸泡测试')
                ->body('该小说已有另一个活跃章节工作流。')
                ->danger(),
        );

    expect(data_get($novel->fresh()->settings, 'soak_run'))->toBeNull()
        ->and(Chapter::query()->whereBelongsTo($novel)->count())->toBe(1);
    Queue::assertNothingPushed();
});
