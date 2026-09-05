<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNovel extends CreateRecord
{
    protected static string $resource = NovelResource::class;

    public function getTitle(): string
    {
        return '创建小说';
    }

    protected function getRedirectUrl(): string
    {
        return NovelResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
