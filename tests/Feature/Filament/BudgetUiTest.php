<?php

use App\Filament\Pages\Settings;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('ai.cost.currency', 'USD');
    config()->set('ai.budget.daily_hard_limit', 5);
    config()->set('ai.budget.novel_total_limit', 20);
    config()->set('ai.budget.chapter_max_cost', 4);
});

test('settings shows the global budget usage and defaults', function () {
    UsageRecord::factory()->create(['estimated_cost' => 1.25, 'created_at' => now()]);

    Livewire::test(Settings::class)
        ->assertOk()
        ->assertSee('Daily Used / Limit')
        ->assertSee('USD 1.2500 / 5.0000')
        ->assertSee('Novel Total Default')
        ->assertSee('USD 20.0000')
        ->assertSee('Chapter Max Default')
        ->assertSee('USD 4.0000');
});

test('novel overview shows novel and current chapter used versus limit', function () {
    $novel = Novel::factory()->create([
        'current_chapter_sequence' => 3,
        'settings' => ['budget' => [
            'novel_total_limit' => 8,
            'chapter_max_cost' => 2,
        ]],
    ]);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 3]);
    UsageRecord::factory()->create([
        'novel_id' => $novel->getKey(),
        'chapter_id' => $chapter->getKey(),
        'estimated_cost' => 1.5,
    ]);
    UsageRecord::factory()->create([
        'novel_id' => $novel->getKey(),
        'estimated_cost' => 0.75,
    ]);

    Livewire::test(ViewNovel::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSee('Novel Used / Limit')
        ->assertSee('USD 2.2500 / 8.0000')
        ->assertSee('Current Chapter Used / Limit')
        ->assertSee('USD 1.5000 / 2.0000');
});

test('novel budget overrides can be saved without replacing other settings', function () {
    $novel = Novel::factory()->create([
        'settings' => ['temperature' => 0.5],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('Budget Limits')
        ->fillForm(['budget_limits' => [
            'novel_total_limit' => 12.5,
            'chapter_max_cost' => 1.25,
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($novel->refresh()->settings)->toBe([
        'temperature' => 0.5,
        'ai' => ['models' => []],
        'budget' => [
            'novel_total_limit' => 12.5,
            'chapter_max_cost' => 1.25,
        ],
    ]);
});
