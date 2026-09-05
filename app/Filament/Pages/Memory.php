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

    protected static ?string $title = 'Memory';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('No memories indexed')
                ->description('Canonical memories and retrieval diagnostics will appear here after memory processing is implemented.')
                ->icon('heroicon-o-circle-stack'),
        ]);
    }
}
