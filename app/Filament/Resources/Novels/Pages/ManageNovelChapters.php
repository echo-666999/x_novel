<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\ChapterStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class ManageNovelChapters extends ManageRelatedRecords
{
    protected static string $resource = NovelResource::class;

    protected static string $relationship = 'chapters';

    protected static ?string $navigationLabel = '章节';

    protected static ?string $relationshipTitle = '章节';

    public function getTitle(): string
    {
        return '章节';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('计划章节')
                ->description('这里只建立章节槽位；完整 Chapter Plan 在后续步骤中维护。')
                ->columns([
                    'default' => 1,
                    'md' => 3,
                ])
                ->schema([
                    TextInput::make('sequence')
                        ->label('章节序号')
                        ->helperText('同一部小说内不可重复。')
                        ->integer()
                        ->minValue(1)
                        ->default(fn (): int => ((int) $this->getRecord()->chapters()->max('sequence')) + 1)
                        ->required()
                        ->unique(
                            table: Chapter::class,
                            column: 'sequence',
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('novel_id', $this->getRecord()->getKey()),
                        ),
                    TextInput::make('title')
                        ->label('章节标题')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),
                    Select::make('volume_id')
                        ->label('所属分卷')
                        ->options(fn (): array => $this->getRecord()->volumes()
                            ->get()
                            ->mapWithKeys(fn ($volume): array => [
                                $volume->getKey() => "第 {$volume->sequence} 卷 · {$volume->title}",
                            ])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->placeholder('暂不指定分卷'),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'volume:id,sequence,title',
                'latestStateVersion',
            ]))
            ->columns([
                TextColumn::make('sequence')
                    ->label('章节')
                    ->formatStateUsing(fn (int $state): string => "第 {$state} 章")
                    ->sortable(),
                TextColumn::make('title')
                    ->label('标题')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('volume.title')
                    ->label('分卷')
                    ->formatStateUsing(fn (string $state, Chapter $record): string => "第 {$record->volume->sequence} 卷 · {$state}")
                    ->placeholder('未指定'),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
                TextColumn::make('word_count')
                    ->label('字数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('review_decision')
                    ->label('Review')
                    ->state('尚未接入')
                    ->color('gray'),
                TextColumn::make('cost')
                    ->label('成本')
                    ->state('尚未接入')
                    ->color('gray')
                    ->alignEnd(),
                TextColumn::make('latestStateVersion.version')
                    ->label('State Version')
                    ->formatStateUsing(fn (int $state): string => 'v'.$state)
                    ->placeholder('—')
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(ChapterStatus::class),
                SelectFilter::make('volume_id')
                    ->label('所属分卷')
                    ->options(fn (): array => $this->getRecord()->volumes()
                        ->get()
                        ->mapWithKeys(fn ($volume): array => [
                            $volume->getKey() => "第 {$volume->sequence} 卷 · {$volume->title}",
                        ])
                        ->all()),
            ])
            ->defaultSort('sequence')
            ->recordAction(null)
            ->emptyStateHeading('尚未建立章节')
            ->emptyStateDescription('创建 planned Chapter 后，再为它补充完整章节计划。')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('创建计划章节')
                ->icon('heroicon-o-plus')
                ->successNotificationTitle('计划章节已创建'),
        ];
    }
}
