<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\NovelStatus;
use App\Enums\VolumeStatus;
use App\Filament\Pages\Dashboard;
use App\Models\Novel;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('dashboard shows the long run empty state and start action', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('20 章长跑进度')
        ->assertSee('Long Run Progress')
        ->assertSee('尚未启动 20 章长跑')
        ->assertActionExists('startSmokeRun');
});

test('dashboard starts and displays a tracked smoke run', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating, 'title' => '雾海长明']);
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(Dashboard::class)
        ->callAction('startSmokeRun', data: ['novel_id' => $novel->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified('20 章长跑已启动')
        ->assertSee('雾海长明')
        ->assertSee('0/20 · 0%')
        ->assertSee('运行中');

    expect(data_get($novel->fresh()->settings, 'smoke_run.target_sequence'))->toBe(20);
});
