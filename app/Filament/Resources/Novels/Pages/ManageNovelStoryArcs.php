<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\StoryArc;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class ManageNovelStoryArcs extends ManageRelatedRecords
{
    protected static string $resource = NovelResource::class;

    protected static string $relationship = 'storyArcs';

    protected static ?string $navigationLabel = '故事线';

    protected static ?string $relationshipTitle = '故事线';

    public function getTitle(): string
    {
        return '故事线规划';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('故事线目标')
                ->description('明确主线或支线要解决的问题，以及失败会带来的代价。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->schema([
                    Select::make('volume_id')
                        ->label('所属分卷')
                        ->options(fn (): array => $this->getRecord()->volumes()->pluck('title', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->placeholder('跨卷 / 全书故事线'),
                    Select::make('type')
                        ->label('类型')
                        ->options(StoryArcType::class)
                        ->default(StoryArcType::Main)
                        ->required(),
                    TextInput::make('title')
                        ->label('标题')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Textarea::make('goal')
                        ->label('目标')
                        ->rows(3)
                        ->required(),
                    Textarea::make('stakes')
                        ->label('风险 / 代价')
                        ->rows(3)
                        ->required(),
                ]),
            Section::make('推进与完成')
                ->description('按发生顺序记录关键节拍，并写明故事线何时算真正完成。')
                ->schema([
                    Repeater::make('beats')
                        ->label('关键节拍')
                        ->simple(TextInput::make('beat')->required()->maxLength(500))
                        ->addActionLabel('添加节拍')
                        ->reorderable()
                        ->default([]),
                    Repeater::make('completion_conditions')
                        ->label('完成条件')
                        ->simple(TextInput::make('condition')->required()->maxLength(500))
                        ->addActionLabel('添加条件')
                        ->reorderable()
                        ->default([]),
                    TextInput::make('progress')
                        ->label('进度')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1)
                        ->step(0.01)
                        ->default(0)
                        ->suffix('（0–1）')
                        ->helperText('0 表示尚未开始，1 表示已经完成。')
                        ->required(),
                    Select::make('status')
                        ->label('状态')
                        ->options(StoryArcStatus::class)
                        ->default(StoryArcStatus::Planned)
                        ->required(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('volume'))
            ->columns([
                TextColumn::make('type')
                    ->label('类型')
                    ->badge()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('故事线')
                    ->weight('medium')
                    ->description(fn (StoryArc $record): string => $record->goal)
                    ->searchable(['title', 'goal']),
                TextColumn::make('stakes')
                    ->label('风险 / 代价')
                    ->limit(36)
                    ->wrap()
                    ->tooltip(fn (StoryArc $record): string => $record->stakes),
                TextColumn::make('beats')
                    ->label('关键节拍')
                    ->state(fn (StoryArc $record): string => count($record->beats ?? []).' 项')
                    ->alignEnd(),
                TextColumn::make('completion_conditions')
                    ->label('完成条件')
                    ->state(fn (StoryArc $record): string => count($record->completion_conditions ?? []).' 项')
                    ->alignEnd(),
                TextColumn::make('progress')
                    ->label('进度')
                    ->formatStateUsing(fn (float $state): string => round($state * 100).'%')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
            ])
            ->groups([
                Group::make('volume.title')
                    ->label('所属分卷'),
            ])
            ->defaultGroup('volume.title')
            ->filters([
                SelectFilter::make('type')
                    ->label('类型')
                    ->options(StoryArcType::class),
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(StoryArcStatus::class),
                SelectFilter::make('volume_id')
                    ->label('所属分卷')
                    ->options(fn (): array => $this->getRecord()->volumes()->pluck('title', 'id')->all()),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('尚未规划故事线')
            ->emptyStateDescription('创建主线或支线，明确目标、风险、关键节拍与完成条件。')
            ->emptyStateIcon('heroicon-o-arrows-right-left')
            ->recordActions([
                EditAction::make()->label('编辑'),
                DeleteAction::make()->label('删除'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('创建故事线')
                ->modalHeading('创建故事线')
                ->icon('heroicon-o-plus'),
        ];
    }
}
