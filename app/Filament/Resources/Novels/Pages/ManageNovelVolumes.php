<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Volume;
use App\Services\VolumeCompletionGate;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class ManageNovelVolumes extends ManageRelatedRecords
{
    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

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
                            ->disableOptionWhen(fn (string $value, ?Volume $record): bool => $value === VolumeStatus::Completed->value
                                && $record?->status !== VolumeStatus::Completed)
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
                ViewAction::make('completionChecklist')
                    ->label('完成检查')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->modalHeading(fn (Volume $record): string => $record->title.' · Completion Checklist')
                    ->modalDescription('PASS 表示已满足；WARNING 需要人工确认；BLOCK 必须处理后才能完成分卷。')
                    ->modalWidth('2xl')
                    ->slideOver()
                    ->modalCancelActionLabel('关闭')
                    ->schema([
                        RepeatableEntry::make('completion_checks')
                            ->hiddenLabel()
                            ->state(fn (Volume $record): array => app(VolumeCompletionGate::class)->evaluate($record)->checks)
                            ->schema([
                                TextEntry::make('label')->label('检查项')->weight('medium'),
                                TextEntry::make('status')
                                    ->label('结果')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'PASS' => 'success',
                                        'WARNING' => 'warning',
                                        default => 'danger',
                                    }),
                                TextEntry::make('message')->label('说明')->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
                Action::make('completeVolume')
                    ->label('完成分卷')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Volume $record): bool => $record->status === VolumeStatus::Active)
                    ->disabled(fn (Volume $record): bool => ! app(VolumeCompletionGate::class)->evaluate($record)->canComplete())
                    ->tooltip(fn (Volume $record): ?string => app(VolumeCompletionGate::class)->evaluate($record)->canComplete()
                        ? null
                        : 'Completion Checklist 仍有 BLOCK 项。')
                    ->requiresConfirmation()
                    ->modalHeading('完成当前分卷')
                    ->modalDescription('确认后分卷状态将变为已完成；WARNING 项请在操作前人工确认。')
                    ->action(function (Volume $record, VolumeCompletionGate $gate): void {
                        $gate->complete($record);
                        Notification::make()->title('分卷已完成')->success()->send();
                    }),
                EditAction::make()
                    ->label('编辑')
                    ->modalHeading('编辑分卷')
                    ->modalWidth('3xl')
                    ->mutateDataUsing(function (array $data, Volume $record): array {
                        if (($data['status'] ?? null) === VolumeStatus::Completed->value
                            && $record->status !== VolumeStatus::Completed) {
                            throw ValidationException::withMessages(['status' => '请通过 Completion Checklist 完成分卷。']);
                        }

                        return $data;
                    }),
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
                ->modalWidth('3xl')
                ->mutateDataUsing(function (array $data): array {
                    if (($data['status'] ?? null) === VolumeStatus::Completed->value) {
                        throw ValidationException::withMessages(['status' => '新分卷不能直接标记为已完成。']);
                    }

                    return $data;
                }),
        ];
    }
}
