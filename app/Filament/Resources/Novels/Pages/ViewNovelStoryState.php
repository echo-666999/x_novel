<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Story\CreateManualFactAction;
use App\Actions\Story\ManualCanonicalCorrectionAction;
use App\Actions\Story\SetFactLockAction;
use App\Actions\Story\SupersedeFactAction;
use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use App\Enums\StoryEventStatus;
use App\Filament\Forms\Components\CharacterPicker;
use App\Filament\Forms\Components\WorldEntityPicker;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Fact;
use App\Models\Novel;
use App\Services\ProjectionRebuilder;
use App\Services\StoryStateRebuilder;
use App\Services\StoryStateService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

class ViewNovelStoryState extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = '故事状态';

    /** @var array<string, string> */
    private const DOMAINS = [
        'characters' => '人物',
        'relationships' => '关系',
        'locations' => '地点',
        'items' => '物品',
        'world' => '世界',
        'timeline' => '时间线',
        'open_threads' => '开放线索',
        'foreshadowings' => '伏笔',
        'reader_promises' => '读者承诺',
        'facts' => '事实',
        'story_events' => '故事事件',
        'state_diff' => '版本差异',
    ];

    #[Url(as: 'version')]
    public ?int $selectedVersion = null;

    #[Url(as: 'from')]
    public ?int $diffFromVersion = null;

    #[Url(as: 'to')]
    public ?int $diffToVersion = null;

    public string $activeDomain = 'characters';

    #[Url(as: 'event-status')]
    public string $eventStatus = 'active';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->selectedVersion = $this->resolveSelectedVersion($this->selectedVersion);
        $this->diffToVersion = $this->resolveSelectedVersion($this->diffToVersion ?? $this->selectedVersion);
        $this->diffFromVersion = $this->resolveDiffFromVersion($this->diffFromVersion, $this->diffToVersion);
    }

    public function getTitle(): string
    {
        return '故事状态检查器';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    protected function getHeaderActions(): array
    {
        $current = app(StoryStateService::class)->current($this->getRecord());

        return [
            Action::make('verifyRebuild')
                ->label('校验 / 重建')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible($current !== null)
                ->modalHeading('校验 Story State 重建结果')
                ->modalDescription('从 State Version 0 重放当前版本范围内的有效 Story Events。')
                ->requiresConfirmation()
                ->modalWidth('4xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('关闭')
                ->action(fn (): null => null)
                ->modalContent(fn (StoryStateRebuilder $rebuilder) => view(
                    'filament.resources.novels.pages.story-state-rebuild',
                    ['result' => $rebuilder->rebuild($this->getRecord())],
                )),
            Action::make('rebuildProjections')
                ->label('重建投影')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('warning')
                ->visible($current !== null)
                ->requiresConfirmation()
                ->modalHeading('重建领域投影')
                ->modalDescription('以当前 Canonical Story State 覆盖人物、世界实体和伏笔的派生投影。正式状态和故事事件不会改变。')
                ->modalSubmitActionLabel('确认重建')
                ->action(function (ProjectionRebuilder $rebuilder): void {
                    $health = $rebuilder->rebuild($this->getRecord());
                    $this->getRecord()->unsetRelations();

                    Notification::make()
                        ->title('领域投影已重建')
                        ->body("已根据 State Version v{$health->stateVersion} 校验 {$health->checkedCount()} 条投影。")
                        ->success()
                        ->send();
                }),
            Action::make('manualCorrection')
                ->label('人工修正')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('warning')
                ->visible($current !== null)
                ->modalHeading('人工修正 Canonical Story State')
                ->modalDescription('该操作会追加 Correction Event 并创建新的 State Version，不会修改历史快照。')
                ->modalSubmitActionLabel('创建修正版本')
                ->schema([
                    Hidden::make('expected_state_version')->default($current?->version),
                    TextInput::make('path')
                        ->label('状态路径')
                        ->placeholder('例如 characters.42.location')
                        ->helperText('必须位于已有 Story State Domain，使用点号分隔。')
                        ->maxLength(500)
                        ->required(),
                    Textarea::make('value')
                        ->label('新值（JSON）')
                        ->placeholder('例如 "洛阳"、true 或 {"status":"open"}')
                        ->helperText('输入合法 JSON；字符串需要包含双引号。')
                        ->rules(['json'])
                        ->rows(5)
                        ->required(),
                    Textarea::make('reason')
                        ->label('修正原因')
                        ->helperText('原因会写入 Correction Event，供后续追踪。')
                        ->rows(3)
                        ->maxLength(2000)
                        ->required(),
                ])
                ->action(function (array $data, ManualCanonicalCorrectionAction $manualCorrection): void {
                    $version = $manualCorrection->execute(
                        $this->getRecord(),
                        (int) $data['expected_state_version'],
                        $data['path'],
                        json_decode($data['value'], true, flags: JSON_THROW_ON_ERROR),
                        $data['reason'],
                    );

                    $this->getRecord()->refresh();
                    $this->selectedVersion = $version->version;
                    $this->diffToVersion = $version->version;
                    $this->diffFromVersion = $version->version - 1;

                    Notification::make()
                        ->title('Canonical Story State 已修正')
                        ->body('已创建 State Version v'.$version->version.'，历史版本保持不变。')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.novels.pages.story-state-inspector')
                ->viewData(fn (): array => $this->inspectorData()),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();

        return $table
            ->query($novel->facts()->getQuery())
            ->modifyQueryUsing(fn ($query) => $query->with([
                'novel:id,title',
                'characterSubject:id,name',
                'worldEntitySubject:id,name',
            ]))
            ->columns([
                TextColumn::make('subject_type')
                    ->label('主体')
                    ->state(fn (Fact $record): string => $record->subjectLabel())
                    ->description(fn (Fact $record): string => match ($record->subject_type) {
                        'character' => '人物',
                        'world_entity' => '世界实体',
                        'novel' => '小说',
                        default => $record->subject_type,
                    })
                    ->searchable(['subject_type', 'subject_id']),
                TextColumn::make('predicate')
                    ->label('谓词')
                    ->fontFamily('mono')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('value')
                    ->label('值')
                    ->state(fn (Fact $record): string => $record->valueSummary())
                    ->fontFamily('mono')
                    ->limit(48)
                    ->tooltip(fn (Fact $record): string => $record->valueSummary()),
                TextColumn::make('hardness')
                    ->label('硬度')
                    ->badge()
                    ->sortable(),
                TextColumn::make('source_type')
                    ->label('来源')
                    ->badge()
                    ->sortable(),
                IconColumn::make('locked')
                    ->label('锁定')
                    ->boolean()
                    ->trueIcon('heroicon-s-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('locked')
                    ->label('锁定状态')
                    ->placeholder('全部')
                    ->trueLabel('已锁定')
                    ->falseLabel('未锁定'),
                SelectFilter::make('status')
                    ->label('有效状态')
                    ->options(FactStatus::class),
                SelectFilter::make('source_type')
                    ->label('来源')
                    ->options(FactSourceType::class),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordAction(null)
            ->headerActions([
                Action::make('createManualFact')
                    ->label('新增手工事实')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('新增手工事实')
                    ->modalDescription('手工事实直接进入规范事实库；锁定后将优先于模型建议。')
                    ->schema($this->manualFactSchema())
                    ->action(function (array $data, CreateManualFactAction $createManualFact): void {
                        $createManualFact->execute($this->getRecord(), [
                            'subject_type' => $data['subject_type'],
                            'subject_id' => $this->manualFactSubjectId($data),
                            'predicate' => $data['predicate'],
                            'value' => $this->manualFactValue($data),
                            'hardness' => $data['hardness'],
                            'confidence' => $data['confidence'],
                            'locked' => $data['locked'],
                        ]);

                        Notification::make()->title('手工事实已创建')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('lock')
                    ->label('锁定')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (Fact $record): bool => $record->status === FactStatus::Active && ! $record->locked)
                    ->action(function (Fact $record, SetFactLockAction $setFactLock): void {
                        $setFactLock->execute($this->getRecord(), $record, true);
                        Notification::make()->title('事实已锁定')->success()->send();
                    }),
                Action::make('unlock')
                    ->label('解锁')
                    ->icon('heroicon-o-lock-open')
                    ->color('gray')
                    ->visible(fn (Fact $record): bool => $record->status === FactStatus::Active && $record->locked)
                    ->requiresConfirmation()
                    ->modalDescription('解锁后，该事实不再作为最高优先级约束。')
                    ->action(function (Fact $record, SetFactLockAction $setFactLock): void {
                        $setFactLock->execute($this->getRecord(), $record, false);
                        Notification::make()->title('事实已解锁')->success()->send();
                    }),
                Action::make('supersede')
                    ->label('替代')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->visible(fn (Fact $record): bool => $record->status === FactStatus::Active)
                    ->requiresConfirmation()
                    ->modalHeading('替代此事实？')
                    ->modalDescription('该事实将保留为历史记录，并从有效事实中移除。')
                    ->action(function (Fact $record, SupersedeFactAction $supersedeFact): void {
                        $supersedeFact->execute($this->getRecord(), $record);
                        Notification::make()->title('事实已标记为已替代')->success()->send();
                    }),
            ])
            ->emptyStateHeading('尚未记录事实')
            ->emptyStateDescription('正式故事中的可查询事实会显示在这里。')
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    /** @return array<int, mixed> */
    private function manualFactSchema(): array
    {
        return [
            Select::make('subject_type')
                ->label('主体类型')
                ->options([
                    'character' => '人物',
                    'world_entity' => '世界实体',
                    'novel' => '当前小说',
                ])
                ->default('character')
                ->live()
                ->required(),
            CharacterPicker::make('character_id')
                ->label('人物')
                ->novel(fn (): Novel => $this->getRecord())
                ->visible(fn (Get $get): bool => $get('subject_type') === 'character')
                ->required(fn (Get $get): bool => $get('subject_type') === 'character'),
            WorldEntityPicker::make('world_entity_id')
                ->label('世界实体')
                ->novel(fn (): Novel => $this->getRecord())
                ->visible(fn (Get $get): bool => $get('subject_type') === 'world_entity')
                ->required(fn (Get $get): bool => $get('subject_type') === 'world_entity'),
            TextInput::make('predicate')
                ->label('谓词')
                ->placeholder('例如 can_swim')
                ->helperText('使用稳定、可查询的英文标识。')
                ->regex('/^[a-z][a-z0-9_.-]*$/')
                ->maxLength(255)
                ->required(),
            Select::make('value_type')
                ->label('值类型')
                ->options([
                    'boolean' => '布尔值',
                    'string' => '文本',
                    'number' => '数字',
                    'json' => 'JSON',
                ])
                ->default('boolean')
                ->live()
                ->required(),
            Toggle::make('boolean_value')
                ->label('值')
                ->visible(fn (Get $get): bool => $get('value_type') === 'boolean')
                ->default(false),
            TextInput::make('string_value')
                ->label('值')
                ->visible(fn (Get $get): bool => $get('value_type') === 'string')
                ->required(fn (Get $get): bool => $get('value_type') === 'string'),
            TextInput::make('number_value')
                ->label('值')
                ->numeric()
                ->visible(fn (Get $get): bool => $get('value_type') === 'number')
                ->required(fn (Get $get): bool => $get('value_type') === 'number'),
            Textarea::make('json_value')
                ->label('JSON 值')
                ->rows(4)
                ->rule('json')
                ->visible(fn (Get $get): bool => $get('value_type') === 'json')
                ->required(fn (Get $get): bool => $get('value_type') === 'json'),
            Select::make('hardness')
                ->label('硬度')
                ->options(FactHardness::class)
                ->default(FactHardness::Hard)
                ->required(),
            TextInput::make('confidence')
                ->label('置信度')
                ->numeric()
                ->minValue(0)
                ->maxValue(1)
                ->step(0.01)
                ->default(1)
                ->required(),
            Toggle::make('locked')
                ->label('立即锁定')
                ->helperText('锁定事实会作为最高优先级约束。')
                ->default(true),
        ];
    }

    /** @param array<string, mixed> $data */
    private function manualFactSubjectId(array $data): int
    {
        return match ($data['subject_type']) {
            'character' => (int) $data['character_id'],
            'world_entity' => (int) $data['world_entity_id'],
            'novel' => (int) $this->getRecord()->getKey(),
        };
    }

    /** @param array<string, mixed> $data */
    private function manualFactValue(array $data): mixed
    {
        return match ($data['value_type']) {
            'boolean' => (bool) ($data['boolean_value'] ?? false),
            'string' => $data['string_value'],
            'number' => (float) $data['number_value'],
            'json' => json_decode($data['json_value'], true, flags: JSON_THROW_ON_ERROR),
        };
    }

    public function selectVersion(int $version): void
    {
        $this->selectedVersion = $this->resolveSelectedVersion($version);
    }

    public function selectDomain(string $domain): void
    {
        if (array_key_exists($domain, self::DOMAINS)) {
            $this->activeDomain = $domain;
        }
    }

    public function selectDiffVersion(string $side, int $version): void
    {
        $resolvedVersion = $this->resolveSelectedVersion($version);

        if ($side === 'from') {
            $this->diffFromVersion = $resolvedVersion;
        }

        if ($side === 'to') {
            $this->diffToVersion = $resolvedVersion;
        }
    }

    public function selectEventStatus(string $status): void
    {
        if (in_array($status, ['active', 'invalidated', 'all'], true)) {
            $this->eventStatus = $status;
        }
    }

    /** @return array<string, mixed> */
    private function inspectorData(): array
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();
        $versions = $novel->storyStateVersions()
            ->orderByDesc('version')
            ->get();
        $activeDomain = array_key_exists($this->activeDomain, self::DOMAINS)
            ? $this->activeDomain
            : 'characters';
        $storyState = app(StoryStateService::class);
        $projectionHealth = $storyState->current($novel) === null
            ? null
            : app(ProjectionRebuilder::class)->inspect($novel);
        $stateVersion = $this->selectedVersion === null
            ? null
            : $storyState->findVersion($novel, $this->selectedVersion);
        $diffFrom = $this->diffFromVersion === null
            ? null
            : $storyState->findVersion($novel, $this->diffFromVersion);
        $diffTo = $this->diffToVersion === null
            ? null
            : $storyState->findVersion($novel, $this->diffToVersion);
        $eventStatus = in_array($this->eventStatus, ['active', 'invalidated', 'all'], true)
            ? $this->eventStatus
            : StoryEventStatus::Active->value;
        $storyEvents = $activeDomain === 'story_events'
            ? $novel->storyEvents()
                ->with(['chapter:id,sequence,title', 'scene:id,sequence'])
                ->when($eventStatus !== 'all', fn ($query) => $query->where('status', $eventStatus))
                ->orderByDesc('chapter_id')
                ->orderByDesc('id')
                ->get()
            : collect();

        return [
            'novel' => $novel,
            'versions' => $versions,
            'stateVersion' => $stateVersion,
            'domains' => self::DOMAINS,
            'activeDomain' => $activeDomain,
            'domainState' => in_array($activeDomain, ['facts', 'story_events', 'state_diff'], true)
                ? []
                : ($stateVersion?->state[$activeDomain] ?? []),
            'eventStatus' => $eventStatus,
            'storyEvents' => $storyEvents,
            'isCurrent' => $stateVersion?->is($storyState->current($novel)) ?? false,
            'diffFrom' => $diffFrom,
            'diffTo' => $diffTo,
            'stateChanges' => $diffFrom !== null && $diffTo !== null
                ? $storyState->diff($diffFrom->state, $diffTo->state)
                : [],
            'projectionHealth' => $projectionHealth,
        ];
    }

    private function resolveSelectedVersion(?int $requestedVersion): ?int
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();

        if ($requestedVersion !== null && app(StoryStateService::class)->findVersion($novel, $requestedVersion) !== null) {
            return $requestedVersion;
        }

        return app(StoryStateService::class)->current($novel)?->version;
    }

    private function resolveDiffFromVersion(?int $requestedVersion, ?int $toVersion): ?int
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();

        if ($requestedVersion !== null && app(StoryStateService::class)->findVersion($novel, $requestedVersion) !== null) {
            return $requestedVersion;
        }

        if ($toVersion === null) {
            return null;
        }

        return $novel->storyStateVersions()
            ->where('version', '<', $toVersion)
            ->max('version') ?? $toVersion;
    }
}
