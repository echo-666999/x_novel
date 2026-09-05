<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Generation;
use App\Filament\Pages\Memory;
use App\Filament\Pages\Novels;
use App\Filament\Pages\Review;
use App\Filament\Pages\Settings;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the x panel exposes the six top level workbench pages', function () {
    $panel = Filament::getPanel('x');

    expect([
        Dashboard::getNavigationLabel(),
        Novels::getNavigationLabel(),
        Generation::getNavigationLabel(),
        Review::getNavigationLabel(),
        Memory::getNavigationLabel(),
        Settings::getNavigationLabel(),
    ])->toBe([
        'Dashboard',
        'Novels',
        'Generation',
        'Review',
        'Memory',
        'Settings',
    ])->and([
        Dashboard::getNavigationSort(),
        Novels::getNavigationSort(),
        Generation::getNavigationSort(),
        Review::getNavigationSort(),
        Memory::getNavigationSort(),
        Settings::getNavigationSort(),
    ])->toBe([-2, 1, 2, 3, 4, 5])
        ->and($panel->getPages())->toContain(
            Dashboard::class,
            Novels::class,
            Generation::class,
            Review::class,
            Memory::class,
            Settings::class,
        );
});

test('guests are redirected to the panel login page', function (string $path) {
    $this->get($path)->assertRedirect('/x/login');
})->with([
    '/x',
    '/x/novels',
    '/x/generation',
    '/x/review',
    '/x/memory',
    '/x/settings',
]);

test('the owner can access every workbench page', function (string $path, string $emptyState) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertOk()
        ->assertSee($emptyState);
})->with([
    ['/x/novels', 'No novels yet'],
    ['/x/generation', 'No generation runs yet'],
    ['/x/review', 'Review inbox is empty'],
    ['/x/memory', 'No memories indexed'],
    ['/x/settings', 'Configuration sources are shown for reference.'],
]);

test('the owner account is restricted to the x panel', function () {
    $user = User::factory()->make();

    expect($user->canAccessPanel(Filament::getPanel('x')))->toBeTrue()
        ->and($user->canAccessPanel(Panel::make()->id('other')))->toBeFalse();
});

test('the dashboard provides the initial operational shell', function () {
    $this->actingAs(User::factory()->create())
        ->get('/x')
        ->assertOk()
        ->assertSeeTextInOrder([
            'Active Novels',
            'Current Chapter',
            "Today's Cost",
            'Needs Attention',
            'Recent Generation',
            'Due Foreshadowing',
        ])
        ->assertSee('No active novel')
        ->assertSee('No chapter selected')
        ->assertSee('No usage recorded')
        ->assertSee('Nothing requires action')
        ->assertSee('No generation activity')
        ->assertSee('No foreshadowing due');
});

test('the settings page provides the five read only configuration sections', function () {
    $this->actingAs(User::factory()->create())
        ->get('/x/settings')
        ->assertOk()
        ->assertSeeTextInOrder([
            'AI',
            'Generation',
            'Review',
            'Memory',
            'Budget',
        ])
        ->assertSee('Source: .env and config/services.php')
        ->assertSee('Source: config and Novel settings')
        ->assertSee('Read only');
});
