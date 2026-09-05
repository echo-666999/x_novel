<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Widgets\StatsOverviewWidget\Stat;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'md' => 2,
                'xl' => 4,
            ])->schema([
                Stat::make('Active Novels', 0)
                    ->description('No active novel')
                    ->descriptionIcon('heroicon-o-book-open')
                    ->color('gray'),
                Stat::make('Current Chapter', '—')
                    ->description('No chapter selected')
                    ->descriptionIcon('heroicon-o-document-text')
                    ->color('gray'),
                Stat::make("Today's Cost", '¥0.00')
                    ->description('No usage recorded')
                    ->descriptionIcon('heroicon-o-banknotes')
                    ->color('gray'),
                Stat::make('Needs Attention', 0)
                    ->description('Nothing requires action')
                    ->descriptionIcon('heroicon-o-check-circle')
                    ->color('success'),
            ]),
            Grid::make([
                'default' => 1,
                'xl' => 2,
            ])->schema([
                Section::make('Recent Generation')
                    ->description('Latest chapter generation activity')
                    ->schema([
                        EmptyState::make('No generation activity')
                            ->description('Recent generation runs will appear here after a chapter workflow starts.')
                            ->icon('heroicon-o-bolt')
                            ->contained(false),
                    ]),
                Section::make('Due Foreshadowing')
                    ->description('Foreshadowing that needs attention soon')
                    ->schema([
                        EmptyState::make('No foreshadowing due')
                            ->description('Due and overdue foreshadowing will appear here when novel data is available.')
                            ->icon('heroicon-o-flag')
                            ->contained(false),
                    ]),
            ]),
        ]);
    }
}
