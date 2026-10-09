<?php

namespace App\Filament\Resources\Novels\Pages;

use App\AI\AiModelRouteService;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Schemas\NovelForm;
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
        $data = NovelForm::prepareGenreForPersistence($data);
        $settings = [
            'generation' => [
                'chapter_target_words' => (int) $data['generation_chapter_target_words'],
            ],
            // 新小说默认使用“当前章生成到审校”，正式启动时再由三种运行模式覆盖。
            'auto_generate' => false,
            'auto_commit' => false,
            'auto_commit_configured' => true,
        ];
        // Select 提交的是价格记录 ID，必须在创建 Novel 前转换并持久化完整的 Provider + Model 路由。
        $data['settings'] = app(AiModelRouteService::class)->applyNovelOverrides(
            $settings,
            (array) ($data['ai_model_overrides'] ?? []),
        );

        unset($data['generation_chapter_target_words']);
        unset($data['ai_model_overrides'], $data['budget_limits']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return NovelResource::getUrl('outline', ['record' => $this->getRecord()]);
    }
}
