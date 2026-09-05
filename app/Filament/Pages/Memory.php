<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;

class Memory extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = '记忆';

    protected static ?string $title = '记忆';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('暂无已索引记忆')
                ->description('记忆处理实现后，正式记忆与检索诊断会显示在这里。')
                ->icon('heroicon-o-circle-stack'),
        ]);
    }
}
