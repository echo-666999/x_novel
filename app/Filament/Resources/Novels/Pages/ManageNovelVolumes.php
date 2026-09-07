<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Volume;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class ManageNovelVolumes extends ManageRelatedRecords
{
    protected static string $resource = NovelResource::class;

    protected static string $relationship = 'volumes';

    protected static ?string $navigationLabel = '分卷';

    protected static ?string $relationshipTitle = '分卷';

    public function getTitle(): string
    {
        return '分卷规划';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('分卷目标')
                    ->description('定义这一卷存在的目的、高潮和篇幅边界。')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->schema([
                        TextInput::make('sequence')
                            ->label('卷序')
                            ->helperText('同一部小说内不可重复。')
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->unique(
                                table: Volume::class,
                                column: 'sequence',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('novel_id', $this->getRecord()->getKey()),
                            ),
                        TextInput::make('title')
                            ->label('卷名')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('goal')
                            ->label('本卷目标')
                            ->rows(4)
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('climax')
                            ->label('本卷高潮')
                            ->rows(4)
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('target_words')
                            ->label('目标字数')
                            ->integer()
                            ->minValue(1)
                            ->default(200_000)
                            ->required(),
                        Select::make('status')
                            ->label('状态')
                            ->options(VolumeStatus::class)
                            ->default(VolumeStatus::Planned)
                            ->required(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')
                    ->label('卷序')
                    ->formatStateUsing(fn (int $state): string => "第 {$state} 卷")
                    ->sortable(),
                TextColumn::make('title')
                    ->label('卷名')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('goal')
                    ->label('本卷目标')
                    ->limit(40)
                    ->wrap()
                    ->tooltip(fn (Volume $record): string => $record->goal),
                TextColumn::make('climax')
                    ->label('本卷高潮')
                    ->limit(40)
                    ->wrap()
                    ->tooltip(fn (Volume $record): string => $record->climax),
                TextColumn::make('target_words')
                    ->label('目标字数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
                TextColumn::make('progress')
                    ->label('进度')
                    ->state('0%')
                    ->alignEnd()
                    ->tooltip('章节数据尚未接入，当前按 0 字计算。'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(VolumeStatus::class),
            ])
            ->defaultSort('sequence')
            ->emptyStateHeading('尚未规划分卷')
            ->emptyStateDescription('创建第一卷，明确阶段目标、高潮和篇幅边界。')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->recordActions([
                EditAction::make()
                    ->label('编辑')
                    ->modalHeading('编辑分卷')
                    ->modalWidth('3xl'),
                DeleteAction::make()->label('删除'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('创建分卷')
                ->icon('heroicon-o-plus')
                ->modalHeading('创建分卷')
                ->modalWidth('3xl'),
        ];
    }
}
