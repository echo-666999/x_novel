<?php

namespace App\Filament\Resources\AIProviderConnections\Pages;

use App\Filament\Resources\AIProviderConnections\AIProviderConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAIProviderConnections extends ListRecords
{
    protected static string $resource = AIProviderConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('新建供应商连接')];
    }
}
