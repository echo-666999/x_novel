<?php

namespace App\Filament\Resources\AIProviderConnections;

use App\Filament\Resources\AIProviderConnections\Pages\CreateAIProviderConnection;
use App\Filament\Resources\AIProviderConnections\Pages\EditAIProviderConnection;
use App\Filament\Resources\AIProviderConnections\Pages\ListAIProviderConnections;
use App\Filament\Resources\AIProviderConnections\Schemas\AIProviderConnectionForm;
use App\Filament\Resources\AIProviderConnections\Tables\AIProviderConnectionsTable;
use App\Models\AIProviderConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AIProviderConnectionResource extends Resource
{
    protected static ?string $model = AIProviderConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = '供应商连接';

    protected static ?string $pluralModelLabel = '供应商连接';

    public static function getNavigationGroup(): ?string
    {
        return 'AI 与成本';
    }

    public static function form(Schema $schema): Schema
    {
        return AIProviderConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AIProviderConnectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAIProviderConnections::route('/'),
            'create' => CreateAIProviderConnection::route('/create'),
            'edit' => EditAIProviderConnection::route('/{record}/edit'),
        ];
    }
}
