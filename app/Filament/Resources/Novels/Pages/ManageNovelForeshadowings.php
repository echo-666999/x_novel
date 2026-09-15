<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Foreshadowings\AbandonForeshadowingAction;
use App\Actions\Foreshadowings\DeferForeshadowingAction;
use App\Data\ProjectionHealth;
use App\Enums\ChapterStatus;
use App\Enums\EventType;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingPlanAction;
use App\Enums\ForeshadowingStatus;
use App\Enums\ForeshadowingTimingStatus;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\Foreshadowing;
use App\Models\StoryEvent;
use App\Services\ForeshadowingLifecycleResolver;
use App\Services\ProjectionRebuilder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ManageNovelForeshadowings extends ManageRelatedRecords
{
    private bool $projectionInspected = false;

    private ?ProjectionHealth $projectionHealthCache = null;

    private ?array $latestEventMapCache = null;

    private ?array $plannedActionMapCache = null;

    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-light-bulb';

    protected static string $relationship = 'foreshadowings';

    protected static ?string $navigationLabel = '伏笔';

    protected static ?string $relationshipTitle = '伏笔';

    public function getTitle(): string
    {
        return '伏笔管理';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('伏笔与承诺')
                ->description('明确铺设内容、对读者的承诺，以及它服务的故事线。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->schema([
                    TextInput::make('title')
                        ->label('标题')
                        ->required()
                        ->maxLength(255),
                    Select::make('owner_arc_id')
                        ->label('所属故事线')
                        ->options(fn (): array => $this->getRecord()->storyArcs()
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->placeholder('未关联故事线'),
                    Textarea::make('description')
                        ->label('描述')
                        ->rows(3)
                        ->required()
                        ->columnSpanFull(),
                    Textarea::make('promised_payoff')
                        ->label('预期兑现')
                        ->rows(3)
                        ->required()
                        ->columnSpanFull(),
                ]),
            Section::make('时间窗口')
                ->description('铺设和兑现章节只允许选择当前小说的章节。已有伏笔的章节引用由 Canonical 投影维护；延期请使用列表中的“延期”操作。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 4,
                ])
                ->schema([
                    Select::make('setup_chapter_id')
                        ->label('铺设章节')
                        ->options(fn (): array => $this->chapterOptions())
                        ->searchable()
                        ->preload()
                        ->placeholder('尚未铺设')
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),
                    TextInput::make('due_from_chapter')
                        ->label('最早兑现章节')
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),
                    TextInput::make('due_to_chapter')
                        ->label('最晚兑现章节')
                        ->integer()
                        ->minValue(1)
                        ->gte('due_from_chapter')
                        ->required()
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),
                    Select::make('payoff_chapter_id')
                        ->label('兑现章节')
                        ->options(fn (): array => $this->chapterOptions())
                        ->searchable()
                        ->preload()
                        ->placeholder('尚未兑现')
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),
                ]),
            Section::make('推进状态')
                ->columns([
                    'default' => 1,
                    'md' => 3,
                ])
                ->schema([
                    Select::make('importance')
                        ->label('重要度')
                        ->options(ForeshadowingImportance::class)
                        ->default(ForeshadowingImportance::Medium)
                        ->required(),
                    Select::make('status')
                        ->label('内容状态')
                        ->options(ForeshadowingStatus::contentOptions())
                        ->default(ForeshadowingStatus::Idea)
                        ->required()
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),
                    TextInput::make('reinforce_count')
                        ->label('强化次数')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),
                    Textarea::make('notes')
                        ->label('备注')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        $currentChapter = $this->getRecord()->current_chapter_sequence;

        return $table
            ->heading('伏笔状态与处理')
            ->description(fn (): string => $this->progressDescription())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('ownerArc')
                ->orderByTimingAt($currentChapter)
                ->orderByRaw('CASE WHEN importance = ? THEN 0 ELSE 1 END', [ForeshadowingImportance::Critical->value])
                ->orderBy('due_to_chapter')
                ->orderByDesc('updated_at'))
            ->columns([
                TextColumn::make('title')
                    ->label('伏笔')
                    ->weight('medium')
                    ->description(fn (Foreshadowing $record): string => (string) str($record->promised_payoff)->limit(48))
                    ->searchable(['title', 'description', 'promised_payoff']),
                TextColumn::make('attention')
                    ->label('需处理')
                    ->state(fn (Foreshadowing $record): array => $this->attentionBadges($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        '关键', '已逾期' => 'danger',
                        '兑现窗口' => 'warning',
                        default => 'gray',
                    })
                    ->separator(','),
                TextColumn::make('canonical_status')
                    ->label('Canonical 内容状态')
                    ->state(fn (Foreshadowing $record): string => $this->canonicalStatus($record)->value)
                    ->formatStateUsing(fn (string $state): string => ForeshadowingStatus::from($state)->getLabel())
                    ->color(fn (string $state): string => ForeshadowingStatus::from($state)->getColor())
                    ->badge()
                    ->description(fn (Foreshadowing $record): string => $this->canonicalStatusSource($record)),
                TextColumn::make('timing_status')
                    ->label('时限状态')
                    ->state(fn (Foreshadowing $record): ?string => $this->timingStatus($record)?->value)
                    ->formatStateUsing(fn (string $state): string => ForeshadowingTimingStatus::from($state)->getLabel())
                    ->color(fn (string $state): string => ForeshadowingTimingStatus::from($state)->getColor())
                    ->badge()
                    ->placeholder('已结束'),
                TextColumn::make('projection_health')
                    ->label('表投影')
                    ->state(fn (Foreshadowing $record): string => $this->projectionHealthLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => $state === '一致' ? 'success' : 'danger')
                    ->description(fn (Foreshadowing $record): string => $record->status->getLabel().' · 强化 '.$record->reinforce_count.' 次'),
                TextColumn::make('canonical_reinforce_count')
                    ->label('Canonical 强化')
                    ->state(fn (Foreshadowing $record): ?int => $this->canonicalReinforceCount($record))
                    ->formatStateUsing(fn (int $state): string => $state.' 次')
                    ->placeholder('未记录')
                    ->alignEnd(),
                TextColumn::make('latest_event')
                    ->label('最近有效事件')
                    ->state(fn (Foreshadowing $record): string => $this->latestEventLabel($record))
                    ->description(fn (Foreshadowing $record): ?string => $this->latestEventEvidence($record))
                    ->wrap(),
                TextColumn::make('next_plan_action')
                    ->label('计划中下一动作')
                    ->state(fn (Foreshadowing $record): string => $this->plannedActionLabel($record))
                    ->wrap(),
                TextColumn::make('importance')
                    ->label('重要度')
                    ->badge()
                    ->sortable(),
                TextColumn::make('setup_chapter_id')
                    ->label('铺设章节')
                    ->formatStateUsing(fn (int $state): string => $this->chapterLabel($state))
                    ->placeholder('未记录')
                    ->alignEnd(),
                TextColumn::make('due_window')
                    ->label('兑现窗口')
                    ->state(fn (Foreshadowing $record): string => "第 {$record->due_from_chapter}–{$record->due_to_chapter} 章")
                    ->alignEnd(),
                TextColumn::make('ownerArc.title')
                    ->label('所属故事线')
                    ->placeholder('未关联')
                    ->limit(24),
                TextColumn::make('payoff_chapter_id')
                    ->label('兑现章节')
                    ->formatStateUsing(fn (int $state): string => $this->chapterLabel($state))
                    ->placeholder('未兑现')
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('attention')
                    ->label('需处理')
                    ->options([
                        'due' => 'Due',
                        'overdue' => 'Overdue',
                        'critical' => 'Critical',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'due' => $query->whereIn('id', $this->timingIds(ForeshadowingTimingStatus::Due)),
                            'overdue' => $query->whereIn('id', $this->timingIds(ForeshadowingTimingStatus::Overdue)),
                            'critical' => $query->where('importance', ForeshadowingImportance::Critical),
                            default => $query,
                        };
                    }),
                SelectFilter::make('canonical_status')
                    ->label('Canonical 内容状态')
                    ->options(ForeshadowingStatus::class)
                    ->query(function (Builder $query, array $data): Builder {
                        $status = ForeshadowingStatus::tryFrom((string) ($data['value'] ?? ''));

                        return $status === null
                            ? $query
                            : $query->whereIn('id', $this->canonicalStatusIds($status));
                    }),
                SelectFilter::make('importance')
                    ->label('重要度')
                    ->options(ForeshadowingImportance::class),
                SelectFilter::make('owner_arc_id')
                    ->label('所属故事线')
                    ->options(fn (): array => $this->getRecord()->storyArcs()
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all()),
            ])
            ->recordAction('view')
            ->emptyStateHeading('尚未记录伏笔')
            ->emptyStateDescription('记录铺设内容、读者承诺和预计兑现窗口。')
            ->emptyStateIcon('heroicon-o-flag')
            ->recordActions([
                ViewAction::make()
                    ->label('查看')
                    ->slideOver(),
                EditAction::make()
                    ->label('编辑')
                    ->modalDescription('标题、描述、承诺、重要度和备注可直接维护；窗口、内容状态、强化次数和章节引用由受控操作或 Canonical 投影维护。')
                    ->slideOver(),
                Action::make('arrangeRepair')
                    ->label('安排修复章')
                    ->icon('heroicon-o-document-plus')
                    ->color('warning')
                    ->visible(fn (Foreshadowing $record): bool => $this->isCriticalOverdue($record))
                    ->tooltip('前往章节列表，在下一章 Chapter Plan 中安排明确的 pay_off 修复动作。')
                    ->url(fn (): string => NovelResource::getUrl('chapters', ['record' => $this->getRecord()])),
                Action::make('defer')
                    ->label('延期')
                    ->icon('heroicon-o-calendar-days')
                    ->color('warning')
                    ->visible(fn (Foreshadowing $record): bool => $this->isCriticalOverdue($record))
                    ->modalHeading(fn (Foreshadowing $record): string => "延期伏笔：{$record->title}")
                    ->modalDescription('延期保留当前内容状态，并记录旧/新窗口、原因、操作者及当前 Canonical 进度。')
                    ->schema([
                        TextInput::make('new_due_from_chapter')->label('新窗口开始章')->integer()->minValue(1)->required(),
                        TextInput::make('new_due_to_chapter')->label('新窗口结束章')->integer()->minValue(1)->gte('new_due_from_chapter')->required(),
                        Textarea::make('reason')->label('延期原因')->rows(3)->maxLength(2000)->required(),
                    ])
                    ->action(function (Foreshadowing $record, array $data, DeferForeshadowingAction $defer): void {
                        $defer->execute(
                            $record,
                            (int) $data['new_due_from_chapter'],
                            (int) $data['new_due_to_chapter'],
                            $data['reason'],
                            auth()->id(),
                        );

                        Notification::make()->title('伏笔兑现窗口已延期')->success()->send();
                    }),
                Action::make('abandon')
                    ->label('放弃')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Foreshadowing $record): bool => $this->isCriticalOverdue($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Foreshadowing $record): string => "放弃伏笔：{$record->title}")
                    ->modalDescription('该操作会创建 Manual Correction Event 和新的 Canonical State Version，不会伪造正文事件。')
                    ->schema([
                        Textarea::make('reason')->label('放弃原因')->rows(3)->maxLength(2000)->required(),
                    ])
                    ->action(function (Foreshadowing $record, array $data, AbandonForeshadowingAction $abandon): void {
                        $version = $abandon->execute($record, $data['reason'], auth()->id());

                        Notification::make()
                            ->title('伏笔已标记为放弃')
                            ->body("Canonical Story State 已创建 v{$version->version}；表投影将异步刷新。")
                            ->success()
                            ->send();
                    }),
                Action::make('inspectProjection')
                    ->label('检查投影')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->color(fn (Foreshadowing $record): string => $this->projectionHealthLabel($record) === '一致' ? 'gray' : 'danger')
                    ->url(fn (): string => NovelResource::getUrl('story-state', ['record' => $this->getRecord()])),
                DeleteAction::make()->label('删除'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rebuildProjection')
                ->label('重建投影')
                ->icon('heroicon-o-arrow-path')
                ->color($this->projectionHealth()?->isHealthy() === false ? 'warning' : 'gray')
                ->requiresConfirmation()
                ->modalHeading('根据 Canonical State 重建领域投影？')
                ->modalDescription('只修复派生表字段，不修改 Story Events、Canonical Story State 或历史章节。')
                ->action(function (ProjectionRebuilder $rebuilder): void {
                    $health = $rebuilder->rebuild($this->getRecord());
                    $this->projectionInspected = false;
                    $this->projectionHealthCache = null;

                    Notification::make()
                        ->title('伏笔领域投影已重建')
                        ->body("已按 State Version v{$health->stateVersion} 完成校验。")
                        ->success()
                        ->send();
                }),
            CreateAction::make()
                ->label('创建伏笔')
                ->icon('heroicon-o-plus')
                ->slideOver(),
        ];
    }

    /** @return array<int, string> */
    private function chapterOptions(): array
    {
        return $this->getRecord()->chapters()->get(['id', 'sequence', 'title'])
            ->mapWithKeys(fn (Chapter $chapter): array => [
                $chapter->getKey() => "第 {$chapter->sequence} 章 · {$chapter->title}",
            ])->all();
    }

    private function chapterLabel(int $chapterId): string
    {
        return $this->chapterOptions()[$chapterId] ?? "未知章节 #{$chapterId}";
    }

    private function progressDescription(): string
    {
        $canonical = $this->getRecord()->current_chapter_sequence;
        $workflow = $this->activeWorkflowChapter();
        $progress = 'Canonical 进度：'.($canonical === null ? '尚无正式章节' : "第 {$canonical} 章");
        $progress .= '；活跃章节：'.($workflow === null ? '无' : "第 {$workflow->sequence} 章（{$workflow->status->getLabel()}）");
        $health = $this->projectionHealth();

        if ($health === null) {
            return $progress.'；警告：当前投影无法校验，请进入 Story State 检查。';
        }

        return $health->foreshadowingDriftIds === []
            ? $progress.'；伏笔表投影与 Canonical State 一致。'
            : $progress.'；警告：发现 '.count($health->foreshadowingDriftIds).' 条伏笔投影漂移，页面以 Canonical State 为准。';
    }

    private function activeWorkflowChapter(): ?Chapter
    {
        return $this->getRecord()->chapters()
            ->where(function (Builder $query): void {
                $query->whereIn('status', [
                    ChapterStatus::Generating->value,
                    ChapterStatus::Review->value,
                    ChapterStatus::Rewrite->value,
                    ChapterStatus::Blocked->value,
                ])->orWhereHas('generationRuns', fn (Builder $run): Builder => $run->whereIn('status', [
                    RunStatus::Queued->value,
                    RunStatus::Running->value,
                ]));
            })
            ->orderBy('sequence')
            ->first();
    }

    private function canonicalStatus(Foreshadowing $foreshadowing): ForeshadowingStatus
    {
        return app(ForeshadowingLifecycleResolver::class)->status(
            $foreshadowing,
            $this->getRecord()->loadMissing('canonicalStateVersion'),
        );
    }

    private function canonicalStatusSource(Foreshadowing $foreshadowing): string
    {
        return app(ForeshadowingLifecycleResolver::class)->source(
            $foreshadowing,
            $this->getRecord()->loadMissing('canonicalStateVersion'),
        ) === 'canonical_state' ? '来源：Canonical State' : 'Canonical 未记录；显示表投影';
    }

    private function canonicalReinforceCount(Foreshadowing $foreshadowing): ?int
    {
        $value = data_get(
            $this->getRecord()->loadMissing('canonicalStateVersion')->canonicalStateVersion?->state,
            "foreshadowings.{$foreshadowing->getKey()}.reinforce_count",
        );

        return is_numeric($value) ? (int) $value : null;
    }

    private function timingStatus(Foreshadowing $foreshadowing): ?ForeshadowingTimingStatus
    {
        return ForeshadowingTimingStatus::forTargetChapter(
            $this->canonicalStatus($foreshadowing),
            $foreshadowing->due_from_chapter,
            $foreshadowing->due_to_chapter,
            Foreshadowing::nextChapterSequence($this->getRecord()->current_chapter_sequence),
        );
    }

    /** @return array<int> */
    private function timingIds(ForeshadowingTimingStatus $status): array
    {
        return $this->getRecord()->foreshadowings()->get()
            ->filter(fn (Foreshadowing $foreshadowing): bool => $this->timingStatus($foreshadowing) === $status)
            ->modelKeys();
    }

    private function isCriticalOverdue(Foreshadowing $foreshadowing): bool
    {
        return $foreshadowing->importance === ForeshadowingImportance::Critical
            && $this->timingStatus($foreshadowing) === ForeshadowingTimingStatus::Overdue;
    }

    /** @return array<string> */
    private function attentionBadges(Foreshadowing $foreshadowing): array
    {
        $badges = [];

        if ($foreshadowing->importance === ForeshadowingImportance::Critical) {
            $badges[] = '关键';
        }

        if (($timing = $this->timingStatus($foreshadowing)) !== null) {
            $badges[] = $timing->getLabel();
        }

        return $badges;
    }

    /** @return array<int> */
    private function canonicalStatusIds(ForeshadowingStatus $status): array
    {
        return $this->getRecord()->foreshadowings()->get()
            ->filter(fn (Foreshadowing $foreshadowing): bool => $this->canonicalStatus($foreshadowing) === $status)
            ->modelKeys();
    }

    private function projectionHealth(): ?ProjectionHealth
    {
        if (! $this->projectionInspected) {
            $this->projectionInspected = true;

            try {
                $this->projectionHealthCache = app(ProjectionRebuilder::class)->inspect($this->getRecord());
            } catch (Throwable) {
                $this->projectionHealthCache = null;
            }
        }

        return $this->projectionHealthCache;
    }

    private function projectionHealthLabel(Foreshadowing $foreshadowing): string
    {
        $health = $this->projectionHealth();

        if ($health === null || $health->errors !== []) {
            return '无法校验';
        }

        return in_array($foreshadowing->getKey(), $health->foreshadowingDriftIds, true) ? '已漂移' : '一致';
    }

    private function latestEvent(Foreshadowing $foreshadowing): ?StoryEvent
    {
        if ($this->latestEventMapCache === null) {
            $this->latestEventMapCache = $this->getRecord()->storyEvents()
                ->active()
                ->where(function (Builder $query): void {
                    $query->where(function (Builder $narrativeEvent): void {
                        $narrativeEvent
                            ->where('subject_type', 'foreshadowing')
                            ->whereIn('event_type', [
                                EventType::ForeshadowingPlanted->value,
                                EventType::ForeshadowingReinforced->value,
                                EventType::ForeshadowingPaidOff->value,
                                EventType::ForeshadowingAbandoned->value,
                            ]);
                    })->orWhere(function (Builder $managementEvent): void {
                        $managementEvent
                            ->where('subject_type', 'foreshadowings')
                            ->where('event_type', EventType::ManualCorrection->value);
                    });
                })
                ->with('chapter:id,sequence,title')
                ->orderByDesc('state_version')
                ->orderByDesc('id')
                ->get()
                ->unique(fn (StoryEvent $event): int => (int) $event->subject_id)
                ->mapWithKeys(fn (StoryEvent $event): array => [(int) $event->subject_id => $event])
                ->all();
        }

        return $this->latestEventMapCache[$foreshadowing->getKey()] ?? null;
    }

    private function latestEventLabel(Foreshadowing $foreshadowing): string
    {
        $event = $this->latestEvent($foreshadowing);

        if ($event === null) {
            return '尚无有效事件';
        }

        $source = $event->chapter === null
            ? "Canonical v{$event->state_version}"
            : "第 {$event->chapter->sequence} 章";

        return "{$source} · {$event->event_type->getLabel()}";
    }

    private function latestEventEvidence(Foreshadowing $foreshadowing): ?string
    {
        $event = $this->latestEvent($foreshadowing);
        $quote = data_get($event?->evidence, '0.quote') ?? data_get($event?->payload, 'reason');

        return is_string($quote) ? (string) str($quote)->limit(56) : null;
    }

    private function plannedActionLabel(Foreshadowing $foreshadowing): string
    {
        if ($this->plannedActionMapCache === null) {
            $this->plannedActionMapCache = [];
            $chapters = $this->getRecord()->chapters()
                ->where('sequence', '>', $this->getRecord()->current_chapter_sequence ?? 0)
                ->whereNotIn('status', [ChapterStatus::Canonical->value, ChapterStatus::Void->value])
                ->with('latestPlan')
                ->orderBy('sequence')
                ->get();

            foreach ($chapters as $chapter) {
                foreach ($chapter->latestPlan?->foreshadowingActionContracts() ?? [] as $contract) {
                    $id = (int) ($contract['foreshadowing_id'] ?? 0);
                    $action = ForeshadowingPlanAction::tryFrom((string) ($contract['action'] ?? ''));
                    if ($id > 0 && $action !== null && ! isset($this->plannedActionMapCache[$id])) {
                        $this->plannedActionMapCache[$id] = "第 {$chapter->sequence} 章 · {$action->getLabel()}";
                    }
                }
            }
        }

        return $this->plannedActionMapCache[$foreshadowing->getKey()] ?? '尚未安排';
    }
}
