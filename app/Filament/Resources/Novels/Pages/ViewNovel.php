<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Generation\AdvanceChapterPipelineAction;
use App\Actions\Generation\GenerateNextChapterAction;
use App\Actions\Generation\PauseGenerationAction;
use App\Actions\Generation\SetAutoGenerationAction;
use App\Actions\Novels\EnterCompletingModeAction;
use App\Actions\Novels\StartNovelGenerationAction;
use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Exceptions\BudgetExceededException;
use App\Enums\NovelStatus;
use App\Exceptions\GenerationPreflightException;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use App\Services\NovelGenerationReadiness;
use App\Services\ResumeResolver;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewNovel extends ViewRecord
{
    /** 三种运行模式直接映射现有 auto_generate 与 auto_commit，不增加新的状态来源。 */
    private const AUTOMATION_REVIEW_ONLY = 'review_only';

    private const AUTOMATION_CONTINUOUS_MANUAL = 'continuous_manual';

    private const AUTOMATION_FULL = 'full_auto';

    /** @var array<int, array{key: string, label: string, ready: bool, repair_hint: string}>|null */
    private ?array $generationReadiness = null;

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
            Action::make('startNovelGeneration')
                ->label('开始正文生成')
                ->icon('heroicon-o-rocket-launch')
                ->color('primary')
                ->visible(fn (): bool => in_array($this->getRecord()->status, [NovelStatus::Draft, NovelStatus::Planning], true))
                ->disabled(fn (): bool => ! app(NovelGenerationReadiness::class)->allReady($this->generationReadiness()))
                ->tooltip(fn (): ?string => app(NovelGenerationReadiness::class)->allReady($this->generationReadiness())
                    ? null
                    : app(NovelGenerationReadiness::class)->missingMessageFor($this->generationReadiness()))
                ->requiresConfirmation()
                ->modalHeading('开始正文生成')
                ->modalDescription('系统会重新检查 Current Outline、小说圣经、主角、世界设定、当前分卷、故事线、初始故事状态和上一章派生数据。')
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
                ->label('恢复：初始化故事状态')
                ->icon('heroicon-o-circle-stack')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->canonical_state_version_id === null
                    && in_array($this->getRecord()->status, [NovelStatus::Draft, NovelStatus::Planning], true)
                    && $this->getRecord()->currentBible()->exists())
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
            Action::make('startAutoGenerate')
                ->label('启动章节生成')
                ->icon('heroicon-o-bolt')
                ->visible(fn (): bool => in_array($this->getRecord()->status, [NovelStatus::Generating, NovelStatus::Completing], true)
                    && ! (bool) data_get($this->getRecord()->settings, 'auto_generate', false))
                ->modalHeading('选择章节生成模式')
                ->modalDescription('三种模式共用同一条安全流水线；Review 与 Canonical Commit 门禁不会被绕过。')
                ->fillForm(fn (): array => [
                    // 弹窗默认值必须反映页面显示的当前模式，不能用遗留 auto_commit 猜测用户意图。
                    'automation_mode' => self::AUTOMATION_REVIEW_ONLY,
                ])
                ->schema([
                    Select::make('automation_mode')
                        ->label('运行模式')
                        ->options([
                            self::AUTOMATION_REVIEW_ONLY => '当前章生成到审校 · PASS 后等待人工提交',
                            self::AUTOMATION_CONTINUOUS_MANUAL => '连续生成 · 每章 PASS 后人工确认提交',
                            self::AUTOMATION_FULL => '全自动连续生成 · PASS 后自动提交并进入下一章',
                        ])
                        ->native(false)
                        ->required(),
                ])
                ->action(function (
                    array $data,
                    GenerateNextChapterAction $generateNextChapter,
                    SetAutoGenerationAction $setAutoGeneration,
                    AdvanceChapterPipelineAction $advanceChapterPipeline,
                ): void {
                    // 模式只决定 PASS 后是否提交和提交后是否续章，其余阶段仍由统一推进器选择。
                    [$autoGenerate, $autoCommit, $modeLabel] = match ($data['automation_mode'] ?? null) {
                        self::AUTOMATION_REVIEW_ONLY => [false, false, '当前章生成到审校'],
                        self::AUTOMATION_CONTINUOUS_MANUAL => [true, false, '连续生成 · 逐章确认'],
                        self::AUTOMATION_FULL => [true, true, '全自动连续生成'],
                        default => throw ValidationException::withMessages(['automation_mode' => '请选择有效的章节生成模式。']),
                    };

                    try {
                        $chapter = $generateNextChapter->handle($this->getRecord());
                    } catch (GenerationPreflightException|BudgetExceededException|ValidationException $exception) {
                        Notification::make()
                            ->title('无法启动章节生成')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $setAutoGeneration->handle($this->getRecord(), $autoGenerate, $autoCommit);
                    $this->getRecord()->refresh();
                    try {
                        $stage = $advanceChapterPipeline->handle($chapter->getKey());
                    } catch (GenerationPreflightException|BudgetExceededException|ValidationException $exception) {
                        Notification::make()
                            ->title('运行模式已保存，但章节无法启动')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title($stage === null ? "{$modeLabel}已设置，当前章等待后续操作" : "{$modeLabel}已启动")
                        ->body($stage === null
                            ? "第 {$chapter->sequence} 章当前没有可自动执行的阶段。"
                            : "第 {$chapter->sequence} 章当前阶段：{$stage->getLabel()}。")
                        ->success()
                        ->send();

                    $this->redirect(NovelResource::getUrl('chapter', [
                        'record' => $this->getRecord(),
                        'chapter' => $chapter,
                    ]));
                }),
            Action::make('stopAutoGenerate')
                ->label('停止自动生成')
                ->icon('heroicon-o-stop')
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->status !== NovelStatus::Paused
                    && (bool) data_get($this->getRecord()->settings, 'auto_generate', false))
                ->action(function (SetAutoGenerationAction $setAutoGeneration): void {
                    // 手工停止回到“当前章生成到审校”，避免残留 auto_commit 形成未展示的第四种组合。
                    $setAutoGeneration->handle($this->getRecord(), false, false);
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
                    Notification::make()
                        ->title($point->key === 'awaiting_commit' ? '已恢复，等待提交正式章节' : '生成流程已继续')
                        ->body('恢复点：'.$point->label)
                        ->success()
                        ->send();
                }),
            EditAction::make()
                ->label('编辑基础信息')
                ->color('gray'),
        ];
    }

    /** @return array<int, array{key: string, label: string, ready: bool, repair_hint: string}> */
    private function generationReadiness(): array
    {
        return $this->generationReadiness ??= app(NovelGenerationReadiness::class)->evaluate($this->getRecord());
    }
}
