<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;

class Novels extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = '小说';

    protected static ?string $title = '小说';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('暂无小说')
                ->description('小说创建与工作台管理将在 TASK-010 中实现。')
                ->icon('heroicon-o-book-open'),
        ]);
    }
}
