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

test('dashboard shows mvp readiness empty state and soak action', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('MVP Readiness')
        ->assertSee('尚未启动 100 章 MVP 浸泡测试')
        ->assertActionExists('startMvpSoakRun');
});

test('dashboard starts and displays mvp soak progress', function () {
    Queue::fake();
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating, 'title' => '星河长卷']);
    NovelBible::factory()->for($novel)->create();
    app(InitializeNovelStateAction::class)->handle($novel);
    Volume::factory()->for($novel)->create(['status' => VolumeStatus::Active]);

    Livewire::test(Dashboard::class)
        ->callAction('startMvpSoakRun', data: ['novel_id' => $novel->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified('100 章 MVP 浸泡测试已启动')
        ->assertSee('星河长卷')
        ->assertSee('0/100 · 0%')
        ->assertSee('验收中');

    expect(data_get($novel->fresh()->settings, 'soak_run.target_sequence'))->toBe(100);
});
