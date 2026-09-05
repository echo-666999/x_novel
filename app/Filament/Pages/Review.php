<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;

class Review extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = '审校';

    protected static ?string $title = '审校';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('审校收件箱为空')
                ->description('需要处理或已阻塞的章节会显示在这里。')
                ->icon('heroicon-o-inbox'),
        ]);
    }
}
