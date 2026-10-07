<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Chapters\RestartChapterFromOutlineAction;
use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\CreateNovelOutlineVersionAction;
use App\Actions\Novels\ResumeNovelOutlineGenerationAction;
use App\Actions\Novels\ReviseNovelOutlineNodeAction;
use App\Actions\Novels\StartNovelOutlineGenerationAction;
use App\AI\Exceptions\AiProviderException;
use App\Data\NormalizedNovelOutline;
use App\Data\NovelOutlineProgress;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\RunStatus;
use App\Enums\StoryArcType;
use App\Enums\WorldEntityType;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\NovelOutline;
use App\Models\NovelOutlineArc;
use App\Models\NovelOutlineBeat;
use App\Models\NovelOutlineMilestone;
use App\Models\NovelOutlineVolume;
use App\Services\NormalizedNovelOutlineValidator;
use App\Services\NovelOutlinePipeline;
use App\Services\NovelOutlineProgressResolver;
use App\Services\NovelOutlineStageContract;
use App\Services\NovelPlanner;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageNovelOutline extends ViewRecord
{
    protected ?NovelOutlineProgress $cachedOutlineGenerationProgress = null;

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $cachedOutlineRoutePreview = null;

    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = '全书大纲';

    public function getTitle(): string
    {
        return '全书大纲';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.novels.pages.outline-builder')
                ->key('outline-generation-workspace')
                ->viewData(function (): array {
                    $outline = $this->displayedOutline();

                    return [
                        'outline' => $outline,
                        'versions' => $this->getRecord()->outlines()->get(),
                        'validation' => $outline === null
                        ? null
                        : app(NormalizedNovelOutlineValidator::class)->validate(NormalizedNovelOutline::fromModel($outline)),
                        'outlineGeneration' => $this->outlineGenerationProgress(),
                        'outlineGenerationElapsed' => $this->outlineGenerationElapsed(),
                    ];
                }),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // 只生成可审阅的 Draft 候选；正式规划表要等用户点击“确认采用”后才写入。
            Action::make('generateOutlineCandidate')
                ->label(fn (): string => match ($this->outlineGenerationProgress()->pageStatus) {
                    'queued', 'running' => '生成中',
                    'retrying' => '等待重试',
                    'cancelled' => '重新生成候选',
                    'failed' => '重新生成候选',
                    default => 'AI 生成候选',
                })
                ->icon('heroicon-o-sparkles')
                ->visible(function (): bool {
                    $progress = $this->outlineGenerationProgress();

                    return $this->getRecord()->current_outline_id === null
                        && ! $this->hasFormalStructure()
                        && (in_array($progress->pageStatus, ['not_started', 'queued', 'running', 'retrying', 'cancelled'], true)
                            || ($progress->pageStatus === 'failed' && ! $progress->canResume));
                })
                ->disabled(fn (): bool => $this->outlineGenerationProgress()->isActive())
                ->tooltip(fn (): ?string => $this->outlineGenerationProgress()->isActive()
                    ? '当前批次仍在处理，页面会自动刷新持久化进度。'
                    : null)
                ->schema([
                    TextInput::make('volume_count')->label('预计分卷数')->integer()->minValue(1)->maxValue(12)->default(5)->required(),
                    Section::make('启动前路由预览')
                        ->description('以下结果按当前小说 Override、全局模型路由和环境回退实时解析；创建批次后会逐任务冻结。')
                        ->schema(collect($this->outlineAiStages())->map(fn (AiStage $stage): TextEntry => TextEntry::make("outline_route_preview.{$stage->value}")
                            ->label($stage->getLabel())
                            ->state(fn (): string => $this->outlineRoutePreviewText($stage))
                            ->color(fn (): string => data_get($this->outlineRoutePreview(), "{$stage->value}.ready") === true ? 'success' : 'danger')
                            ->columnSpanFull())->all()),
                ])
                ->action(function (array $data, StartNovelOutlineGenerationAction $start): void {
                    try {
                        $batch = $start->handle($this->getRecord(), (int) $data['volume_count']);
                    } catch (Throwable $exception) {
                        $this->forgetOutlineGenerationProgress();
                        $message = match (true) {
                            $exception instanceof ValidationException => collect($exception->errors())->flatten()->first(),
                            $exception instanceof AiProviderException => $exception->getMessage(),
                            default => '创建或投递规划批次失败，请查看日志后重试。',
                        };
                        Notification::make()
                            ->title('无法生成大纲候选')
                            ->body($message)
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->forgetOutlineGenerationProgress();
                    Notification::make()
                        ->title('AI 大纲候选已加入生成队列')
                        ->body("规划批次 #{$batch->getKey()} 已持久化，可安全等待后台处理。")
                        ->success()
                        ->send();
                }),
            Action::make('resumeOutlineGeneration')
                ->label('继续 AI 生成')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->current_outline_id === null
                    && ! $this->hasFormalStructure()
                    && $this->outlineGenerationProgress()->pageStatus === 'failed'
                    && $this->outlineGenerationProgress()->canResume)
                ->requiresConfirmation()
                ->modalHeading('从最近成功阶段继续生成')
                ->modalDescription('系统会复用来源链仍然有效的成功 Artifact，并从最早缺失的阶段继续。')
                ->action(function (ResumeNovelOutlineGenerationAction $resume): void {
                    $progress = $this->outlineGenerationProgress();
                    $batch = $progress->batchId === null ? null : GenerationRun::query()->find($progress->batchId);

                    if ($batch === null) {
                        $this->forgetOutlineGenerationProgress();
                        Notification::make()->title('无法继续生成')->body('未找到对应的 Outline 主批次。')->danger()->send();

                        return;
                    }

                    try {
                        $resumed = $resume->handle($this->getRecord(), $batch);
                    } catch (Throwable $exception) {
                        $this->forgetOutlineGenerationProgress();
                        Notification::make()
                            ->title('无法继续生成')
                            ->body($this->outlineActionErrorMessage($exception))
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->forgetOutlineGenerationProgress();
                    Notification::make()
                        ->title('AI 大纲生成已继续')
                        ->body("规划批次 #{$resumed->getKey()} 已从持久化恢复点继续。")
                        ->success()
                        ->send();
                }),
            Action::make('viewOutlineGenerationRuns')
                ->label('查看运行详情')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->visible(fn (): bool => $this->outlineGenerationProgress()->batchId !== null)
                ->modalHeading('AI 大纲生成运行详情')
                ->modalDescription(fn (): string => 'Batch #'.$this->outlineGenerationProgress()->batchId)
                ->modalWidth('3xl')
                ->slideOver()
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('关闭')
                ->modalContent(fn () => view('filament.resources.novels.pages.partials.outline-generation-runs', [
                    'progress' => $this->outlineGenerationProgress(),
                ])),
            Action::make('saveManualOutline')
                ->label('手工创建')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => $this->getRecord()->current_outline_id === null
                    && ! $this->hasFormalStructure()
                    && $this->latestDraft() === null)
                ->modalHeading('手工创建全书大纲')
                ->modalDescription('保存会创建不可变 Draft Version。后续请直接编辑单个节点。')
                ->modalWidth('7xl')
                ->fillForm(fn (): array => $this->emptyOutline())
                ->schema(self::outlineForm())
                ->action(function (array $data, CreateNovelOutlineVersionAction $create): void {
                    $data = $this->synchronizeSequences($data);
                    $create->handle(
                        novel: $this->getRecord(),
                        outline: $data,
                        source: NovelOutlineSource::Manual,
                        creator: auth()->user(),
                    );
                    $this->getRecord()->refresh();
                    Notification::make()->title('大纲 Draft Version 已创建')->success()->send();
                }),
            Action::make('regenerateOutlineNode')
                ->label('局部重新生成')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->latestDraft() !== null && $this->latestBlueprintArtifact() !== null && ! $this->hasFormalStructure())
                ->schema([
                    Select::make('node_key')
                        ->label('目标节点')
                        ->options(function (NovelPlanner $planner): array {
                            $draft = $this->latestDraft();
                            $data = $draft === null ? [] : NormalizedNovelOutline::fromModel($draft)->toArray();

                            return collect($planner->nodeKeys($data))->mapWithKeys(fn (string $key): array => [$key => $key])->all();
                        })
                        ->searchable()
                        ->required(),
                    Textarea::make('instruction')->label('修改要求')->rows(5)->required()->maxLength(2000),
                ])
                ->action(function (array $data, NovelPlanner $planner): void {
                    try {
                        // 服务端会再次限制变更范围，不能只依赖 Prompt 要求模型保持其他节点不变。
                        $planner->regenerateNode(
                            $this->getRecord(),
                            $this->latestDraft(),
                            $this->latestBlueprintArtifact(),
                            $data['node_key'],
                            $data['instruction'],
                        );
                    } catch (Throwable $exception) {
                        Notification::make()->title('局部重新生成失败')->body($exception->getMessage())->danger()->send();

                        return;
                    }
                    $this->getRecord()->refresh();
                    Notification::make()->title('局部修订已保存为新 Draft Version')->success()->send();
                }),
            Action::make('applyOutline')
                ->label('确认采用')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->visible(fn (): bool => $this->latestDraft() !== null && ! $this->hasFormalStructure())
                ->requiresConfirmation()
                ->modalHeading('采用当前 Draft Outline')
                ->modalDescription('采用将在一个事务内创建正式分卷、故事线和初始状态。AI 候选还会创建其冻结 Artifact 中的 Bible、初始人物、世界资料和伏笔。')
                ->action(function (ApplyNovelBlueprintAction $apply): void {
                    $outline = $this->latestDraft();
                    try {
                        // 只有 AI 根版本需要附带 Blueprint Artifact；手工大纲使用已维护的正式资料。
                        $apply->handle(
                            $this->getRecord(),
                            $outline,
                            $this->aiRootArtifact($outline),
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()->title('无法采用大纲')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }
                    $this->getRecord()->refresh();
                    Notification::make()->title('Current Novel Outline 已采用')->success()->send();
                }),
            Action::make('restartChapterFromOutline')
                ->label('重建当前非正式章')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->currentNonCanonicalChapterNeedsRestart())
                ->requiresConfirmation()
                ->modalHeading('按 Current Outline 重建当前非正式章')
                ->modalDescription('旧 Chapter Plan、Generation Run、Artifact、Review 和 Usage 会完整保留。系统只替代当前执行指针，并从 Chapter Planning 创建新的来源链。')
                ->fillForm(function (): array {
                    $current = $this->getRecord()->currentOutline()->firstOrFail();

                    return [
                        'expected_outline_id' => $current->getKey(),
                        'expected_outline_checksum' => $current->checksum,
                    ];
                })
                ->schema([
                    Hidden::make('expected_outline_id')->required(),
                    Hidden::make('expected_outline_checksum')->required(),
                ])
                ->action(function (array $data, RestartChapterFromOutlineAction $restart): void {
                    try {
                        $result = $restart->handle(
                            novel: $this->getRecord(),
                            expectedOutlineId: (int) $data['expected_outline_id'],
                            expectedOutlineChecksum: (string) $data['expected_outline_checksum'],
                            actorId: auth()->id(),
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()->title('无法重建当前章')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    Notification::make()
                        ->title($result['dispatched'] ? '当前章已进入重新规划' : '当前章已重置，规划任务已存在')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * 页面内每个节点复用同一个短表单；稳定 Key 和层级关系只作为参数，不允许从表单修改。
     */
    public function editOutlineNodeAction(): Action
    {
        return Action::make('editOutlineNode')
            ->label('编辑')
            ->icon('heroicon-m-pencil-square')
            ->link()
            ->color('gray')
            ->disabled(fn (): bool => $this->outlineGenerationProgress()->isActive())
            ->tooltip(fn (): ?string => $this->outlineGenerationProgress()->isActive()
                ? 'AI 大纲批次仍在运行，结束后才能创建修订版本。'
                : null)
            ->modalHeading(fn (array $arguments): string => '编辑'.$this->outlineNodeTypeLabel((string) ($arguments['node_type'] ?? '')).' · '.($arguments['node_key'] ?? ''))
            ->modalDescription(function (array $arguments): string {
                $outline = $this->outlineForNodeArguments($arguments);

                return $outline->status === NovelOutlineStatus::Current
                    ? '只修改当前节点；保存会创建并采用新的不可变 Outline Version，从下一章生效。'
                    : '只修改当前节点；保存会创建新的不可变 Draft Version，不覆盖旧版本。';
            })
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('保存为新版本')
            ->fillForm(fn (array $arguments): array => $this->outlineNodeFormData($arguments))
            ->schema(fn (array $arguments): array => self::outlineNodeForm((string) ($arguments['node_type'] ?? '')))
            ->action(function (array $data, array $arguments, ReviseNovelOutlineNodeAction $revise): void {
                try {
                    $revision = $revise->handle(
                        novel: $this->getRecord(),
                        expectedOutlineId: (int) ($arguments['outline_id'] ?? 0),
                        expectedOutlineChecksum: (string) ($arguments['outline_checksum'] ?? ''),
                        nodeType: (string) ($arguments['node_type'] ?? ''),
                        nodeKey: (string) ($arguments['node_key'] ?? ''),
                        changes: $data,
                        creator: auth()->user(),
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('无法保存节点修订')
                        ->body((string) collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return;
                }

                $this->getRecord()->refresh();
                $unchanged = $revision->getKey() === (int) ($arguments['outline_id'] ?? 0);
                Notification::make()
                    ->title(match (true) {
                        $unchanged => '节点内容未发生变化',
                        $revision->status === NovelOutlineStatus::Current => '节点修订已从下一章生效',
                        default => '节点修订已保存为新 Draft Version',
                    })
                    ->success()
                    ->send();
            });
    }

    /** @return array<int, mixed> */
    private static function outlineNodeForm(string $nodeType): array
    {
        return match ($nodeType) {
            'volume' => [
                TextInput::make('title')->label('分卷名称')->required()->maxLength(NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH),
            ],
            'arc' => [
                TextInput::make('title')->label('Arc 标题')->required()->maxLength(NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH),
                Textarea::make('goal')->label('目标')->required()->rows(3)->maxLength(NovelOutlineStageContract::MAX_TEXT_LENGTH),
                Textarea::make('stakes')->label('风险 / 代价')->required()->rows(3)->maxLength(NovelOutlineStageContract::MAX_TEXT_LENGTH),
                self::outlineNodeTags('completion_conditions', '完成条件', true),
            ],
            'beat' => [
                TextInput::make('title')->label('Beat 标题')->required()->maxLength(NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH),
                Textarea::make('summary')->label('节点摘要')->required()->rows(4)->maxLength(NovelOutlineStageContract::MAX_TEXT_LENGTH),
                Section::make('章节预算')->columns(2)->schema([
                    TextInput::make('chapter_budget.min')->label('最少章节')->integer()->minValue(1)->required(),
                    TextInput::make('chapter_budget.max')->label('最多章节')->integer()->minValue(1)->nullable(),
                ]),
                self::outlineNodeTags('acceptance_criteria', '验收条件', true),
                self::outlineNodeTags('must_include', '必须包含'),
                self::outlineNodeTags('must_not_include', '禁止包含'),
            ],
            'milestone' => [
                TextInput::make('title')->label('Milestone 标题')->required()->maxLength(NovelOutlineStageContract::MAX_SHORT_TEXT_LENGTH),
                Textarea::make('objective')->label('阶段目标')->required()->rows(4)->maxLength(NovelOutlineStageContract::MAX_TEXT_LENGTH),
                self::outlineNodeTags('acceptance_criteria', '验收条件', true),
                self::outlineNodeTags('must_include', '必须包含'),
                self::outlineNodeTags('must_not_include', '禁止包含'),
            ],
            default => throw ValidationException::withMessages(['outline_node' => '不支持的 Outline 节点类型。']),
        };
    }

    private static function outlineNodeTags(string $name, string $label, bool $required = false): TagsInput
    {
        return TagsInput::make($name)
            ->label($label)
            ->required($required)
            ->rules(['array', ($required ? 'min:1' : 'min:0'), 'max:'.NovelOutlineStageContract::MAX_LIST_ITEMS])
            ->nestedRecursiveRules(['string', 'max:'.NovelOutlineStageContract::MAX_LIST_ITEM_LENGTH]);
    }

    /** @return array<string, mixed> */
    private function outlineNodeFormData(array $arguments): array
    {
        $node = $this->outlineNode($arguments);

        return match ((string) ($arguments['node_type'] ?? '')) {
            'volume' => ['title' => $node->title],
            'arc' => [
                'title' => $node->title,
                'goal' => $node->goal,
                'stakes' => $node->stakes,
                'completion_conditions' => $node->completion_conditions ?? [],
            ],
            'beat' => [
                'title' => $node->title,
                'summary' => $node->summary,
                'chapter_budget' => ['min' => $node->chapter_budget_min, 'max' => $node->chapter_budget_max],
                'acceptance_criteria' => $node->acceptance_criteria ?? [],
                'must_include' => $node->must_include ?? [],
                'must_not_include' => $node->must_not_include ?? [],
            ],
            'milestone' => [
                'title' => $node->title,
                'objective' => $node->objective,
                'acceptance_criteria' => $node->acceptance_criteria ?? [],
                'must_include' => $node->must_include ?? [],
                'must_not_include' => $node->must_not_include ?? [],
            ],
            default => throw ValidationException::withMessages(['outline_node' => '不支持的 Outline 节点类型。']),
        };
    }

    private function outlineNode(array $arguments): NovelOutlineVolume|NovelOutlineArc|NovelOutlineBeat|NovelOutlineMilestone
    {
        $outline = $this->outlineForNodeArguments($arguments);
        $nodeType = (string) ($arguments['node_type'] ?? '');
        $nodeKey = (string) ($arguments['node_key'] ?? '');
        $node = match ($nodeType) {
            'volume' => $outline->volumes()->where('volume_key', $nodeKey)->first(),
            'arc' => $outline->arcs()->where('arc_key', $nodeKey)->first(),
            'beat' => $outline->beats()->where('beat_key', $nodeKey)->first(),
            'milestone' => $outline->milestones()->where('milestone_key', $nodeKey)->first(),
            default => null,
        };

        if (! $node instanceof NovelOutlineVolume
            && ! $node instanceof NovelOutlineArc
            && ! $node instanceof NovelOutlineBeat
            && ! $node instanceof NovelOutlineMilestone) {
            throw ValidationException::withMessages(['outline_node' => "Outline 中不存在 {$nodeType} 节点 {$nodeKey}。"]);
        }

        return $node;
    }

    private function outlineForNodeArguments(array $arguments): NovelOutline
    {
        $outline = $this->getRecord()->outlines()->find((int) ($arguments['outline_id'] ?? 0));
        if ($outline === null || ! in_array($outline->status, [NovelOutlineStatus::Draft, NovelOutlineStatus::Current], true)) {
            throw ValidationException::withMessages(['outline' => '只能编辑当前 Draft 或 Current Outline。']);
        }

        return $outline;
    }

    private function outlineNodeTypeLabel(string $nodeType): string
    {
        return match ($nodeType) {
            'volume' => '分卷名称',
            'arc' => 'Arc',
            'beat' => 'Beat',
            'milestone' => 'Milestone',
            default => 'Outline 节点',
        };
    }

    /** @return array<int, AiStage> */
    private function outlineAiStages(): array
    {
        return [
            AiStage::OutlineFoundation,
            AiStage::OutlineStructure,
            AiStage::OutlineArcBeats,
            AiStage::OutlineBeatDetail,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function outlineRoutePreview(): array
    {
        return $this->cachedOutlineRoutePreview ??= app(NovelOutlinePipeline::class)
            ->outlineRoutePreview($this->getRecord());
    }

    private function outlineRoutePreviewText(AiStage $stage): string
    {
        $route = $this->outlineRoutePreview()[$stage->value] ?? null;
        if (! is_array($route)) {
            return '不可启动 · [outline_route_preview_missing] 未返回该任务的路由预览。';
        }
        if (($route['ready'] ?? false) !== true) {
            // 错误码与用户文案同时展示，便于从页面直接关联 Generation Run 和日志诊断。
            $errorCode = filled($route['error_code'] ?? null)
                ? '['.$route['error_code'].'] '
                : '';

            return '不可启动 · '.$errorCode.($route['error'] ?? '路由配置无效。');
        }

        $source = match ($route['source'] ?? null) {
            'novel' => '小说 Override',
            'database' => '数据库路由',
            default => '环境回退',
        };
        $reasoning = filled($route['reasoning_effort'] ?? null)
            ? (string) $route['reasoning_effort']
            : 'Provider 默认';
        $capacity = (array) ($route['model_capacity'] ?? []);
        $requestBudget = (array) ($route['request_budget'] ?? []);

        return sprintf(
            '可启动 · %s / %s · 推理 %s · %s · Prompt %s · 请求 %s + 推理预留 %s = %s · 容量 %s / %s',
            strtoupper((string) $route['provider']),
            $route['model'],
            $reasoning,
            $source,
            $route['prompt_version'],
            number_format((int) ($requestBudget['output_tokens'] ?? 0)),
            number_format((int) ($requestBudget['reasoning_reserve_tokens'] ?? 0)),
            number_format((int) ($requestBudget['max_completion_tokens'] ?? 0)),
            number_format((int) ($capacity['context_window_tokens'] ?? 0)),
            number_format((int) ($capacity['max_output_tokens'] ?? 0)),
        );
    }

    /** @return array<int, mixed> */
    private static function outlineForm(): array
    {
        return [
            Section::make('全书约束')->columns(2)->schema([
                TextInput::make('title')->label('大纲标题')->required()->maxLength(255),
                Textarea::make('summary')->label('全书摘要')->required()->rows(3)->columnSpanFull(),
                TagsInput::make('must_include')->label('必须包含')->default([]),
                TagsInput::make('must_not_include')->label('禁止包含')->default([]),
            ]),
            Repeater::make('volumes')
                ->label('Volumes')
                ->addActionLabel('添加 Volume')
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->schema([
                    TextInput::make('key')->label('稳定 Key')->required(),
                    TextInput::make('sequence')->label('顺序')->integer()->minValue(1)->required(),
                    TextInput::make('title')->label('标题')->required(),
                    TextInput::make('target_words')->label('目标字数')->integer()->minValue(1)->required(),
                    Textarea::make('goal')->label('目标')->required()->rows(2),
                    Textarea::make('climax')->label('高潮')->required()->rows(2),
                    Repeater::make('arcs')->label('Story Arcs')->addActionLabel('添加 Arc')->reorderable()->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                        ->schema([
                            TextInput::make('key')->label('稳定 Key')->required(),
                            TextInput::make('sequence')->label('顺序')->integer()->minValue(1)->required(),
                            Hidden::make('mainline_sequence'),
                            Select::make('type')->label('类型')->options(StoryArcType::class)->required(),
                            TextInput::make('title')->label('标题')->required(),
                            Textarea::make('goal')->label('目标')->required()->rows(2),
                            Textarea::make('stakes')->label('风险 / 代价')->required()->rows(2),
                            TagsInput::make('completion_conditions')->label('完成条件')->required(),
                            Repeater::make('beats')->label('Outline Beats')->addActionLabel('添加 Beat')->reorderable()->collapsible()
                                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                ->schema([
                                    TextInput::make('key')->label('稳定 Key')->required(),
                                    TextInput::make('sequence')->label('顺序')->integer()->minValue(1)->required(),
                                    Hidden::make('mainline_sequence'),
                                    TextInput::make('title')->label('标题')->required(),
                                    Textarea::make('summary')->label('节点摘要')->required()->rows(2),
                                    TextInput::make('chapter_budget.min')->label('最少章节')->integer()->minValue(1)->required(),
                                    TextInput::make('chapter_budget.max')->label('最多章节')->integer()->minValue(1)->nullable(),
                                    TagsInput::make('acceptance_criteria')->label('验收条件')->required(),
                                    TagsInput::make('must_include')->label('必须包含')->default([]),
                                    TagsInput::make('must_not_include')->label('禁止包含')->default([]),
                                    Repeater::make('character_candidates')->label('未来人物 Candidate')->default([])->collapsible()->schema([
                                        TextInput::make('candidate_key')->label('Candidate Key')->required(),
                                        TextInput::make('name')->label('名称')->required(),
                                        TextInput::make('role')->label('角色定位')->required(),
                                        TextInput::make('motivation')->label('动机')->required(),
                                        KeyValue::make('profile')->label('档案')->default([]),
                                        KeyValue::make('personality')->label('性格')->default([]),
                                        KeyValue::make('abilities')->label('能力')->default([]),
                                        KeyValue::make('knowledge')->label('知识')->default([]),
                                        Textarea::make('deduplication_basis')->label('去重依据')->required(),
                                        Hidden::make('possible_duplicate_character_ids')->default([]),
                                        Textarea::make('introduction_reason')->label('引入理由')->required(),
                                        TextInput::make('target_scene_sequence')->label('目标 Scene')->integer()->minValue(1)->required(),
                                    ]),
                                    Repeater::make('world_entity_candidates')->label('未来世界实体 Candidate')->default([])->collapsible()->schema([
                                        TextInput::make('candidate_key')->label('Candidate Key')->required(),
                                        Select::make('type')->label('类型')->options(WorldEntityType::class)->required(),
                                        TextInput::make('name')->label('名称')->required(),
                                        Textarea::make('description')->label('描述')->required(),
                                        Textarea::make('deduplication_basis')->label('去重依据')->required(),
                                        Hidden::make('possible_duplicate_entity_ids')->default([]),
                                        Textarea::make('introduction_reason')->label('引入理由')->required(),
                                        TextInput::make('target_scene_sequence')->label('目标 Scene')->integer()->minValue(1)->required(),
                                    ]),
                                    Repeater::make('milestones')->label('Main Beat Milestones')->default([])->collapsible()->reorderable()
                                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                        ->schema([
                                            TextInput::make('key')->label('稳定 Key')->required(),
                                            TextInput::make('sequence')->label('顺序')->integer()->minValue(1)->required(),
                                            TextInput::make('title')->label('标题')->required(),
                                            Textarea::make('objective')->label('阶段目标')->required()->rows(2),
                                            TagsInput::make('acceptance_criteria')->label('验收条件')->required(),
                                            TagsInput::make('must_include')->label('必须包含')->default([]),
                                            TagsInput::make('must_not_include')->label('禁止包含')->default([]),
                                        ])->columns(2),
                                    Section::make('相邻 Beat Handoff')->schema([
                                        Hidden::make('handoff.next_beat_key'),
                                        TextInput::make('handoff.transition_mode')->label('过渡方式')->nullable(),
                                        Textarea::make('handoff.exit_result')->label('前一 Beat 已成立结果')->rows(2)->nullable(),
                                        Textarea::make('handoff.next_trigger')->label('下一 Beat 直接触发')->rows(2)->nullable(),
                                        TagsInput::make('handoff.carried_states')->label('延续状态')->default([]),
                                        TagsInput::make('handoff.open_threads')->label('未结事项')->default([]),
                                        TagsInput::make('handoff.required_transition')->label('必须写出的过渡')->default([]),
                                        TagsInput::make('handoff.forbidden_jump')->label('禁止跳跃')->default([]),
                                    ])->columns(2),
                                ])->columns(2),
                        ])->columns(2),
                ])->columns(2),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyOutline(): array
    {
        return [
            'title' => $this->getRecord()->title.'全书大纲',
            'summary' => (string) $this->getRecord()->premise,
            'must_include' => [],
            'must_not_include' => [],
            'volumes' => [],
        ];
    }

    private function outlineGenerationProgress(): NovelOutlineProgress
    {
        return $this->cachedOutlineGenerationProgress ??= app(NovelOutlineProgressResolver::class)->resolve($this->getRecord());
    }

    private function forgetOutlineGenerationProgress(): void
    {
        $this->cachedOutlineGenerationProgress = null;
    }

    private function outlineGenerationElapsed(): ?string
    {
        $progress = $this->outlineGenerationProgress();
        $milliseconds = $progress->currentRunDurationMilliseconds;
        if ($milliseconds === null && $progress->currentRunStartedAt !== null && $progress->isActive()) {
            $milliseconds = (int) $progress->currentRunStartedAt->diffInMilliseconds(now());
        }
        if ($milliseconds === null) {
            return null;
        }

        $seconds = intdiv(max(0, $milliseconds), 1000);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function outlineActionErrorMessage(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ValidationException => (string) collect($exception->errors())->flatten()->first(),
            $exception instanceof AiProviderException => $exception->getMessage(),
            default => '恢复规划批次失败，请查看运行详情后重试。',
        };
    }

    private function displayedOutline(): ?NovelOutline
    {
        $outline = $this->getRecord()->currentOutline ?? $this->latestDraft() ?? $this->getRecord()->outlines()->first();

        // 页面会同时展示四层节点，预加载完整关系，避免 Beat 数量增加后产生逐节点查询。
        return $outline?->loadMissing('volumes.arcs.beats.milestones');
    }

    private function latestDraft(): ?NovelOutline
    {
        return $this->getRecord()->outlines()->where('status', NovelOutlineStatus::Draft->value)->latest('version')->first();
    }

    private function hasFormalStructure(): bool
    {
        return $this->getRecord()->current_outline_id !== null
            || $this->getRecord()->volumes()->exists()
            || $this->getRecord()->storyArcs()->exists();
    }

    private function currentNonCanonicalChapterNeedsRestart(): bool
    {
        $novel = $this->getRecord();
        if ($novel->current_outline_id === null) {
            return false;
        }

        $chapter = $novel->chapters()
            ->where('sequence', ((int) $novel->current_chapter_sequence) + 1)
            ->where('status', '!=', ChapterStatus::Canonical->value)
            ->with('latestPlan')
            ->first();

        return $chapter?->latestPlan !== null
            && $chapter->latestPlan->novel_outline_id !== $novel->current_outline_id;
    }

    private function aiRootArtifact(?NovelOutline $outline): ?GenerationArtifact
    {
        while ($outline?->based_on_outline_id !== null) {
            $outline = $outline->basedOn;
        }

        return $outline?->source === NovelOutlineSource::Ai ? $outline->sourceArtifact : null;
    }

    private function latestBlueprintArtifact(): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::OutlineBlueprint)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('novel_id', $this->getRecord()->getKey())
                ->whereNull('chapter_id')
                ->where('scope_type', NovelOutlinePipeline::FINALIZE_SCOPE)
                ->where('stage', GenerationStage::ChapterPlanning)
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->first();
    }

    /** @param array<string, mixed> $content @return array<string, mixed> */
    private function synchronizeSequences(array $content): array
    {
        $volumes = array_values($content['volumes'] ?? []);
        $mainArcSequence = 0;
        $mainBeatSequence = 0;
        $mainBeatKeys = [];
        foreach ($volumes as $volumeIndex => &$volume) {
            $volume['sequence'] = $volumeIndex + 1;
            $arcs = array_values($volume['arcs'] ?? []);
            foreach ($arcs as $arcIndex => &$arc) {
                $arc['sequence'] = $arcIndex + 1;
                if (($arc['type'] ?? null) instanceof \BackedEnum) {
                    $arc['type'] = $arc['type']->value;
                }
                $isMain = ($arc['type'] ?? null) === StoryArcType::Main->value;
                $arc['mainline_sequence'] = $isMain ? ++$mainArcSequence : null;
                $beats = array_values($arc['beats'] ?? []);
                foreach ($beats as $beatIndex => &$beat) {
                    $beat['sequence'] = $beatIndex + 1;
                    $beat['mainline_sequence'] = $isMain ? ++$mainBeatSequence : null;
                    if ($isMain) {
                        $mainBeatKeys[] = $beat['key'] ?? null;
                    }
                    $milestones = array_values($beat['milestones'] ?? []);
                    foreach ($milestones as $milestoneIndex => &$milestone) {
                        $milestone['sequence'] = $milestoneIndex + 1;
                    }
                    unset($milestone);
                    $beat['milestones'] = $milestones;
                    $beat['handoff'] = is_array($beat['handoff'] ?? null) ? $beat['handoff'] : [];
                    foreach (['carried_states', 'open_threads', 'required_transition', 'forbidden_jump'] as $field) {
                        $beat['handoff'][$field] = array_values($beat['handoff'][$field] ?? []);
                    }
                    foreach ($beat['world_entity_candidates'] ?? [] as &$candidate) {
                        if (($candidate['type'] ?? null) instanceof \BackedEnum) {
                            $candidate['type'] = $candidate['type']->value;
                        }
                    }
                    unset($candidate);
                }
                unset($beat);
                $arc['beats'] = $beats;
            }
            unset($arc);
            $volume['arcs'] = $arcs;
        }
        unset($volume);

        // Handoff 目标由当前 Mainline 顺序确定，表单不能提交跳跃或跨版本引用。
        $mainBeatIndex = 0;
        foreach ($volumes as &$volume) {
            foreach ($volume['arcs'] as &$arc) {
                $isMain = ($arc['type'] ?? null) === StoryArcType::Main->value;
                foreach ($arc['beats'] as &$beat) {
                    $beat['handoff']['next_beat_key'] = $isMain ? ($mainBeatKeys[$mainBeatIndex + 1] ?? null) : null;
                    if ($isMain) {
                        $mainBeatIndex++;
                    }
                }
                unset($beat);
            }
            unset($arc);
        }
        unset($volume);
        $content['volumes'] = $volumes;

        return $content;
    }
}
