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

    protected static ?string $title = 'Generation';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('No generation runs yet')
                ->description('Generation runs and pipeline controls will appear here when the workflow is implemented.')
                ->icon('heroicon-o-bolt'),
        ]);
    }
}
