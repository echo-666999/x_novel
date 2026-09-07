<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Generation\GenerateNextChapterAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Exceptions\BudgetExceededException;
use App\Exceptions\GenerationPreflightException;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewNovel extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = '概览';

    public function getTitle(): string
    {
        return $this->getRecord()->title;
    }

    public function getSubheading(): ?string
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();

        return "{$novel->genre} · {$novel->status->getLabel()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('initializeStoryState')
                ->label('初始化故事状态')
                ->icon('heroicon-o-circle-stack')
                ->visible(fn (): bool => $this->getRecord()->canonical_state_version_id === null)
                ->action(function (InitializeNovelStateAction $initializeNovelState): void {
                    $stateVersion = $initializeNovelState->handle($this->getRecord());

                    $this->getRecord()->refresh();
                    $this->refreshFormData(['canonical_state_version_id']);

                    Notification::make()
                        ->title('故事状态已初始化')
                        ->body('当前版本: '.$stateVersion->version)
                        ->success()
                        ->send();
                }),
            Action::make('generateNextChapter')
                ->label('生成下一章')
                ->icon('heroicon-o-play')
                ->action(function (GenerateNextChapterAction $generateNextChapter): void {
                    try {
                        $chapter = $generateNextChapter->handle($this->getRecord());
                    } catch (GenerationPreflightException|BudgetExceededException $exception) {
                        Notification::make()
                            ->title('无法生成下一章')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('章节工作流已就绪')
                        ->body("第 {$chapter->sequence} 章已创建或恢复。")
                        ->success()
                        ->send();

                    $this->redirect(NovelResource::getUrl('chapter', [
                        'record' => $this->getRecord(),
                        'chapter' => $chapter,
                    ]));
                }),
            Action::make('autoGenerate')
                ->label('自动生成')
                ->icon('heroicon-o-bolt')
                ->color('gray')
                ->disabled()
                ->tooltip('将在 TASK-100 中启用'),
            Action::make('pause')
                ->label('暂停')
                ->icon('heroicon-o-pause')
                ->color('gray')
                ->disabled()
                ->tooltip('将在 TASK-101 中启用'),
            Action::make('resume')
                ->label('继续')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->disabled()
                ->tooltip('将在 TASK-102 中启用'),
            EditAction::make()
                ->label('编辑基础信息')
                ->color('gray'),
        ];
    }
}
