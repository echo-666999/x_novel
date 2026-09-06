<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use Filament\Resources\Pages\EditRecord;

class EditNovel extends EditRecord
{
    protected static string $resource = NovelResource::class;

    public function getTitle(): string
    {
        return '小说工作台';
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['ai_model_overrides'] = data_get($data, 'settings.ai.models', []);

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $overrides = collect($data['ai_model_overrides'] ?? [])
            ->map(fn (mixed $model): string => is_string($model) ? trim($model) : '')
            ->filter()
            ->all();
        $settings = $this->getRecord()->settings ?? [];
        $settings['ai'] ??= [];
        $settings['ai']['models'] = $overrides;

        $data['settings'] = $settings;
        unset($data['ai_model_overrides']);

        return $data;
    }
}
