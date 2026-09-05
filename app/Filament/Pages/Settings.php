<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = '设置';

    protected static ?string $title = '设置';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('此处仅展示配置来源。各设置项将由对应任务开放编辑。')
                ->color('gray'),
            Grid::make([
                'default' => 1,
                'xl' => 2,
            ])->schema([
                $this->placeholderSection(
                    heading: 'AI',
                    description: '模型提供商凭据、模型选择与连接状态。',
                    source: '来源：.env 与 config/services.php',
                    icon: 'heroicon-o-cpu-chip',
                ),
                $this->placeholderSection(
                    heading: '生成',
                    description: '生成默认值、重试行为与章节工作流策略。',
                    source: '来源：config 与小说设置',
                    icon: 'heroicon-o-bolt',
                ),
                $this->placeholderSection(
                    heading: '审校',
                    description: '审校阈值、决策与重写次数限制。',
                    source: '来源：config 与小说设置',
                    icon: 'heroicon-o-clipboard-document-check',
                ),
                $this->placeholderSection(
                    heading: '记忆',
                    description: '检索数量、上下文预算与嵌入策略。',
                    source: '来源：config 与小说设置',
                    icon: 'heroicon-o-circle-stack',
                ),
                $this->placeholderSection(
                    heading: '预算',
                    description: '每日、单本小说、单章与重写成本限制。',
                    source: '来源：config 与小说设置',
                    icon: 'heroicon-o-banknotes',
                )->columnSpanFull(),
            ]),
        ]);
    }

    private function placeholderSection(
        string $heading,
        string $description,
        string $source,
        string $icon,
    ): Section {
        return Section::make($heading)
            ->description($description)
            ->icon($icon)
            ->afterHeader([
                Text::make('只读')
                    ->badge()
                    ->color('gray'),
            ])
            ->schema([
                Text::make($source)
                    ->icon('heroicon-o-information-circle')
                    ->color('gray'),
            ]);
    }
}
