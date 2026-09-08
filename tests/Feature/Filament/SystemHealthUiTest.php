<?php

use App\Filament\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('settings displays the system health release checklist in chinese', function () {
    Livewire::test(Settings::class)
        ->assertOk()
        ->assertSee('系统健康')
        ->assertSee('System Health')
        ->assertSee('数据库备份')
        ->assertSee('备份恢复演练')
        ->assertSee('Queue 重启')
        ->assertSee('State / Memory 重建')
        ->assertSee('成本硬限额')
        ->assertSee('紧急停止')
        ->assertSee('重新检查');
});
