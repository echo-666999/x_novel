<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Services\PlanValidator;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ViewNovelChapter extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = 'Chapter Detail';

    public int $chapterId;

    protected ?Chapter $cachedChapter = null;

    public function mount(int|string $record, int|string|null $chapter = null): void
    {
        parent::mount($record);

        $chapterId = $this->getRecord()->chapters()
            ->whereKey($chapter)
            ->value('id');

        abort_if($chapterId === null, 404);

        $this->chapterId = (int) $chapterId;
    }

    public function getTitle(): string
    {
        $chapter = $this->chapter();

        return "第 {$chapter->sequence} 章 · {$chapter->title}";
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title.' · Chapter Detail 工作台';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generatePlan')
                ->label('AI Generate Plan')
                ->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => $this->chapter()->latestPlan === null)
                ->disabled(fn (): bool => $this->hasActivePlanningRun())
                ->tooltip(fn (): ?string => $this->hasActivePlanningRun() ? 'Chapter Planning 已在运行。' : null)
                ->action(function (): void {
                    PlanChapterJob::dispatch($this->chapterId);

                    Notification::make()
                        ->title('Chapter Plan 已加入生成队列')
                        ->body('可在 Runs 页签查看执行状态。')
                        ->success()
                        ->send();
                }),
            Action::make('regeneratePlan')
                ->label('Regenerate Plan')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->chapter()->latestPlan !== null)
                ->disabled(fn (): bool => $this->hasActivePlanningRun())
                ->tooltip(fn (): ?string => $this->hasActivePlanningRun() ? 'Chapter Planning 已在运行。' : null)
                ->requiresConfirmation()
                ->modalDescription('将生成新的 Plan 版本；当前版本与 Artifact 会保留用于追踪。')
                ->action(function (): void {
                    PlanChapterJob::dispatch($this->chapterId, true);

                    Notification::make()
                        ->title('Chapter Plan 重新生成已排队')
                        ->body('可在 Runs 页签查看执行状态。')
                        ->success()
                        ->send();
                }),
            Action::make('planningPreview')
                ->label('Planning Preview')
                ->icon('heroicon-o-eye')
                ->visible(fn (): bool => $this->chapter()->latestPlan !== null)
                ->url(fn (): string => NovelResource::getUrl('planning-preview', [
                    'record' => $this->getRecord(),
                    'chapter' => $this->chapter(),
                ])),
            Action::make('chapters')
                ->label('返回章节列表')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => NovelResource::getUrl('chapters', [
                    'record' => $this->getRecord(),
                ])),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Chapter Workspace')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Overview')
                        ->icon('heroicon-o-squares-2x2')
                        ->schema($this->overviewSchema()),
                    Tab::make('Plan')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->badge(fn (): string => $this->chapter()->latestPlan === null ? '未建立' : 'v'.$this->chapter()->latestPlan->version)
                        ->schema($this->planSchema()),
                    Tab::make('Scenes')
                        ->icon('heroicon-o-rectangle-stack')
                        ->badge(fn (): int => $this->chapter()->scenes->count())
                        ->schema($this->scenesSchema()),
                    Tab::make('Draft')
                        ->icon('heroicon-o-document-text')
                        ->schema([$this->futureSection('Draft 尚未接入', '章节草稿将在后续生成流水线任务中通过 immutable Artifact 接入。')]),
                    Tab::make('Events')
                        ->icon('heroicon-o-bolt')
                        ->schema([$this->futureSection('Story Events 尚未接入', '正式 Story Events 必须由通过 Review 的 Canonical Chapter 产生。')]),
                    Tab::make('Review')
                        ->icon('heroicon-o-shield-check')
                        ->schema([$this->futureSection('Review 尚未接入', 'Review 结果和人工处理动作将在后续 Review 工作流任务中接入。')]),
                    Tab::make('State Changes')
                        ->icon('heroicon-o-arrows-right-left')
                        ->schema($this->stateChangesSchema()),
                    Tab::make('Runs')
                        ->icon('heroicon-o-command-line')
                        ->badge(fn (): int => $this->chapter()->generationRuns()->count())
                        ->schema($this->runsSchema()),
                ]),
        ]);
    }

    /** @return array<int, mixed> */
    private function overviewSchema(): array
    {
        return [
            Section::make('章节概览')
                ->description('从计划到正式状态的单章工作入口。')
                ->columns(['default' => 1, 'md' => 3, 'xl' => 6])
                ->schema([
                    TextEntry::make('chapter_status')
                        ->label('Chapter Status')
                        ->state(fn () => $this->chapter()->status)
                        ->badge(),
                    TextEntry::make('volume')
                        ->label('所属分卷')
                        ->state(fn (): ?string => $this->chapter()->volume === null
                            ? null
                            : "第 {$this->chapter()->volume->sequence} 卷 · {$this->chapter()->volume->title}")
                        ->placeholder('未指定'),
                    TextEntry::make('word_count')
                        ->label('字数')
                        ->state(fn (): int => $this->chapter()->word_count)
                        ->numeric(),
                    TextEntry::make('plan_status')
                        ->label('Plan')
                        ->state(fn () => $this->chapter()->latestPlan?->status)
                        ->badge()
                        ->placeholder('未建立'),
                    TextEntry::make('scene_count')
                        ->label('Scenes')
                        ->state(fn (): int => $this->chapter()->scenes->count()),
                    TextEntry::make('state_version')
                        ->label('State Version')
                        ->state(fn (): ?string => $this->chapter()->latestStateVersion === null
                            ? null
                            : 'v'.$this->chapter()->latestStateVersion->version)
                        ->placeholder('—'),
                ]),
            Section::make('流水线状态')
                ->description('当前阶段只汇总已经落地的 Chapter、Plan、Scene 与 Story State 数据。')
                ->schema([
                    RepeatableEntry::make('pipeline')
                        ->label('')
                        ->state(fn (): array => $this->pipelineStages())
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('stage')->label('阶段'),
                            TextEntry::make('status')->label('状态')->badge(),
                            TextEntry::make('detail')->label('说明'),
                        ]),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function planSchema(): array
    {
        return [
            Section::make('尚未建立 Chapter Plan')
                ->description('返回章节列表建立 Plan 后，这里会显示生成执行基线。')
                ->icon('heroicon-o-clipboard-document-list')
                ->visible(fn (): bool => $this->chapter()->latestPlan === null),
            Section::make('章节目标')
                ->description('完整约束与校验结果可通过右上角 Planning Preview 查看。')
                ->visible(fn (): bool => $this->chapter()->latestPlan !== null)
                ->columns(['default' => 1, 'lg' => 3])
                ->schema([
                    TextEntry::make('chapter_function')
                        ->label('Chapter Function')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->chapter_function),
                    TextEntry::make('arc_contribution')
                        ->label('Arc Contribution')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->arc_contribution),
                    TextEntry::make('reader_promise')
                        ->label('Reader Promise')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->reader_promise),
                    TextEntry::make('pov')
                        ->label('POV')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->povCharacter?->name)
                        ->placeholder('未指定'),
                    TextEntry::make('tone')
                        ->label('语气 / 钩子')
                        ->state(fn (): ?string => $this->chapter()->latestPlan === null
                            ? null
                            : $this->chapter()->latestPlan->tone.' · '.$this->chapter()->latestPlan->hook_type),
                    TextEntry::make('validation')
                        ->label('Plan Findings')
                        ->state(fn () => $this->chapter()->latestPlan === null
                            ? null
                            : app(PlanValidator::class)->validate($this->chapter()->latestPlan)->status())
                        ->badge(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function scenesSchema(): array
    {
        return [
            Section::make('尚未同步 Scenes')
                ->description('先在章节列表保存 Chapter Plan，再使用“同步 Scenes”。')
                ->icon('heroicon-o-rectangle-stack')
                ->visible(fn (): bool => $this->chapter()->scenes->isEmpty()),
            Section::make('Scene 列表')
                ->description('按执行顺序展示当前章节的场景计划。')
                ->visible(fn (): bool => $this->chapter()->scenes->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('scenes')
                        ->label('')
                        ->state(fn (): array => $this->chapter()->scenes
                            ->map(fn ($scene): array => [
                                'sequence' => 'Scene '.$scene->sequence,
                                'status' => $scene->status,
                                'pov' => $scene->povCharacter?->name,
                                'location' => $scene->location,
                                'time_anchor' => $scene->time_anchor,
                                'goal' => $scene->goal,
                                'conflict' => $scene->conflict,
                                'turn' => $scene->turn,
                                'outcome' => $scene->outcome,
                            ])
                            ->all())
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('sequence')->label('Scene')->badge(),
                            TextEntry::make('status')->label('状态')->badge(),
                            TextEntry::make('pov')->label('POV')->placeholder('未指定'),
                            TextEntry::make('location')->label('地点')->placeholder('未指定'),
                            TextEntry::make('time_anchor')->label('时间锚点')->placeholder('未指定'),
                            TextEntry::make('goal')->label('目标'),
                            TextEntry::make('conflict')->label('冲突'),
                            TextEntry::make('turn')->label('转折'),
                            TextEntry::make('outcome')->label('结果'),
                        ]),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function stateChangesSchema(): array
    {
        return [
            Section::make('尚无正式 State Changes')
                ->description('只有 Canonical Commit 才能创建新的 Story State Version。')
                ->icon('heroicon-o-arrows-right-left')
                ->visible(fn (): bool => $this->chapter()->latestStateVersion === null),
            Section::make('Canonical State Version')
                ->description('这里只展示本章关联的只读正式状态版本；具体 Diff 继续由 Story State Inspector 查看。')
                ->visible(fn (): bool => $this->chapter()->latestStateVersion !== null)
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('canonical_version')
                        ->label('Version')
                        ->state(fn (): ?string => $this->chapter()->latestStateVersion === null
                            ? null
                            : 'v'.$this->chapter()->latestStateVersion->version)
                        ->badge(),
                    TextEntry::make('checksum')
                        ->label('Checksum')
                        ->state(fn (): ?string => $this->chapter()->latestStateVersion?->checksum)
                        ->copyable(),
                    TextEntry::make('created_at')
                        ->label('创建时间')
                        ->state(fn () => $this->chapter()->latestStateVersion?->created_at)
                        ->dateTime(),
                ]),
        ];
    }

    private function futureSection(string $heading, string $description): Section
    {
        return Section::make($heading)
            ->description($description)
            ->icon('heroicon-o-clock');
    }

    /** @return array<int, mixed> */
    private function runsSchema(): array
    {
        return [
            Section::make('尚无 Chapter Planning Run')
                ->description('使用 AI Generate Plan 后，规划阶段的状态和错误会显示在这里。')
                ->icon('heroicon-o-command-line')
                ->visible(fn (): bool => $this->chapter()->generationRuns()->doesntExist()),
            Section::make('Chapter Planning Runs')
                ->description('按最近执行顺序展示模型、Prompt、State Version 与失败原因。')
                ->visible(fn (): bool => $this->chapter()->generationRuns()->exists())
                ->schema([
                    RepeatableEntry::make('planning_runs')
                        ->label('')
                        ->state(fn (): array => $this->chapter()->generationRuns()
                            ->latest('id')
                            ->get()
                            ->map(fn ($run): array => [
                                'run' => '#'.$run->getKey().' · Attempt '.$run->attempt,
                                'status' => $run->status,
                                'model' => $run->model_policy,
                                'prompt_version' => $run->prompt_version,
                                'state_version' => $run->state_version === null ? '—' : 'v'.$run->state_version,
                                'error' => $run->error_code === null ? '—' : $run->error_code.' · '.$run->error_message,
                            ])
                            ->all())
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('run')->label('Run'),
                            TextEntry::make('status')->label('Status')->badge(),
                            TextEntry::make('model')->label('Model')->placeholder('—'),
                            TextEntry::make('prompt_version')->label('Prompt Version')->placeholder('—'),
                            TextEntry::make('state_version')->label('State Version'),
                            TextEntry::make('error')->label('Error'),
                        ]),
                ]),
        ];
    }

    /** @return array<int, array{stage: string, status: string, detail: string}> */
    private function pipelineStages(): array
    {
        $chapter = $this->chapter();

        return [
            [
                'stage' => 'Plan',
                'status' => $chapter->latestPlan === null ? '未建立' : $chapter->latestPlan->status->getLabel(),
                'detail' => $chapter->latestPlan === null ? '等待手工建立 Chapter Plan' : 'Plan v'.$chapter->latestPlan->version,
            ],
            [
                'stage' => 'Scenes',
                'status' => $chapter->scenes->isEmpty() ? '未同步' : '已同步',
                'detail' => $chapter->scenes->count().' 个 Scene',
            ],
            [
                'stage' => 'Draft → Review → Commit',
                'status' => '尚未接入',
                'detail' => '由后续 Generation Pipeline 任务接入',
            ],
        ];
    }

    private function chapter(): Chapter
    {
        return $this->cachedChapter ??= Chapter::query()
            ->whereKey($this->chapterId)
            ->where('novel_id', $this->getRecord()->getKey())
            ->with([
                'volume:id,sequence,title',
                'latestPlan.povCharacter:id,name',
                'scenes.povCharacter:id,name',
                'latestStateVersion',
            ])
            ->firstOrFail();
    }

    private function hasActivePlanningRun(): bool
    {
        return $this->chapter()->generationRuns()
            ->where('stage', GenerationStage::ChapterPlanning)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->exists();
    }
}
