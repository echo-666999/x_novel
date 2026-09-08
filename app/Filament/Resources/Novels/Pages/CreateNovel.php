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
        $data['settings'] = [
            'generation' => [
                'chapter_target_words' => (int) $data['generation_chapter_target_words'],
                'narrative_style' => trim((string) $data['generation_narrative_style']),
            ],
        ];

        unset($data['generation_chapter_target_words'], $data['generation_narrative_style']);
        unset($data['ai_model_overrides'], $data['budget_limits']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return NovelResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
