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
        $data['generation_narrative_style'] = (string) data_get(
            $data,
            'settings.generation.narrative_style',
            $this->getRecord()->currentBible?->tone ?? '叙事清晰自然，语言风格保持统一。',
        );

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
        $generation = [
            'chapter_target_words' => (int) $data['generation_chapter_target_words'],
            'narrative_style' => trim((string) $data['generation_narrative_style']),
        ];
        $generationDefaults = [
            'chapter_target_words' => 3_000,
            'narrative_style' => $this->getRecord()->currentBible?->tone ?? '叙事清晰自然，语言风格保持统一。',
        ];
        if (array_key_exists('generation', $settings) || $generation !== $generationDefaults) {
            $settings['generation'] = $generation;
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
        unset($data['ai_model_overrides'], $data['budget_limits'], $data['auto_commit'], $data['generation_chapter_target_words'], $data['generation_narrative_style']);

        return $data;
    }
}
