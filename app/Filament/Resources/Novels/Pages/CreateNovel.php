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
            ],
            'editorial' => $this->editorialSettings($data),
        ];

        unset($data['generation_chapter_target_words']);
        $this->unsetEditorialFields($data);
        unset($data['ai_model_overrides'], $data['budget_limits']);

        return $data;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function editorialSettings(array $data): array
    {
        return [
            'subgenre' => filled($data['editorial_subgenre'] ?? null) ? trim((string) $data['editorial_subgenre']) : null,
            'target_platform' => $data['editorial_target_platform'],
            'story_tone' => $data['editorial_story_tone'],
            'primary_style' => $data['editorial_primary_style'],
            'secondary_styles' => array_values($data['editorial_secondary_styles'] ?? []),
            'language_era' => $data['editorial_language_era'],
            'pacing' => $data['editorial_pacing'],
            'narrative_pov' => $data['editorial_narrative_pov'],
            'style_parameters' => collect($data['editorial_style_parameters'] ?? [])->filter(fn (mixed $value): bool => filled($value))->map(fn (mixed $value): int => (int) $value)->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function unsetEditorialFields(array &$data): void
    {
        unset($data['editorial_subgenre'], $data['editorial_target_platform'], $data['editorial_story_tone'], $data['editorial_primary_style'], $data['editorial_secondary_styles'], $data['editorial_language_era'], $data['editorial_pacing'], $data['editorial_narrative_pov'], $data['editorial_style_parameters']);
    }

    protected function getRedirectUrl(): string
    {
        return NovelResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
