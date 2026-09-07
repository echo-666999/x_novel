<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings;
use App\Models\User;
use App\Services\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('dashboard and settings share the confirmed emergency stop action', function () {
    $emergencyStop = app(EmergencyStopService::class);

    Livewire::test(Dashboard::class)
        ->assertActionExists('emergencyStop', fn ($action): bool => $action->getLabel() === '紧急停止')
        ->callAction('emergencyStop')
        ->assertNotified('紧急停止已启用')
        ->assertActionExists('emergencyStop', fn ($action): bool => $action->getLabel() === '解除紧急停止');

    expect($emergencyStop->isActive())->toBeTrue();

    Livewire::test(Settings::class)
        ->assertActionExists('emergencyStop', fn ($action): bool => $action->getLabel() === '解除紧急停止')
        ->callAction('emergencyStop')
        ->assertNotified('紧急停止已解除');

    expect($emergencyStop->isActive())->toBeFalse();
});
