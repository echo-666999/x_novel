<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

class EditNovel extends EditRecord
{
    protected static string $resource = NovelResource::class;

    private bool $autoCommitBeforeSave = false;

    public function getTitle(): string
    {
        return '小说工作台';
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['ai_model_overrides'] = data_get($data, 'settings.ai.models', []);
        $data['budget_limits'] = data_get($data, 'settings.budget', []);
        $data['generation_chapter_target_words'] = (int) data_get($data, 'settings.generation.chapter_target_words', 3_000);
        $data['workflow_auto_commit'] = data_get($data, 'settings.auto_commit_configured') === true
            && data_get($data, 'settings.auto_commit') === true;

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
        $this->autoCommitBeforeSave = data_get($settings, 'auto_commit_configured') === true
            && data_get($settings, 'auto_commit') === true;
        $settings['auto_commit'] = (bool) ($data['workflow_auto_commit'] ?? false);
        $settings['auto_commit_configured'] = true;
        $chapterTargetWords = (int) $data['generation_chapter_target_words'];
        if (array_key_exists('generation', $settings) || $chapterTargetWords !== 3_000) {
            $settings['generation']['chapter_target_words'] = $chapterTargetWords;
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

        $data['settings'] = $settings;
        unset($data['ai_model_overrides'], $data['budget_limits'], $data['generation_chapter_target_words'], $data['workflow_auto_commit']);

        return $data;
    }

    protected function afterSave(): void
    {
        $enabled = data_get($this->getRecord()->settings, 'auto_commit_configured') === true
            && data_get($this->getRecord()->settings, 'auto_commit') === true;

        if ($enabled === $this->autoCommitBeforeSave) {
            return;
        }

        Log::info('Novel automatic canonical commit setting changed.', [
            'novel_id' => $this->getRecord()->getKey(),
            'enabled' => $enabled,
            'actor_id' => auth()->id(),
        ]);
    }
}
