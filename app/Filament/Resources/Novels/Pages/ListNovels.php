<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNovels extends ListRecords
{
    protected static string $resource = NovelResource::class;

    public function getTitle(): string
    {
        return '小说';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('创建小说'),
        ];
    }
}
