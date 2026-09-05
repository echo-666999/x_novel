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

    protected static ?string $title = 'Review';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('Review inbox is empty')
                ->description('Chapters that need attention or are blocked will appear here.')
                ->icon('heroicon-o-inbox'),
        ]);
    }
}
