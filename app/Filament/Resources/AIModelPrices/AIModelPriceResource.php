<?php

namespace App\Filament\Resources\AIModelPrices;

use App\Filament\Resources\AIModelPrices\Pages\CreateAIModelPrice;
use App\Filament\Resources\AIModelPrices\Pages\EditAIModelPrice;
use App\Filament\Resources\AIModelPrices\Pages\ListAIModelPrices;
use App\Filament\Resources\AIModelPrices\Schemas\AIModelPriceForm;
use App\Filament\Resources\AIModelPrices\Tables\AIModelPricesTable;
use App\Models\AIModelPrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AIModelPriceResource extends Resource
{
    protected static ?string $model = AIModelPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = '模型价格';

    protected static ?string $pluralModelLabel = '模型价格';

    public static function getNavigationGroup(): ?string
    {
        return 'AI 与成本';
    }

    public static function form(Schema $schema): Schema
    {
        return AIModelPriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AIModelPricesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAIModelPrices::route('/'),
            'create' => CreateAIModelPrice::route('/create'),
            'edit' => EditAIModelPrice::route('/{record}/edit'),
        ];
    }
}
