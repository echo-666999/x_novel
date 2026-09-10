<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\Enums\NovelStatus;
use App\Enums\VolumeStatus;
use App\Filament\Pages\Dashboard;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('dashboard shows reliability summary empty state and start action', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('50 章可靠性汇总')
        ->assertSee('Reliability Summary')
        ->assertSee('尚未启动 50 章可靠性长跑')
        ->assertActionExists('startReliabilityRun');
});

test('dashboard starts and displays reliability run', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating, 'title' => '雾海长明']);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(Dashboard::class)
        ->callAction('startReliabilityRun', data: ['novel_id' => $novel->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified('50 章可靠性长跑已启动')
        ->assertSee('雾海长明')
        ->assertSee('0/50 · 0%')
        ->assertSee('样本不足');

    expect(data_get($novel->fresh()->settings, 'reliability_run.target_sequence'))->toBe(50);
});
