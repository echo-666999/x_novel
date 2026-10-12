<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Chapters\DeleteChapterRangeAction;
use App\Actions\Chapters\SaveManualChapterPlanAction;
use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\AI\AiSettingsService;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Support\ChapterPlanEditorSchema;
use App\Models\Chapter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
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

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

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
        return $schema
            ->columns(1)
            ->components([
                Section::make('计划章节')
                    ->description('这里只建立章节槽位；完整章节计划在后续步骤中维护。')
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'volume:id,sequence,title',
                    'latestStateVersion',
                    'latestPlan',
                    'latestReview',
                ])
                ->withSum('usageRecords', 'estimated_cost'))
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
                TextColumn::make('latestPlan.status')
                    ->label('章节计划')
                    ->badge()
                    ->placeholder('未建立'),
                TextColumn::make('word_count')
                    ->label('字数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('latestReview.decision')
                    ->label('审校')
                    ->badge()
                    ->placeholder('未审校'),
                TextColumn::make('usage_records_sum_estimated_cost')
                    ->label('成本')
                    ->formatStateUsing(fn (mixed $state): string => data_get(app(AiSettingsService::class)->costSettings(), 'currency', 'USD').' '.number_format((float) $state, 4))
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('latestStateVersion.version')
                    ->label('故事版本')
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
            ->recordActions([
                Action::make('viewChapter')
                    ->label('工作台')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Chapter $record): string => NovelResource::getUrl('chapter', [
                        'record' => $this->getRecord(),
                        'chapter' => $record,
                    ])),
                Action::make('managePlan')
                    ->label(fn (Chapter $record): string => $record->latestPlan === null ? '建立计划' : '编辑计划')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->modalHeading(fn (Chapter $record): string => "第 {$record->sequence} 章 · Chapter Plan")
                    ->modalDescription(fn (Chapter $record): string => $record->latestPlan === null
                        ? '手工填写可直接执行的章节计划，不会调用 AI。'
                        : '保存会创建新的 Plan Version；若下游 Scene 已生成，将从最早受影响 Scene 开始级联重建。')
                    ->modalWidth('7xl')
                    ->slideOver()
                    ->fillForm(fn (Chapter $record): array => app(ChapterPlanEditorSchema::class)->formData($record))
                    ->schema(fn (): array => app(ChapterPlanEditorSchema::class)->schema(
                        $this->getRecord(),
                        fn (): ?Chapter => $this->getMountedAction()?->getRecord(),
                    ))
                    ->action(function (
                        Chapter $record,
                        array $data,
                        SaveManualChapterPlanAction $savePlan,
                    ): void {
                        $result = $savePlan->handle($record, $data, auth()->id());

                        Notification::make()
                            ->title($result['status'] === 'reused'
                                ? 'Chapter Plan 输入未变化，已复用当前 Ready Plan'
                                : 'Chapter Plan 已保存')
                            ->success()
                            ->send();
                    }),
                Action::make('syncScenes')
                    ->label('同步场景')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (Chapter $record): bool => $record->latestPlan !== null)
                    ->requiresConfirmation()
                    ->modalHeading('从当前 Plan 同步 Scenes？')
                    ->modalDescription('将按 Scene Plan 顺序初始化或更新尚未生成的 Scenes。已进入生成流程的 Scene 不会被覆盖。')
                    ->action(function (Chapter $record, SyncScenesFromChapterPlanAction $syncScenes): void {
                        $count = $syncScenes->execute($record);

                        Notification::make()
                            ->title("已同步 {$count} 个 Scenes")
                            ->success()
                            ->send();
                    }),
                Action::make('previewPlan')
                    ->label('预览')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (Chapter $record): bool => $record->latestPlan !== null)
                    ->url(fn (Chapter $record): string => NovelResource::getUrl('planning-preview', [
                        'record' => $this->getRecord(),
                        'chapter' => $record,
                    ])),
                Action::make('viewScenes')
                    ->label('查看场景')
                    ->icon('heroicon-o-list-bullet')
                    ->visible(fn (Chapter $record): bool => $record->scenes()->exists())
                    ->modalHeading(fn (Chapter $record): string => "第 {$record->sequence} 章 · Scenes")
                    ->modalDescription('这里展示从当前 Chapter Plan 同步出的有序场景。')
                    ->modalWidth('7xl')
                    ->slideOver()
                    ->modalSubmitAction(false)
                    ->fillForm(fn (Chapter $record): array => [
                        'scenes' => $record->scenes()
                            ->with('povCharacter:id,name')
                            ->get()
                            ->map(fn ($scene): array => [
                                'sequence' => $scene->sequence,
                                'goal' => $scene->goal,
                                'conflict' => $scene->conflict,
                                'turn' => $scene->turn,
                                'outcome' => $scene->outcome,
                                'pov' => $scene->povCharacter?->name,
                                'location' => $scene->location,
                                'time_anchor' => $scene->time_anchor,
                                'status' => $scene->status->getLabel(),
                            ])
                            ->all(),
                    ])
                    ->schema([
                        Repeater::make('scenes')
                            ->label('Scenes')
                            ->schema([
                                TextInput::make('sequence')->label('序号'),
                                TextInput::make('status')->label('状态'),
                                TextInput::make('pov')->label('POV'),
                                TextInput::make('location')->label('地点'),
                                TextInput::make('time_anchor')->label('时间锚点'),
                                Textarea::make('goal')->label('目标')->rows(2),
                                Textarea::make('conflict')->label('冲突')->rows(2),
                                Textarea::make('turn')->label('转折')->rows(2),
                                Textarea::make('outcome')->label('结果')->rows(2),
                            ])
                            ->columns(['default' => 1, 'lg' => 3])
                            ->disabled()
                            ->deletable(false)
                            ->addable(false)
                            ->reorderable(false),
                    ]),
                Action::make('deleteChapterRange')
                    ->label('从本章起删除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->disabled(fn (): bool => $this->getRecord()->status !== NovelStatus::Paused)
                    ->tooltip(fn (): ?string => $this->getRecord()->status !== NovelStatus::Paused
                        ? '必须先暂停小说，并确认没有排队中或运行中的 Generation Run。'
                        : null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Chapter $record): string => "从第 {$record->sequence} 章起物理删除？")
                    ->modalDescription('本章及全部后续章节都会删除，并恢复到前一正式章节的故事状态。成功后只能从数据库备份恢复。')
                    ->modalSubmitActionLabel('确认删除本章及全部后续章节')
                    ->modalWidth('3xl')
                    ->fillForm(fn (Chapter $record, DeleteChapterRangeAction $delete): array => [
                        'impact' => $delete->impact($record),
                        'reason' => null,
                    ])
                    ->schema([
                        Section::make('删除影响预览')
                            ->description('Outline 的 Volume / Arc / Beat / Milestone 定义会保留；下列运行时数据将物理删除或重建。')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('impact.range')->label('章节范围')->columnSpanFull(),
                                TextEntry::make('impact.state')->label('Canonical State'),
                                TextEntry::make('impact.chapters')->label('章节'),
                                TextEntry::make('impact.scenes')->label('Scenes'),
                                TextEntry::make('impact.plans')->label('Plans'),
                                TextEntry::make('impact.runs')->label('Runs'),
                                TextEntry::make('impact.artifacts')->label('Artifacts'),
                                TextEntry::make('impact.reviews')->label('Reviews'),
                                TextEntry::make('impact.usage')->label('Usage'),
                                TextEntry::make('impact.events')->label('Events'),
                                TextEntry::make('impact.facts')->label('Facts'),
                                TextEntry::make('impact.memories')->label('Memories'),
                                TextEntry::make('impact.characters')->label('新人物'),
                                TextEntry::make('impact.world_entities')->label('新世界实体'),
                                TextEntry::make('impact.foreshadowing_projections')->label('伏笔投影'),
                            ]),
                        Textarea::make('reason')
                            ->label('删除原因')
                            ->helperText('原因会写入小说操作记录；删除成功后不可在界面撤销。')
                            ->required()
                            ->maxLength(2000)
                            ->rows(3),
                    ])
                    ->action(function (Chapter $record, array $data, DeleteChapterRangeAction $delete): void {
                        $result = $delete->execute($record, (string) $data['reason'], auth()->id());

                        Notification::make()
                            ->title($result['status'] === 'already_deleted'
                                ? '该章节范围已删除'
                                : "已删除 {$result['chapters']} 个章节并完成状态重建")
                            ->success()
                            ->send();

                        $this->resetTable();
                    }),
            ])
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
                ->modalHeading('创建计划章节')
                ->modalWidth('3xl')
                ->successNotificationTitle('计划章节已创建'),
        ];
    }
}
