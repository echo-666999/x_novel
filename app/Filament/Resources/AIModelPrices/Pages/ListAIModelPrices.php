<?php

namespace App\Filament\Resources\AIModelPrices\Pages;

use App\Filament\Resources\AIModelPrices\AIModelPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAIModelPrices extends ListRecords
{
    protected static string $resource = AIModelPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('新建模型价格')];
    }
}
