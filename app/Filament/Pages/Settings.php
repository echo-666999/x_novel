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

    protected static ?string $title = 'Settings';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Configuration sources are shown for reference. Editing will be enabled by the tasks that own each setting.')
                ->color('gray'),
            Grid::make([
                'default' => 1,
                'xl' => 2,
            ])->schema([
                $this->placeholderSection(
                    heading: 'AI',
                    description: 'Provider credentials, model selection, and connection health.',
                    source: 'Source: .env and config/services.php',
                    icon: 'heroicon-o-cpu-chip',
                ),
                $this->placeholderSection(
                    heading: 'Generation',
                    description: 'Generation defaults, retry behavior, and chapter workflow policy.',
                    source: 'Source: config and Novel settings',
                    icon: 'heroicon-o-bolt',
                ),
                $this->placeholderSection(
                    heading: 'Review',
                    description: 'Review thresholds, decisions, and rewrite attempt limits.',
                    source: 'Source: config and Novel settings',
                    icon: 'heroicon-o-clipboard-document-check',
                ),
                $this->placeholderSection(
                    heading: 'Memory',
                    description: 'Retrieval limits, context budget, and embedding policy.',
                    source: 'Source: config and Novel settings',
                    icon: 'heroicon-o-circle-stack',
                ),
                $this->placeholderSection(
                    heading: 'Budget',
                    description: 'Daily, novel, chapter, and rewrite cost limits.',
                    source: 'Source: config and Novel settings',
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
                Text::make('Read only')
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
