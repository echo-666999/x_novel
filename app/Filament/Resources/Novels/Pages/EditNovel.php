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
        $data['budget_limits'] = data_get($data, 'settings.budget', []);
        $data['auto_commit'] = (bool) data_get($data, 'settings.auto_commit', false);
        $data['generation_chapter_target_words'] = (int) data_get($data, 'settings.generation.chapter_target_words', 3_000);
        $editorial = data_get($data, 'settings.editorial', []);
        $data['editorial_subgenre'] = data_get($editorial, 'subgenre');
        $data['editorial_target_platform'] = data_get($editorial, 'target_platform', 'general');
        $data['editorial_story_tone'] = data_get($editorial, 'story_tone', 'serious');
        $data['editorial_primary_style'] = data_get($editorial, 'primary_style', 'accessible_brisk');
        $data['editorial_secondary_styles'] = data_get($editorial, 'secondary_styles', []);
        $data['editorial_language_era'] = data_get($editorial, 'language_era', 'modern_spoken');
        $data['editorial_pacing'] = data_get($editorial, 'pacing', 'balanced');
        $data['editorial_narrative_pov'] = data_get($editorial, 'narrative_pov', 'third_limited');
        $data['editorial_style_parameters'] = data_get($editorial, 'style_parameters', []);

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
        $chapterTargetWords = (int) $data['generation_chapter_target_words'];
        if (array_key_exists('generation', $settings) || $chapterTargetWords !== 3_000) {
            $settings['generation']['chapter_target_words'] = $chapterTargetWords;
        }
        $editorial = $this->editorialSettings($data);
        if (array_key_exists('editorial', $settings) || $editorial !== $this->editorialDefaults()) {
            $settings['editorial'] = $editorial;
        }
        $settings['ai'] ??= [];
        $settings['ai']['models'] = $overrides;
        $budgetLimits = collect($data['budget_limits'] ?? [])
            ->filter(fn (mixed $limit): bool => filled($limit))
            ->map(fn (mixed $limit): float => (float) $limit)
            ->all();

        if ($budgetLimits !== [] || array_key_exists('budget', $settings)) {
            $settings['budget'] = $budgetLimits;
        }

        $autoCommit = (bool) ($data['auto_commit'] ?? false);
        if ($autoCommit || array_key_exists('auto_commit', $settings)) {
            $settings['auto_commit'] = $autoCommit;
        }

        $data['settings'] = $settings;
        unset($data['ai_model_overrides'], $data['budget_limits'], $data['auto_commit'], $data['generation_chapter_target_words']);
        $this->unsetEditorialFields($data);

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

    /** @return array<string, mixed> */
    private function editorialDefaults(): array
    {
        return ['subgenre' => null, 'target_platform' => 'general', 'story_tone' => 'serious', 'primary_style' => 'accessible_brisk', 'secondary_styles' => [], 'language_era' => 'modern_spoken', 'pacing' => 'balanced', 'narrative_pov' => 'third_limited', 'style_parameters' => []];
    }

    /** @param array<string, mixed> $data */
    private function unsetEditorialFields(array &$data): void
    {
        unset($data['editorial_subgenre'], $data['editorial_target_platform'], $data['editorial_story_tone'], $data['editorial_primary_style'], $data['editorial_secondary_styles'], $data['editorial_language_era'], $data['editorial_pacing'], $data['editorial_narrative_pov'], $data['editorial_style_parameters']);
    }
}
