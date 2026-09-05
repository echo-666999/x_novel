<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;

class Generation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = '生成';

    protected static ?string $title = '生成';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('暂无生成记录')
                ->description('生成流程实现后，运行记录与流水线控制会显示在这里。')
                ->icon('heroicon-o-bolt'),
        ]);
    }
}
