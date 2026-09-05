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

    expect($panel->getBrandName())->toBe(config('app.name'))
        ->and([
            Dashboard::getNavigationLabel(),
            Novels::getNavigationLabel(),
            Generation::getNavigationLabel(),
            Review::getNavigationLabel(),
            Memory::getNavigationLabel(),
            Settings::getNavigationLabel(),
        ])->toBe([
            '仪表盘',
            '小说',
            '生成',
            '审校',
            '记忆',
            '设置',
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
    ['/x/novels', '暂无小说'],
    ['/x/generation', '暂无生成记录'],
    ['/x/review', '审校收件箱为空'],
    ['/x/memory', '暂无已索引记忆'],
    ['/x/settings', '此处仅展示配置来源。'],
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
            '活跃小说',
            '当前章节',
            '今日成本',
            '需要处理',
            '最近生成',
            '待处理伏笔',
        ])
        ->assertSee('暂无活跃小说')
        ->assertSee('尚未选择章节')
        ->assertSee('暂无用量记录')
        ->assertSee('暂无待处理事项')
        ->assertSee('暂无生成活动')
        ->assertSee('暂无待处理伏笔');
});

test('the settings page provides the five read only configuration sections', function () {
    $this->actingAs(User::factory()->create())
        ->get('/x/settings')
        ->assertOk()
        ->assertSeeTextInOrder([
            'AI',
            '生成',
            '审校',
            '记忆',
            '预算',
        ])
        ->assertSee('来源：.env 与 config/services.php')
        ->assertSee('来源：config 与小说设置')
        ->assertSee('只读');
});
