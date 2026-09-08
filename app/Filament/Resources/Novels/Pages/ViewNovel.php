<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Generation\GenerateNextChapterAction;
use App\Actions\Generation\PauseGenerationAction;
use App\Actions\Generation\SetAutoGenerationAction;
use App\Actions\Novels\EnterCompletingModeAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Exceptions\BudgetExceededException;
use App\Enums\NovelStatus;
use App\Exceptions\GenerationPreflightException;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use App\Services\ResumeResolver;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

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

        return $novel->status === NovelStatus::Completing
            ? "{$novel->genre} · COMPLETING · {$novel->status->getLabel()}"
            : "{$novel->genre} · {$novel->status->getLabel()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enterCompletingMode')
                ->label('进入收束期')
                ->icon('heroicon-o-flag')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->status === NovelStatus::Generating)
                ->requiresConfirmation()
                ->modalHeading('进入收束期')
                ->modalDescription('进入后 Chapter Planner 将限制新增核心人物、主线、硬世界规则和高重要度伏笔，并优先降低 Closure Debt。')
                ->action(function (EnterCompletingModeAction $enterCompletingMode): void {
                    $enterCompletingMode->handle($this->getRecord());
                    $this->getRecord()->refresh();
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title('已进入收束期')
                        ->body('Closing Restrictions Active')
                        ->warning()
                        ->send();
                }),
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
                ->visible(fn (): bool => $this->getRecord()->status !== NovelStatus::Paused)
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
            Action::make('startAutoGenerate')
                ->label('开始自动生成')
                ->icon('heroicon-o-bolt')
                ->visible(fn (): bool => $this->getRecord()->status !== NovelStatus::Paused
                    && ! (bool) data_get($this->getRecord()->settings, 'auto_generate', false))
                ->action(function (SetAutoGenerationAction $setAutoGeneration): void {
                    $setAutoGeneration->handle($this->getRecord(), true);
                    $this->getRecord()->refresh();
                    Notification::make()->title('自动生成已开启')->success()->send();
                }),
            Action::make('stopAutoGenerate')
                ->label('停止自动生成')
                ->icon('heroicon-o-stop')
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->status !== NovelStatus::Paused
                    && (bool) data_get($this->getRecord()->settings, 'auto_generate', false))
                ->action(function (SetAutoGenerationAction $setAutoGeneration): void {
                    $setAutoGeneration->handle($this->getRecord(), false);
                    $this->getRecord()->refresh();
                    Notification::make()->title('自动生成已停止')->success()->send();
                }),
            Action::make('pause')
                ->label('暂停')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->getRecord()->status, [NovelStatus::Generating, NovelStatus::Completing], true))
                ->requiresConfirmation()
                ->modalHeading('暂停生成')
                ->modalDescription('已发出的模型请求可以完成并保存草稿；系统不会派发新阶段，也不会提交正式章节。')
                ->action(function (PauseGenerationAction $pauseGeneration): void {
                    $pauseGeneration->handle($this->getRecord());
                    $this->getRecord()->refresh();
                    Notification::make()->title('小说生成已暂停')->warning()->send();
                }),
            Action::make('resume')
                ->label('继续')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->status === NovelStatus::Paused)
                ->requiresConfirmation()
                ->modalHeading('继续生成')
                ->modalDescription(fn (): string => '检测到的恢复点：'.app(ResumeResolver::class)->detect($this->getRecord())->label)
                ->action(function (ResumeResolver $resolver): void {
                    try {
                        $point = $resolver->resume($this->getRecord());
                    } catch (GenerationPreflightException|BudgetExceededException|ValidationException $exception) {
                        Notification::make()->title('无法继续生成')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    Notification::make()->title('生成流程已继续')->body('恢复点：'.$point->label)->success()->send();
                }),
            EditAction::make()
                ->label('编辑基础信息')
                ->color('gray'),
        ];
    }
}
