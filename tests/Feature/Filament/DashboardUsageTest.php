<?php

use App\Filament\Pages\Dashboard;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('ai.cost.currency', 'USD');
});

test('dashboard shows todays provider cost and token totals', function () {
    UsageRecord::factory()->create([
        'input_tokens' => 1_200,
        'output_tokens' => 800,
        'estimated_cost' => 0.012345,
        'created_at' => now(),
    ]);
    UsageRecord::factory()->create([
        'input_tokens' => 9_000,
        'output_tokens' => 1_000,
        'estimated_cost' => 9.999999,
        'created_at' => now()->subDay(),
    ]);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSeeTextInOrder([
            '今日成本',
            'USD 0.012345',
            '成功 Provider 请求',
            '今日 Tokens',
            '2,000',
            'Input + Output',
        ]);
});

test('dashboard keeps an explicit usage empty state', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('USD 0.000000')
        ->assertSee('今日 Tokens')
        ->assertSee('暂无用量记录');
});
