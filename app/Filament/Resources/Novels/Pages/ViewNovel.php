<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Generation\GenerateNextChapterAction;
use App\Actions\Generation\PauseGenerationAction;
use App\Actions\Generation\SetAutoGenerationAction;
use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\EnterCompletingModeAction;
use App\Actions\Novels\StartNovelGenerationAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Exceptions\BudgetExceededException;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Exceptions\GenerationPreflightException;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use App\Services\NovelPlanner;
use App\Services\ResumeResolver;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
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
            Action::make('generateNovelBlueprint')
                ->label(fn (): string => $this->latestBlueprintArtifact() === null ? 'AI 生成小说规划' : '重新生成规划建议')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->visible(fn (): bool => in_array($this->getRecord()->status, [NovelStatus::Draft, NovelStatus::Planning], true)
                    && ! $this->hasPlanningData())
                ->modalHeading('AI 生成小说规划')
                ->modalDescription('根据小说基础信息生成小说圣经、人物、世界设定、分卷、故事线和伏笔。生成结果会先保存为候选方案。')
                ->schema([
                    TextInput::make('volume_count')
                        ->label('预计分卷数')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(12)
                        ->default(5)
                        ->required(),
                ])
                ->modalSubmitActionLabel('开始生成')
                ->action(function (array $data, NovelPlanner $planner): void {
                    try {
                        $planner->generate($this->getRecord(), (int) $data['volume_count']);
                    } catch (\Throwable $exception) {
                        Notification::make()->title('小说规划生成失败')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    $this->refreshFormData(['status']);
                    Notification::make()->title('小说规划候选方案已生成')->body('请预览并确认采用。')->success()->send();
                }),
            Action::make('previewNovelBlueprint')
                ->label('预览 AI 规划')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (): bool => $this->latestBlueprintArtifact() !== null && ! $this->hasPlanningData())
                ->modalHeading('AI 小说规划预览')
                ->modalDescription('采用后将写入现有小说规划页面；采用前不会修改小说圣经、人物、世界或故事结构。')
                ->modalWidth('5xl')
                ->modalSubmitAction(false)
                ->infolist([
                    Section::make('核心方案')->schema([
                        TextEntry::make('blueprint_logline')->label('一句话梗概')->state(fn (): ?string => data_get($this->latestBlueprintArtifact()?->data, 'bible.logline')),
                        TextEntry::make('blueprint_themes')->label('主题')->state(fn (): array => data_get($this->latestBlueprintArtifact()?->data, 'bible.themes', []))->bulleted(),
                    ]),
                    Section::make('规划结构')->columns(3)->schema([
                        TextEntry::make('blueprint_characters')->label('人物')->state(fn (): array => collect(data_get($this->latestBlueprintArtifact()?->data, 'characters', []))->pluck('name')->all())->bulleted(),
                        TextEntry::make('blueprint_volumes')->label('分卷')->state(fn (): array => collect(data_get($this->latestBlueprintArtifact()?->data, 'volumes', []))->pluck('title')->all())->bulleted(),
                        TextEntry::make('blueprint_arcs')->label('故事线')->state(fn (): array => collect(data_get($this->latestBlueprintArtifact()?->data, 'story_arcs', []))->pluck('title')->all())->bulleted(),
                        TextEntry::make('blueprint_world')->label('世界设定')->state(fn (): array => collect(data_get($this->latestBlueprintArtifact()?->data, 'world_entities', []))->pluck('name')->all())->bulleted(),
                        TextEntry::make('blueprint_foreshadowings')->label('伏笔')->state(fn (): array => collect(data_get($this->latestBlueprintArtifact()?->data, 'foreshadowings', []))->pluck('title')->all())->bulleted(),
                    ]),
                ]),
            Action::make('applyNovelBlueprint')
                ->label('采用 AI 规划')
                ->icon('heroicon-o-check-circle')
                ->visible(fn (): bool => $this->latestBlueprintArtifact() !== null && ! $this->hasPlanningData())
                ->requiresConfirmation()
                ->modalHeading('采用 AI 小说规划')
                ->modalDescription('将一次性创建小说圣经、人物、世界设定、分卷、故事线、伏笔和初始故事状态。')
                ->action(function (ApplyNovelBlueprintAction $apply): void {
                    try {
                        $apply->handle($this->getRecord(), $this->latestBlueprintArtifact());
                    } catch (ValidationException $exception) {
                        Notification::make()->title('无法采用规划')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    $this->refreshFormData(['status', 'canonical_state_version_id']);
                    Notification::make()->title('AI 小说规划已采用')->body('请检查规划内容，确认后开始正文生成。')->success()->send();
                }),
            Action::make('startNovelGeneration')
                ->label('开始正文生成')
                ->icon('heroicon-o-rocket-launch')
                ->color('primary')
                ->visible(fn (): bool => in_array($this->getRecord()->status, [NovelStatus::Draft, NovelStatus::Planning], true)
                    && $this->hasPlanningData())
                ->requiresConfirmation()
                ->modalHeading('开始正文生成')
                ->modalDescription('系统会重新检查小说圣经、人物、世界、当前分卷、故事线和初始故事状态。')
                ->action(function (StartNovelGenerationAction $start): void {
                    try {
                        $start->handle($this->getRecord());
                    } catch (ValidationException $exception) {
                        Notification::make()->title('规划尚未就绪')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    $this->refreshFormData(['status']);
                    Notification::make()->title('小说已进入生成阶段')->success()->send();
                }),
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
                ->visible(fn (): bool => $this->getRecord()->canonical_state_version_id === null && $this->hasPlanningData())
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
                ->visible(fn (): bool => in_array($this->getRecord()->status, [NovelStatus::Generating, NovelStatus::Completing], true))
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
                ->visible(fn (): bool => in_array($this->getRecord()->status, [NovelStatus::Generating, NovelStatus::Completing], true)
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

    private function hasPlanningData(): bool
    {
        return $this->getRecord()->bibles()->exists()
            || $this->getRecord()->volumes()->exists()
            || $this->getRecord()->storyArcs()->exists()
            || $this->getRecord()->characters()->exists()
            || $this->getRecord()->worldEntities()->exists()
            || $this->getRecord()->foreshadowings()->exists();
    }

    private function latestBlueprintArtifact(): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('novel_id', $this->getRecord()->getKey())
                ->whereNull('chapter_id')
                ->where('scope_type', 'novel')
                ->where('stage', GenerationStage::ChapterPlanning)
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->first();
    }
}
