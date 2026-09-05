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

    protected static ?string $title = 'Novels';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('No novels yet')
                ->description('Novel creation and workspace management will be added in TASK-010.')
                ->icon('heroicon-o-book-open'),
        ]);
    }
}
