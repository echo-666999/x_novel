<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DueForeshadowingsWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Widgets\StatsOverviewWidget\Stat;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = '仪表盘';

    protected static ?string $title = '仪表盘';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'md' => 2,
                'xl' => 4,
            ])->schema([
                Stat::make('活跃小说', 0)
                    ->description('暂无活跃小说')
                    ->descriptionIcon('heroicon-o-book-open')
                    ->color('gray'),
                Stat::make('当前章节', '—')
                    ->description('尚未选择章节')
                    ->descriptionIcon('heroicon-o-document-text')
                    ->color('gray'),
                Stat::make('今日成本', '¥0.00')
                    ->description('暂无用量记录')
                    ->descriptionIcon('heroicon-o-banknotes')
                    ->color('gray'),
                Stat::make('需要处理', 0)
                    ->description('暂无待处理事项')
                    ->descriptionIcon('heroicon-o-check-circle')
                    ->color('success'),
            ]),
            Grid::make([
                'default' => 1,
                'xl' => 2,
            ])->schema([
                Section::make('最近生成')
                    ->description('最近的章节生成活动')
                    ->schema([
                        EmptyState::make('暂无生成活动')
                            ->description('章节生成流程启动后，最近的生成记录会显示在这里。')
                            ->icon('heroicon-o-bolt')
                            ->contained(false),
                    ]),
                ...$this->getWidgetsSchemaComponents([
                    DueForeshadowingsWidget::class,
                ]),
            ]),
        ]);
    }
}
