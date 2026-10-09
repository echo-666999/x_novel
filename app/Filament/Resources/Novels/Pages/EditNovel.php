<?php

namespace App\Filament\Resources\Novels\Pages;

use App\AI\AiModelRouteService;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Schemas\NovelForm;
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
        $data = NovelForm::prepareGenreForFill($data);
        // 表单状态使用价格 ID 或受控保留 Token，避免把 Provider/Model 明文拆成两个可漂移字段。
        $data['ai_model_overrides'] = app(AiModelRouteService::class)->novelOverrideFormState($this->getRecord());
        $data['budget_limits'] = data_get($data, 'settings.budget', []);
        $data['generation_chapter_target_words'] = (int) data_get($data, 'settings.generation.chapter_target_words', 3_000);

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = NovelForm::prepareGenreForPersistence($data);
        $settings = $this->getRecord()->settings ?? [];
        $chapterTargetWords = (int) $data['generation_chapter_target_words'];
        if (array_key_exists('generation', $settings) || $chapterTargetWords !== 3_000) {
            $settings['generation']['chapter_target_words'] = $chapterTargetWords;
        }
        // 合并时保留无关 settings；旧 Outline Model-only 值只有用户重选完整路由后才允许迁移。
        $settings = app(AiModelRouteService::class)->applyNovelOverrides(
            $settings,
            (array) ($data['ai_model_overrides'] ?? []),
            $this->getRecord(),
        );
        $budgetLimits = collect($data['budget_limits'] ?? [])
            ->filter(fn (mixed $limit): bool => filled($limit))
            ->map(fn (mixed $limit): float => (float) $limit)
            ->all();

        if ($budgetLimits !== [] || array_key_exists('budget', $settings)) {
            $settings['budget'] = $budgetLimits;
        }

        $data['settings'] = $settings;
        unset($data['ai_model_overrides'], $data['budget_limits'], $data['generation_chapter_target_words']);

        return $data;
    }
}
