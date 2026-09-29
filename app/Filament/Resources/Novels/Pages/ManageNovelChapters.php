<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Chapters\DeleteChapterRangeAction;
use App\Actions\Chapters\InvalidateChapterPlanDownstreamAction;
use App\Actions\Chapters\RegenerateSceneSequenceAction;
use App\Actions\Chapters\SyncScenesFromChapterPlanAction;
use App\AI\AiSettingsService;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\FactStatus;
use App\Enums\ForeshadowingPlanAction;
use App\Enums\ForeshadowingTimingStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\PlanStatus;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Foreshadowing;
use App\Models\GenerationRun;
use App\Services\ForeshadowingLifecycleResolver;
use App\Services\OutlineProgressResolver;
use App\Services\PlanAdmissionService;
use App\Services\PlanValidator;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

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
                    ->fillForm(fn (Chapter $record): array => $this->chapterPlanFormData($record))
                    ->schema($this->chapterPlanSchema())
                    ->action(function (
                        Chapter $record,
                        array $data,
                        PlanAdmissionService $planAdmission,
                        InvalidateChapterPlanDownstreamAction $invalidatePlanDownstream,
                        SyncScenesFromChapterPlanAction $syncScenes,
                        RegenerateSceneSequenceAction $regenerateScenes,
                    ): void {
                        $currentPlan = $record->latestPlan()->first();
                        // 手工计划也必须冻结 Current Outline 的完整父链，不能创建空来源或由用户提交数据库 ID。
                        $target = app(OutlineProgressResolver::class)->resolve($record->novel);
                        if ($target === null) {
                            throw ValidationException::withMessages([
                                'plan' => 'Current Outline 没有可规划的 Main Beat/Milestone。',
                            ]);
                        }
                        $data['novel_outline_id'] = $target->outlineId;
                        $data['primary_outline_arc_id'] = $target->outlineArcId;
                        $data['primary_outline_beat_id'] = $target->outlineBeatId;
                        $data['primary_outline_milestone_id'] = $target->outlineMilestoneId;
                        $data['arc_contributions'] = [[
                            'role' => 'primary',
                            'arc_id' => $target->arcId,
                            'beat_key' => $target->beat['key'],
                            'beat_index' => $target->beat['sequence'],
                            'milestone_key' => $target->milestone['key'],
                            'milestone_sequence' => $target->milestone['sequence'],
                            'target_scene_sequence' => 1,
                            'acceptance_criteria' => $target->milestone['acceptance_criteria'][0],
                        ]];
                        $data['must_reveal'] = array_values(array_unique([
                            ...($data['must_reveal'] ?? []),
                            ...($target->milestone['must_include'] ?? []),
                        ]));
                        $data['must_not_reveal'] = array_values(array_unique([
                            ...($data['must_not_reveal'] ?? []),
                            ...($target->beat['must_not_include'] ?? []),
                            ...($target->milestone['must_not_include'] ?? []),
                        ]));
                        $data['scene_plans'] = collect($data['scene_plans'] ?? [])->map(
                            fn (array $scene): array => [
                                ...$scene,
                                'outcome_allowed' => array_values(array_filter($scene['outcome_allowed'] ?? [], 'is_string')),
                                'outcome_forbidden' => array_values(array_filter($scene['outcome_forbidden'] ?? [], 'is_string')),
                                'continuity_requirements' => array_values(array_filter($scene['continuity_requirements'] ?? [], 'is_array')),
                                'transition_from_previous' => $scene['transition_from_previous'] ?? null,
                            ],
                        )->values()->all();
                        $data['required_facts'] = array_map('intval', $data['required_facts'] ?? []);
                        $data['character_candidates'] = $currentPlan?->character_candidates ?? [];
                        $data['world_entity_candidates'] = $currentPlan?->world_entity_candidates ?? [];
                        // The prior Plan version preserves legacy IDs; every newly saved version uses contracts only.
                        $data['due_foreshadowings'] = [];
                        $data['foreshadowing_actions'] = $this->authorizedForeshadowingActions(
                            $data['foreshadowing_actions'] ?? [],
                            $record,
                        );
                        $version = ((int) $record->plans()->max('version')) + 1;
                        $candidate = new ChapterPlan(['version' => $version, ...$data]);
                        $candidate->setRelation('chapter', $record);
                        app(PlanValidator::class)->validate($candidate)->assertCanGenerate();
                        $admission = $planAdmission->prepare($candidate);
                        $reusable = $planAdmission->reusableReadyPlan($record, $admission['input_hash']);

                        if ($reusable !== null) {
                            Notification::make()
                                ->title('Chapter Plan 输入未变化，已复用当前 Ready Plan')
                                ->success()
                                ->send();

                            return;
                        }

                        $fromSceneSequence = $this->earliestAffectedSceneSequence($currentPlan, $candidate);
                        $hadGeneratedDownstream = $currentPlan !== null && $record->scenes()
                            ->where('sequence', '>=', $fromSceneSequence)
                            ->where(fn ($query) => $query
                                ->where('status', '!=', SceneStatus::Planned->value)
                                ->orWhereNotNull('current_artifact_id'))
                            ->exists();
                        if ($hadGeneratedDownstream && $record->generationRuns()
                            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
                            ->exists()) {
                            throw ValidationException::withMessages([
                                'plan' => '当前章节仍有排队中或运行中的任务，请等待结束后再修订 Plan。',
                            ]);
                        }

                        DB::transaction(function () use ($record, $data, $version, $admission, $invalidatePlanDownstream, $syncScenes, $fromSceneSequence): void {
                            $record->plans()->where('status', PlanStatus::Ready)->update(['status' => PlanStatus::Superseded]);
                            $plan = $record->plans()->create(['version' => $version, ...$data, ...$admission]);
                            $attempt = (int) $record->generationRuns()->where('stage', GenerationStage::ChapterPlanning)->max('attempt') + 1;
                            $run = GenerationRun::query()->create([
                                'novel_id' => $record->novel_id,
                                'chapter_id' => $record->getKey(),
                                'scope_type' => 'chapter',
                                'scope_id' => $record->getKey(),
                                'stage' => GenerationStage::ChapterPlanning,
                                'status' => RunStatus::Succeeded,
                                'attempt' => $attempt,
                                'idempotency_key' => 'plan:manual:'.$record->getKey().':v'.$version.':'.$admission['input_hash'],
                                'input_hash' => $admission['input_hash'],
                                'state_version' => data_get($admission, 'admission_snapshot.state_version'),
                                'bible_version' => data_get($admission, 'admission_snapshot.bible_version'),
                                'prompt_version' => 'manual-chapter-plan-v1',
                                'model_policy' => 'manual',
                                'context_snapshot' => [
                                    'manual_plan' => true,
                                    'chapter_plan_id' => $plan->getKey(),
                                    'chapter_plan_version' => $plan->version,
                                    'from_scene_sequence' => $fromSceneSequence,
                                ],
                                'started_at' => now(),
                                'finished_at' => now(),
                            ]);
                            $run->artifacts()->create([
                                'type' => ArtifactType::ChapterPlan,
                                'version' => $plan->version,
                                'data' => [
                                    'manual_plan' => true,
                                    'chapter_plan_id' => $plan->getKey(),
                                    'semantic_payload' => $plan->semanticPayload(),
                                ],
                                'checksum' => $plan->checksum,
                            ]);
                            $invalidatePlanDownstream->execute($record, $fromSceneSequence);
                            $syncScenes->execute($record, fromSceneSequence: $fromSceneSequence);
                        });

                        if ($hadGeneratedDownstream) {
                            $scene = $record->scenes()->where('sequence', $fromSceneSequence)->first();
                            if ($scene !== null) {
                                $regenerateScenes->handle($scene);
                            }
                        }

                        Notification::make()
                            ->title('Chapter Plan 已保存')
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

    private function earliestAffectedSceneSequence(?ChapterPlan $current, ChapterPlan $candidate): int
    {
        if ($current === null) {
            return 1;
        }

        $currentPayload = $current->semanticPayload();
        $candidatePayload = $candidate->semanticPayload();
        $currentScenes = array_values($currentPayload['scene_plans'] ?? []);
        $candidateScenes = array_values($candidatePayload['scene_plans'] ?? []);
        unset($currentPayload['scene_plans'], $candidatePayload['scene_plans']);

        if ($currentPayload !== $candidatePayload) {
            return 1;
        }

        $maximum = max(count($currentScenes), count($candidateScenes));
        for ($index = 0; $index < $maximum; $index++) {
            if (($currentScenes[$index] ?? null) !== ($candidateScenes[$index] ?? null)) {
                return $index + 1;
            }
        }

        return 1;
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
                    Repeater::make('foreshadowing_actions')
                        ->label('伏笔动作契约')
                        ->helperText('延期或放弃由当前登录用户明确授权，必须填写原因；延期还必须填写晚于原窗口的新兑现窗口。')
                        ->columns(['default' => 1, 'lg' => 2])
                        ->schema([
                            Select::make('foreshadowing_id')
                                ->label('伏笔')
                                ->options(fn (): array => $this->dueForeshadowingOptions())
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('action')
                                ->label('动作')
                                ->options(ForeshadowingPlanAction::options())
                                ->required(),
                            TextInput::make('target_scene_sequence')
                                ->label('目标 Scene 序号')
                                ->integer()
                                ->minValue(1)
                                ->required(),
                            Textarea::make('acceptance_criteria')
                                ->label('正文验收条件')
                                ->rows(2)
                                ->required(),
                            Textarea::make('reason')
                                ->label('延期/放弃原因')
                                ->rows(2)
                                ->helperText('plant、reinforce、pay_off 可留空。'),
                            TextInput::make('new_due_from_chapter')
                                ->label('延期后窗口开始章')
                                ->integer()
                                ->minValue(1),
                            TextInput::make('new_due_to_chapter')
                                ->label('延期后窗口结束章')
                                ->integer()
                                ->minValue(1),
                        ])
                        ->default([])
                        ->columnSpanFull(),
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
                            Textarea::make('transition_from_previous')
                                ->label('与上一段的衔接')
                                ->helperText('第一场景说明如何承接上一章正式结尾；后续场景说明如何承接前一场景。')
                                ->rows(2),
                            Textarea::make('goal')->label('目标')->rows(2)->required(),
                            Textarea::make('conflict')->label('冲突')->rows(2)->required(),
                            Textarea::make('turn')->label('转折')->rows(2)->required(),
                            Textarea::make('outcome')->label('结果')->rows(2)->required(),
                            TagsInput::make('outcome_allowed')
                                ->label('结果允许行为')
                                ->helperText('列出符合该结果的具体行为；没有时留空。')
                                ->default([]),
                            TagsInput::make('outcome_forbidden')
                                ->label('结果禁止行为')
                                ->helperText('列出会反转或越过该结果的具体行为；没有时留空。')
                                ->default([]),
                            Repeater::make('continuity_requirements')
                                ->label('跨场景连续性契约')
                                ->helperText('使用稳定 key 表达同一持续状态；首次建立、后续增量、真实变化和章末回扣分别选择对应模式。')
                                ->schema([
                                    TextInput::make('key')
                                        ->label('状态 Key')
                                        ->required(),
                                    Select::make('mode')
                                        ->label('模式')
                                        ->options([
                                            'establish' => '首次建立',
                                            'persist' => '持续状态',
                                            'change' => '状态变化',
                                            'callback' => '章末回扣',
                                        ])
                                        ->required(),
                                    Textarea::make('description')
                                        ->label('本场要求')
                                        ->rows(2)
                                        ->required(),
                                ])
                                ->columns(['default' => 1, 'lg' => 3])
                                ->default([])
                                ->columnSpanFull(),
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
                    TextEntry::make('validation_status')
                        ->label('校验状态')
                        ->state(fn (?Chapter $record): string => $record?->latestPlan === null
                            ? '尚未保存 Plan'
                            : app(PlanValidator::class)->validate($record->latestPlan)->status()->getLabel()),
                    TextEntry::make('validation_findings')
                        ->label('Findings')
                        ->state(function (?Chapter $record): HtmlString|string {
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
                'foreshadowing_actions' => [],
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
            'foreshadowing_actions',
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

    /** @return array<int, string> */
    private function dueForeshadowingOptions(): array
    {
        $chapter = $this->getMountedAction()?->getRecord();

        if (! $chapter instanceof Chapter) {
            return [];
        }

        $currentCanonicalChapter = $this->getRecord()->current_chapter_sequence;
        $targetChapter = Foreshadowing::nextChapterSequence($currentCanonicalChapter);
        $selectedIds = $chapter->latestPlan?->referencedForeshadowingIds() ?? [];
        $lifecycleResolver = app(ForeshadowingLifecycleResolver::class);
        $novel = $this->getRecord()->loadMissing('canonicalStateVersion');

        return $this->getRecord()->foreshadowings()
            ->where(function (Builder $query) use ($selectedIds, $targetChapter): void {
                $query->where('due_from_chapter', '<=', $targetChapter);

                if ($selectedIds !== []) {
                    $query->orWhereIn('id', $selectedIds);
                }
            })
            ->orderBy('due_to_chapter')
            ->get()
            ->reject(fn (Foreshadowing $foreshadowing): bool => ! in_array($foreshadowing->getKey(), $selectedIds, true)
                && $lifecycleResolver->status($foreshadowing, $novel)->isTerminal())
            ->mapWithKeys(function (Foreshadowing $foreshadowing) use ($lifecycleResolver, $novel, $targetChapter): array {
                $status = $lifecycleResolver->status($foreshadowing, $novel);
                $timing = ForeshadowingTimingStatus::forTargetChapter(
                    $status,
                    $foreshadowing->due_from_chapter,
                    $foreshadowing->due_to_chapter,
                    $targetChapter,
                );
                $timingLabel = $timing?->getLabel() ?? '已结束';

                if ($foreshadowing->requiresLegacyStatusMigration()) {
                    $timingLabel .= '；内容状态待迁移';
                }

                return [$foreshadowing->getKey() => "{$foreshadowing->title} · {$timingLabel}"];
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<int, array<string, mixed>>
     */
    private function authorizedForeshadowingActions(array $actions, Chapter $chapter): array
    {
        $chapter->loadMissing('novel.canonicalStateVersion');

        return collect($actions)
            ->map(function (array $contract) use ($chapter): array {
                $action = ForeshadowingPlanAction::tryFrom((string) ($contract['action'] ?? ''));

                if (! $action?->requiresUserAuthorization()) {
                    unset(
                        $contract['authorized_by_user_id'],
                        $contract['authorized_at'],
                        $contract['authorized_at_canonical_chapter'],
                        $contract['authorized_at_state_version'],
                    );

                    return $contract;
                }

                return [
                    ...$contract,
                    'authorized_by_user_id' => auth()->id(),
                    'authorized_at' => now()->toISOString(),
                    'authorized_at_canonical_chapter' => $chapter->novel->current_chapter_sequence ?? 0,
                    'authorized_at_state_version' => $chapter->novel->canonicalStateVersion?->version,
                ];
            })
            ->values()
            ->all();
    }
}
