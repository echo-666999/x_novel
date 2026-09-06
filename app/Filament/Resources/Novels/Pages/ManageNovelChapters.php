<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\Enums\ChapterStatus;
use App\Enums\FactStatus;
use App\Enums\ForeshadowingStatus;
use App\Enums\PlanStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Services\PlanValidator;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
use Illuminate\Support\HtmlString;
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
                'latestPlan',
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
                TextColumn::make('latestPlan.status')
                    ->label('Plan')
                    ->badge()
                    ->placeholder('未建立'),
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
            ->recordActions([
                Action::make('viewChapter')
                    ->label('工作台')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Chapter $record): string => NovelResource::getUrl('chapter', [
                        'record' => $this->getRecord(),
                        'chapter' => $record,
                    ])),
                Action::make('managePlan')
                    ->label(fn (Chapter $record): string => $record->latestPlan === null ? '建立 Plan' : '编辑 Plan')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->modalHeading(fn (Chapter $record): string => "第 {$record->sequence} 章 · Chapter Plan")
                    ->modalDescription(fn (Chapter $record): string => $record->latestPlan === null
                        ? '手工填写可直接执行的章节计划，不会调用 AI。'
                        : '编辑当前 Plan 版本；保存不会启动生成流程。')
                    ->modalWidth('7xl')
                    ->slideOver()
                    ->fillForm(fn (Chapter $record): array => $this->chapterPlanFormData($record))
                    ->schema($this->chapterPlanSchema())
                    ->action(function (Chapter $record, array $data): void {
                        $data['required_facts'] = array_map('intval', $data['required_facts'] ?? []);
                        $data['due_foreshadowings'] = array_map('intval', $data['due_foreshadowings'] ?? []);

                        if ($record->latestPlan === null) {
                            $record->plans()->create(['version' => 1, ...$data]);
                        } else {
                            $record->latestPlan->update($data);
                        }

                        Notification::make()
                            ->title('Chapter Plan 已保存')
                            ->success()
                            ->send();
                    }),
                Action::make('syncScenes')
                    ->label('同步 Scenes')
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
                    ->label('查看 Scenes')
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
                ->successNotificationTitle('计划章节已创建'),
        ];
    }

    /** @return array<int, mixed> */
    private function chapterPlanSchema(): array
    {
        return [
            ...$this->planFindingsSchema(),
            Section::make('章节目标')
                ->description('说明本章为什么存在、推进哪条故事线，以及向读者兑现什么。')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Textarea::make('chapter_function')
                        ->label('章节功能')
                        ->rows(3)
                        ->required(),
                    Textarea::make('arc_contribution')
                        ->label('故事线贡献')
                        ->rows(3)
                        ->required(),
                    Textarea::make('reader_promise')
                        ->label('读者承诺')
                        ->rows(3)
                        ->required()
                        ->columnSpanFull(),
                ]),
            Section::make('叙事参数')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextInput::make('target_words')
                        ->label('目标字数')
                        ->integer()
                        ->minValue(1)
                        ->default(3_000)
                        ->required(),
                    Select::make('pov_character_id')
                        ->label('POV 角色')
                        ->options(fn (): array => $this->getRecord()->characters()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('status')
                        ->label('Plan 状态')
                        ->options(PlanStatus::class)
                        ->default(PlanStatus::Draft)
                        ->required(),
                    TextInput::make('tone')
                        ->label('语气')
                        ->maxLength(255)
                        ->required(),
                    TextInput::make('time_anchor')
                        ->label('时间锚点')
                        ->maxLength(255)
                        ->required(),
                    TextInput::make('hook_type')
                        ->label('钩子类型')
                        ->maxLength(255)
                        ->required(),
                ]),
            Section::make('内容边界')
                ->description('明确必须披露、允许暗示和本章禁止提前揭示的内容。')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    TagsInput::make('must_reveal')
                        ->label('必须揭示')
                        ->default([]),
                    TagsInput::make('may_hint')
                        ->label('可以暗示')
                        ->default([]),
                    TagsInput::make('must_not_reveal')
                        ->label('禁止揭示')
                        ->default([]),
                    TagsInput::make('forbidden_conflicts')
                        ->label('禁止冲突')
                        ->default([]),
                    Select::make('required_facts')
                        ->label('必需事实')
                        ->options(fn (): array => $this->factOptions())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                    Select::make('due_foreshadowings')
                        ->label('到期伏笔')
                        ->options(fn (): array => $this->getRecord()->foreshadowings()
                            ->whereNotIn('status', [ForeshadowingStatus::PaidOff->value, ForeshadowingStatus::Abandoned->value])
                            ->orderBy('due_to_chapter')
                            ->pluck('title', 'id')
                            ->all())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ]),
            Section::make('场景计划')
                ->description('按正文顺序拆分场景。每个场景都必须产生明确转折和结果。')
                ->schema([
                    Repeater::make('scene_plans')
                        ->label('Scenes')
                        ->schema([
                            Select::make('pov_character_id')
                                ->label('POV 角色')
                                ->options(fn (): array => $this->getRecord()->characters()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->placeholder('继承 Chapter Plan POV'),
                            TextInput::make('location')
                                ->label('地点')
                                ->maxLength(255),
                            TextInput::make('time_anchor')
                                ->label('时间锚点')
                                ->maxLength(255)
                                ->placeholder('继承 Chapter Plan 时间锚点'),
                            Textarea::make('goal')->label('目标')->rows(2)->required(),
                            Textarea::make('conflict')->label('冲突')->rows(2)->required(),
                            Textarea::make('turn')->label('转折')->rows(2)->required(),
                            Textarea::make('outcome')->label('结果')->rows(2)->required(),
                        ])
                        ->columns(['default' => 1, 'lg' => 2])
                        ->minItems(1)
                        ->defaultItems(1)
                        ->reorderable()
                        ->addActionLabel('添加场景')
                        ->required(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function planFindingsSchema(): array
    {
        return [
            Section::make('Plan Findings')
                ->description('Blocked 必须修复后才能进入生成；Warning 需要人工确认。')
                ->schema([
                    Placeholder::make('validation_status')
                        ->label('校验状态')
                        ->content(fn (?Chapter $record): string => $record?->latestPlan === null
                            ? '尚未保存 Plan'
                            : app(PlanValidator::class)->validate($record->latestPlan)->status()->getLabel()),
                    Placeholder::make('validation_findings')
                        ->label('Findings')
                        ->content(function (?Chapter $record): HtmlString|string {
                            if ($record?->latestPlan === null) {
                                return '保存 Plan 后显示检查结果。';
                            }

                            $result = app(PlanValidator::class)->validate($record->latestPlan);

                            if ($result->findings === []) {
                                return '未发现阻塞或警告。';
                            }

                            return new HtmlString(collect($result->findings)
                                ->map(fn ($finding): string => '<strong>'.e($finding->severity->getLabel()).'</strong> · <code>'.e($finding->code).'</code><br>'.e($finding->message))
                                ->implode('<br><br>'));
                        }),
                ]),
        ];
    }

    /** @return array<string, mixed> */
    private function chapterPlanFormData(Chapter $chapter): array
    {
        $plan = $chapter->latestPlan;

        if ($plan === null) {
            return [
                'target_words' => 3_000,
                'must_reveal' => [],
                'may_hint' => [],
                'must_not_reveal' => [],
                'required_facts' => [],
                'forbidden_conflicts' => [],
                'due_foreshadowings' => [],
                'scene_plans' => [[]],
                'status' => PlanStatus::Draft->value,
            ];
        }

        return $plan->only([
            'chapter_function',
            'arc_contribution',
            'reader_promise',
            'target_words',
            'pov_character_id',
            'tone',
            'time_anchor',
            'hook_type',
            'must_reveal',
            'may_hint',
            'must_not_reveal',
            'required_facts',
            'forbidden_conflicts',
            'due_foreshadowings',
            'scene_plans',
            'status',
        ]);
    }

    /** @return array<int, string> */
    private function factOptions(): array
    {
        return $this->getRecord()->facts()
            ->where('status', FactStatus::Active)
            ->with(['characterSubject', 'worldEntitySubject'])
            ->get()
            ->mapWithKeys(fn ($fact): array => [
                $fact->getKey() => $fact->subjectLabel().' · '.$fact->predicate.' · '.$fact->valueSummary(),
            ])
            ->all();
    }
}
