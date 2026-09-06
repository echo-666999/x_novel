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

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['ai_model_overrides']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return NovelResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
