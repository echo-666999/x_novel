<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Foreshadowing;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageNovelForeshadowings extends ManageRelatedRecords
{
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
                ->description('章节数据接入前暂以引用 ID 记录铺设和兑现章节；到期窗口使用章节序号。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 4,
                ])
                ->schema([
                    TextInput::make('setup_chapter_id')
                        ->label('铺设章节 ID')
                        ->integer()
                        ->minValue(1),
                    TextInput::make('due_from_chapter')
                        ->label('最早兑现章节')
                        ->integer()
                        ->minValue(1)
                        ->required(),
                    TextInput::make('due_to_chapter')
                        ->label('最晚兑现章节')
                        ->integer()
                        ->minValue(1)
                        ->gte('due_from_chapter')
                        ->required(),
                    TextInput::make('payoff_chapter_id')
                        ->label('兑现章节 ID')
                        ->integer()
                        ->minValue(1),
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
                        ->label('状态')
                        ->options(ForeshadowingStatus::class)
                        ->default(ForeshadowingStatus::Idea)
                        ->required(),
                    TextInput::make('reinforce_count')
                        ->label('强化次数')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
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
        $terminalStatuses = [
            ForeshadowingStatus::PaidOff->value,
            ForeshadowingStatus::Abandoned->value,
        ];
        $attentionOrder = $currentChapter === null
            ? 'CASE WHEN importance = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END'
            : 'CASE
                WHEN status NOT IN (?, ?) AND due_to_chapter < ? THEN 0
                WHEN importance = ? THEN 1
                WHEN status = ? THEN 2
                ELSE 3
            END';
        $attentionOrderBindings = $currentChapter === null
            ? [
                ForeshadowingImportance::Critical->value,
                ForeshadowingStatus::Due->value,
            ]
            : [
                ...$terminalStatuses,
                $currentChapter,
                ForeshadowingImportance::Critical->value,
                ForeshadowingStatus::Due->value,
            ];

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('ownerArc')
                ->orderByRaw($attentionOrder, $attentionOrderBindings)
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
                    ->state(fn (Foreshadowing $record): array => $record->attentionBadges($currentChapter))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Critical', 'Overdue' => 'danger',
                        'Due' => 'warning',
                        default => 'gray',
                    })
                    ->separator(','),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
                TextColumn::make('importance')
                    ->label('重要度')
                    ->badge()
                    ->sortable(),
                TextColumn::make('setup_chapter_id')
                    ->label('铺设章节')
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
                    ->placeholder('未记录')
                    ->alignEnd(),
                TextColumn::make('due_window')
                    ->label('到期窗口')
                    ->state(fn (Foreshadowing $record): string => "第 {$record->due_from_chapter}–{$record->due_to_chapter} 章")
                    ->alignEnd(),
                TextColumn::make('ownerArc.title')
                    ->label('所属故事线')
                    ->placeholder('未关联')
                    ->limit(24),
                TextColumn::make('payoff_chapter_id')
                    ->label('兑现章节')
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
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
                    ->query(function (Builder $query, array $data) use ($currentChapter, $terminalStatuses): Builder {
                        return match ($data['value'] ?? null) {
                            'due' => $query->whereNotIn('status', $terminalStatuses)
                                ->where(fn (Builder $query): Builder => $query
                                    ->where('status', ForeshadowingStatus::Due)
                                    ->when($currentChapter !== null, fn (Builder $query): Builder => $query->orWhere(
                                        fn (Builder $query): Builder => $query
                                            ->where('due_from_chapter', '<=', $currentChapter)
                                            ->where('due_to_chapter', '>=', $currentChapter),
                                    ))),
                            'overdue' => $currentChapter === null
                                ? $query->whereRaw('1 = 0')
                                : $query->whereNotIn('status', $terminalStatuses)
                                    ->where('due_to_chapter', '<', $currentChapter),
                            'critical' => $query->where('importance', ForeshadowingImportance::Critical),
                            default => $query,
                        };
                    }),
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(ForeshadowingStatus::class),
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
                    ->slideOver(),
                DeleteAction::make()->label('删除'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('创建伏笔')
                ->icon('heroicon-o-plus')
                ->slideOver(),
        ];
    }
}
