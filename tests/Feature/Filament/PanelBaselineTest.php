<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Generation;
use App\Filament\Pages\Memory;
use App\Filament\Pages\Review;
use App\Filament\Pages\Settings;
use App\Filament\Resources\AIModelPrices\AIModelPriceResource;
use App\Filament\Resources\AIProviderConnections\AIProviderConnectionResource;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the x panel exposes the workbench and the two ai and cost resources', function () {
    $panel = Filament::getPanel('x');

    expect($panel->getBrandName())->toBe(config('app.name'))
        ->and([
            Dashboard::getNavigationLabel(),
            NovelResource::getNavigationLabel(),
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
            NovelResource::getNavigationSort(),
            Generation::getNavigationSort(),
            Review::getNavigationSort(),
            Memory::getNavigationSort(),
            Settings::getNavigationSort(),
        ])->toBe([-2, 1, 2, 3, 4, 5])
        ->and($panel->getPages())->toContain(
            Dashboard::class,
            Generation::class,
            Review::class,
            Memory::class,
            Settings::class,
        )->and($panel->getResources())->toContain(
            NovelResource::class,
            AIProviderConnectionResource::class,
            AIModelPriceResource::class,
        )->and(AIProviderConnectionResource::getNavigationGroup())->toBe('AI 与成本')
        ->and(AIModelPriceResource::getNavigationGroup())->toBe('AI 与成本');
});

test('filament interface strings added after the bundled chinese translation remain localized', function () {
    expect(trans_choice('filament-tables::table.result_count', 0, ['count' => 0]))->toBe('暂无结果')
        ->and(__('filament-panels::layout.skip_to_content.label'))->toBe('跳转到内容')
        ->and(__('filament-panels::layout.navigation.label'))->toBe('侧边导航')
        ->and(__('filament-panels::layout.topbar.label'))->toBe('顶部栏')
        ->and(__('filament-panels::layout.actions.theme_switcher.label'))->toBe('主题')
        ->and(__('filament::components/breadcrumbs.label'))->toBe('面包屑导航')
        ->and(__('filament-forms::components.select.actions.clear.label'))->toBe('清除选择');
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
    '/x/a-i-provider-connections',
    '/x/a-i-model-prices',
]);

test('the owner can access every workbench page', function (string $path, string $emptyState) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertOk()
        ->assertSee($emptyState);
})->with([
    ['/x/novels', '创建第一部小说'],
    ['/x/generation', '暂无生成记录'],
    ['/x/review', '审校收件箱为空'],
    ['/x/memory', '暂无已索引记忆'],
    ['/x/settings', '系统健康'],
    ['/x/a-i-provider-connections', '暂无服务商连接'],
    ['/x/a-i-model-prices', '暂无模型价格'],
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
            '运行中章节',
            '今日成本',
            '已失败',
            '已阻塞',
            '需要关注',
            '最近生成',
            '待处理伏笔',
        ])
        ->assertSee('生成中、已暂停或收束中的小说')
        ->assertSee('排队中 / 运行中的不同章节')
        ->assertSee('暂无用量记录')
        ->assertSee('暂无生成活动')
        ->assertSee('暂无待处理伏笔')
        ->assertDontSee('诊断与验收');
});

test('the settings page keeps the remaining operational configuration sections', function () {
    $this->actingAs(User::factory()->create())
        ->get('/x/settings')
        ->assertOk()
        ->assertSeeTextInOrder([
            '生成',
            '审校',
            '记忆',
            '预算',
        ])
        ->assertSee('生成参数')
        ->assertSee('数据库配置')
        ->assertSee('PostgreSQL · system_settings')
        ->assertDontSee('应用默认值（保存后写入 PostgreSQL）')
        ->assertSee('只读');
});

test('the generation settings dialog localizes user facing english except token', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(Settings::class)
        ->assertActionExists(
            'configureGenerationSettings',
            fn ($action): bool => $action->getModalDescription() === '保存后只影响新建或重新开始的任务；继续执行的任务与已冻结的生成记录仍使用原值。',
        )
        ->mountAction('configureGenerationSettings');

    $schemaName = $component->instance()->getMountedActionSchemaName();
    $dialogText = html_entity_decode(strip_tags((string) $component->instance()->{$schemaName}->toHtmlString()));

    expect($dialogText)
        ->toContain(
            '全书大纲',
            '基础设定',
            '整体结构',
            '故事线节点',
            '节点细化',
            '计划覆盖证据修复',
            '伏笔覆盖修复',
            '计划覆盖判定',
            '响应结构修复',
            '故事线完成度修复',
            '嵌入向量维度',
            '可见输出 Token',
            '推理预留 Token',
        )
        ->not->toContain(
            'Restart',
            'Resume',
            'Foundation',
            'Structure',
            'Arc Beats',
            'Beat Detail',
            'Coverage Judgment',
            'Schema 修复',
            'Arc Completion',
            'Embedding',
        );
});
