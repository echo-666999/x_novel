<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Chapters\ManuallyReviseChapterAction;
use App\Actions\Chapters\OverrideChapterReviewAction;
use App\Actions\Chapters\RegenerateSceneSequenceAction;
use App\Data\CanonicalCommitData;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Filament\Pages\Memory as MemoryPage;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Support\ContextInspectorSchema;
use App\Jobs\AssembleChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use App\Models\Scene;
use App\Models\UsageRecord;
use App\Services\CanonicalCommitService;
use App\Services\DraftLengthPolicy;
use App\Services\DraftRewriteDiff;
use App\Services\LatestCanonicalChapterRollback;
use App\Services\PlanValidator;
use App\Services\StatePatchBuilder;
use App\Services\StateValidator;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ViewNovelChapter extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = '章节详情';

    public int $chapterId;

    protected ?Chapter $cachedChapter = null;

    protected ?int $cachedPlanningRunId = null;

    public function mount(int|string $record, int|string|null $chapter = null): void
    {
        parent::mount($record);

        $chapterId = $this->getRecord()->chapters()
            ->whereKey($chapter)
            ->value('id');

        abort_if($chapterId === null, 404);

        $this->chapterId = (int) $chapterId;
    }

    public function getTitle(): string
    {
        $chapter = $this->chapter();

        return "第 {$chapter->sequence} 章 · {$chapter->title}";
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title.' · 章节工作台';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generatePlan')
                ->label('AI 生成章节计划')
                ->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => $this->chapter()->latestPlan === null)
                ->disabled(fn (): bool => $this->hasActivePlanningRun())
                ->tooltip(fn (): ?string => $this->hasActivePlanningRun() ? '章节规划正在运行。' : null)
                ->action(function (): void {
                    PlanChapterJob::dispatch($this->chapterId);

                    Notification::make()
                        ->title('章节计划已加入生成队列')
                        ->body('可在“运行记录”页签查看执行状态。')
                        ->success()
                        ->send();
                }),
            Action::make('regeneratePlan')
                ->label('重新生成计划')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->chapter()->latestPlan !== null)
                ->disabled(fn (): bool => $this->hasActivePlanningRun() || ! $this->canRegeneratePlan())
                ->tooltip(fn (): ?string => match (true) {
                    $this->hasActivePlanningRun() => '章节规划正在运行。',
                    ! $this->canRegeneratePlan() => '场景已进入生成流程，请在“场景”页签重新生成单个场景。仅已废弃章节可整体重新规划。',
                    default => null,
                })
                ->requiresConfirmation()
                ->modalDescription('将生成新的计划版本；当前版本与产物会保留用于追踪。')
                ->action(function (): void {
                    PlanChapterJob::dispatch($this->chapterId, true);

                    Notification::make()
                        ->title('章节计划重新生成已排队')
                        ->body('可在“运行记录”页签查看执行状态。')
                        ->success()
                        ->send();
                }),
            Action::make('planningPreview')
                ->label('规划预览')
                ->icon('heroicon-o-eye')
                ->visible(fn (): bool => $this->chapter()->latestPlan !== null)
                ->url(fn (): string => NovelResource::getUrl('planning-preview', [
                    'record' => $this->getRecord(),
                    'chapter' => $this->chapter(),
                ])),
            Action::make('commitCanonical')
                ->label('提交正式章节')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->chapter()->status === ChapterStatus::Review
                    && $this->latestReview()?->decision === ReviewDecision::Pass
                    && $this->chapter()->canonical_artifact_id === null)
                ->disabled(fn (): bool => $this->canonicalCommitContext() === null)
                ->tooltip(fn (): ?string => $this->canonicalCommitContext() === null ? '请先完成事件提取、状态补丁与状态校验。' : null)
                ->modalHeading('提交正式章节')
                ->modalDescription('该操作会原子写入正式事件、事实变化和新的故事状态版本。')
                ->modalSubmitActionLabel('确认提交')
                ->schema([
                    TextEntry::make('commit_draft')->label('草稿版本')->state(fn (): string => $this->canonicalCommitPreview()['draft']),
                    TextEntry::make('commit_state')->label('故事状态')->state(fn (): string => $this->canonicalCommitPreview()['state']),
                    TextEntry::make('commit_events')->label('事件数量')->state(fn (): int => $this->canonicalCommitPreview()['events'])->numeric(),
                    TextEntry::make('commit_facts')->label('事实变化')->state(fn (): int => $this->canonicalCommitPreview()['facts'])->numeric(),
                    TextEntry::make('commit_changes')->label('状态变化')->state(fn (): int => $this->canonicalCommitPreview()['changes'])->numeric(),
                ])
                ->action(function (CanonicalCommitService $canonicalCommit): void {
                    $context = $this->canonicalCommitContext();

                    if ($context === null) {
                        throw ValidationException::withMessages(['commit' => '正式提交所需数据尚未准备完成。']);
                    }

                    $stateVersion = $canonicalCommit->commit($context);
                    $this->cachedChapter = null;

                    Notification::make()
                        ->title('章节已提交为正式版本')
                        ->body('正式故事状态已更新至 v'.$stateVersion->version.'。')
                        ->success()
                        ->send();
                }),
            Action::make('rollbackLatestCanonical')
                ->label('回滚最新正式章节')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => $this->chapter()->status === ChapterStatus::Canonical
                    && $this->chapter()->sequence === $this->getRecord()->current_chapter_sequence)
                ->requiresConfirmation()
                ->modalHeading('回滚最新正式章节')
                ->modalDescription('此操作会失效本章的正式事件和记忆，恢复上一个故事状态指针。历史数据不会删除。')
                ->modalSubmitActionLabel('确认回滚')
                ->schema([
                    TextEntry::make('rollback_state')->label('受影响状态')->state(fn (): string => app(LatestCanonicalChapterRollback::class)->impact($this->chapter())['state']),
                    TextEntry::make('rollback_events')->label('受影响事件')->state(fn (): int => app(LatestCanonicalChapterRollback::class)->impact($this->chapter())['events'])->numeric(),
                    TextEntry::make('rollback_memories')->label('受影响记忆')->state(fn (): int => app(LatestCanonicalChapterRollback::class)->impact($this->chapter())['memories'])->numeric(),
                    Textarea::make('reason')->label('回滚原因')->required()->maxLength(1000)->columnSpanFull(),
                ])
                ->action(function (array $data, LatestCanonicalChapterRollback $rollback): void {
                    try {
                        $result = $rollback->rollback($this->chapter(), $data['reason']);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('无法回滚章节')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->cachedChapter = null;
                    $this->getRecord()->refresh();
                    Notification::make()
                        ->title('最新正式章节已回滚')
                        ->body("故事状态已恢复至 v{$result['to_state_version']}，{$result['events']} 个事件和 {$result['memories']} 条记忆已失效。")
                        ->warning()
                        ->send();
                }),
            Action::make('chapters')
                ->label('返回章节列表')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => NovelResource::getUrl('chapters', [
                    'record' => $this->getRecord(),
                ])),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('章节工作台')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('概览')
                        ->icon('heroicon-o-squares-2x2')
                        ->schema($this->overviewSchema()),
                    Tab::make('计划')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->badge(fn (): string => $this->chapter()->latestPlan === null ? '未建立' : 'v'.$this->chapter()->latestPlan->version)
                        ->schema($this->planSchema()),
                    Tab::make('场景')
                        ->icon('heroicon-o-rectangle-stack')
                        ->badge(fn (): int => $this->chapter()->scenes->count())
                        ->schema($this->scenesSchema()),
                    Tab::make('草稿')
                        ->icon('heroicon-o-document-text')
                        ->badge(fn (): int => $this->chapterDraftArtifacts()->count())
                        ->schema($this->draftSchema()),
                    Tab::make('事件')
                        ->icon('heroicon-o-bolt')
                        ->badge(fn (): int => $this->latestEventCandidateArtifact() === null
                            ? 0
                            : count(data_get($this->latestEventCandidateArtifact()?->data, 'events', [])))
                        ->schema($this->eventsSchema()),
                    Tab::make('审校')
                        ->icon('heroicon-o-shield-check')
                        ->badge(fn (): string => $this->latestReview()?->decision->getLabel() ?? '未审校')
                        ->schema($this->reviewSchema()),
                    Tab::make('状态变化')
                        ->icon('heroicon-o-arrows-right-left')
                        ->badge(fn (): int => count(data_get($this->latestStatePatchArtifact()?->data, 'changes', [])))
                        ->schema($this->stateChangesSchema()),
                    Tab::make('正式版本')
                        ->icon('heroicon-o-check-badge')
                        ->badge(fn (): string => $this->chapter()->canonicalArtifact === null ? '未提交' : '正式')
                        ->schema($this->canonicalSchema()),
                    Tab::make('运行记录')
                        ->icon('heroicon-o-command-line')
                        ->badge(fn (): int => $this->chapter()->generationRuns()->count())
                        ->schema($this->runsSchema()),
                ]),
        ]);
    }

    /** @return array<int, mixed> */
    private function reviewSchema(): array
    {
        $nextStep = $this->reviewNextStep();

        return [
            Section::make('当前下一步')
                ->description('重写完成后会自动继续事件提取、状态补丁和重新审校，无需再次手动发起审校。')
                ->icon('heroicon-o-map')
                ->headerActions([
                    Action::make('manuallyReviseChapter')
                        ->label('人工修改正文')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->visible(fn (): bool => in_array($this->latestReview()?->decision, [ReviewDecision::NeedsAttention, ReviewDecision::Block], true))
                        ->modalHeading('人工修改当前章节正文')
                        ->modalDescription('保存后会创建新的不可变人工修订稿，并自动重新提取事件、重建状态补丁和重新审校。')
                        ->modalSubmitActionLabel('保存并重新审校')
                        ->fillForm(fn (): array => [
                            'content' => $this->currentReviewDraft()?->content,
                            'reason' => null,
                        ])
                        ->schema([
                            Textarea::make('content')
                                ->label('章节正文')
                                ->rows(24)
                                ->required()
                                ->columnSpanFull(),
                            Textarea::make('reason')
                                ->label('修改原因')
                                ->helperText('原因会写入人工修订 Run 和 Artifact，供后续追踪。')
                                ->rows(3)
                                ->maxLength(2000)
                                ->required(),
                        ])
                        ->action(function (array $data, ManuallyReviseChapterAction $revise): void {
                            $artifact = $revise->execute(
                                $this->chapter(),
                                $data['content'],
                                $data['reason'],
                                auth()->id(),
                            );
                            $this->cachedChapter = null;

                            Notification::make()
                                ->title('人工修订稿已保存')
                                ->body("已创建重写稿 v{$artifact->version}，事件提取和重新审校已加入队列。")
                                ->success()
                                ->send();
                        }),
                    Action::make('overrideReview')
                        ->label('人工通过（Override）')
                        ->icon('heroicon-o-shield-check')
                        ->color('danger')
                        ->visible(fn (): bool => $this->latestReview()?->decision === ReviewDecision::NeedsAttention)
                        ->disabled(fn (): bool => $this->hasHardReviewFinding())
                        ->tooltip(fn (): ?string => $this->hasHardReviewFinding() ? '当前 Review 存在硬冲突，不能人工通过。' : '保留原 Review 和 Findings，并创建一条带原因的人工 PASS Review。')
                        ->requiresConfirmation()
                        ->modalHeading('人工 Override 为通过')
                        ->modalDescription('该操作不会删除原审校问题。系统会创建新的 PASS Review 并记录操作原因，之后才可提交正式章节。')
                        ->modalSubmitActionLabel('确认人工通过')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Override 原因')
                                ->helperText('请说明为什么可以接受当前问题并提交该版本。')
                                ->rows(4)
                                ->maxLength(2000)
                                ->required(),
                        ])
                        ->action(function (array $data, OverrideChapterReviewAction $override): void {
                            $review = $override->execute($this->chapter(), $data['reason'], auth()->id());
                            $this->cachedChapter = null;

                            Notification::make()
                                ->title('章节审校已人工通过')
                                ->body("已创建 Review v{$review->artifact->version}，现在可以提交正式章节。")
                                ->warning()
                                ->send();
                        }),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('review_next_action')
                        ->label('建议操作')
                        ->state($nextStep['action'])
                        ->badge()
                        ->color($nextStep['color']),
                    TextEntry::make('review_rewrite_budget')
                        ->label('重写次数')
                        ->state($nextStep['budget']),
                    TextEntry::make('review_next_result')
                        ->label('通过后')
                        ->state($nextStep['after_pass']),
                    TextEntry::make('review_next_explanation')
                        ->label('处理说明')
                        ->state($nextStep['explanation'])
                        ->columnSpanFull(),
                    TextEntry::make('review_automatic_flow')
                        ->label('自动流程')
                        ->state($nextStep['flow'])
                        ->columnSpanFull(),
                ]),
            Section::make('叙事审校')
                ->description('七维评分结合确定性状态检查结果形成最终审校决策。')
                ->headerActions([
                    Action::make('rewriteScene')
                        ->label('重写场景')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn (): bool => in_array($this->latestReview()?->decision, [ReviewDecision::Rewrite, ReviewDecision::NeedsAttention], true))
                        ->disabled(fn (): bool => $this->automaticRewriteArtifacts()->count() >= (int) config('generation.max_rewrite_attempts', 2))
                        ->tooltip(fn (): string => $this->rewriteActionTooltip('scene'))
                        ->schema([
                            Select::make('scene_id')->label('场景')->required()->options(fn (): array => $this->chapter()->scenes->mapWithKeys(fn (Scene $scene): array => [$scene->getKey() => '场景 '.$scene->sequence.' · '.$scene->goal])->all()),
                        ])
                        ->action(function (array $data): void {
                            RewriteChapterJob::dispatch($this->chapterId, (int) $data['scene_id']);
                            Notification::make()
                                ->title('场景重写已加入队列')
                                ->body('完成后将自动重新组装章节、提取事件、重建状态补丁并重新审校。')
                                ->success()
                                ->send();
                        }),
                    Action::make('rewriteChapter')
                        ->label('重写章节')
                        ->icon('heroicon-o-document-text')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => in_array($this->latestReview()?->decision, [ReviewDecision::Rewrite, ReviewDecision::NeedsAttention], true))
                        ->disabled(fn (): bool => $this->automaticRewriteArtifacts()->count() >= (int) config('generation.max_rewrite_attempts', 2))
                        ->tooltip(fn (): string => $this->rewriteActionTooltip('chapter'))
                        ->modalDescription('将基于最新审校问题生成新的不可变重写稿。完成后系统会自动重新提取事件、重建状态补丁并重新审校。')
                        ->action(function (): void {
                            RewriteChapterJob::dispatch($this->chapterId);
                            Notification::make()
                                ->title('章节重写已加入队列')
                                ->body('完成后将自动提取事件、重建状态补丁并重新审校，无需再点“强制重新审校”。')
                                ->success()
                                ->send();
                        }),
                    Action::make('runReview')
                        ->label(fn (): string => $this->latestReview() ? '强制重新审校' : '开始审校')
                        ->icon('heroicon-o-shield-check')
                        ->color(fn (): string => $this->latestReview() ? 'gray' : 'primary')
                        ->visible(fn (): bool => ! in_array($this->latestReview()?->decision, [ReviewDecision::NeedsAttention, ReviewDecision::Block], true))
                        ->tooltip(fn (): ?string => $this->latestReview() ? '仅用于在正文未变化时再次执行审校；正常重写完成后系统会自动重新审校。' : null)
                        ->disabled(fn (): bool => $this->chapter()->generationRuns()->where('stage', GenerationStage::Review)->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists())
                        ->action(function (): void {
                            ReviewChapterJob::dispatch($this->chapterId, $this->latestReview() !== null);
                            Notification::make()->title('叙事审校已加入生成队列')->success()->send();
                        }),
                ])
                ->columns(['default' => 1, 'md' => 4])
                ->schema([
                    TextEntry::make('review_decision')->label('结论')->state(fn () => $this->latestReview()?->decision ?? '未审校')->badge(),
                    TextEntry::make('review_total')->label('总分')->state(fn () => $this->latestReview()?->score ?? '—')->suffix(fn () => $this->latestReview() ? ' / 100' : null),
                    TextEntry::make('review_version')->label('审校版本')->state(fn () => $this->latestReview()?->artifact?->version ? 'v'.$this->latestReview()->artifact->version : '—'),
                    TextEntry::make('review_time')->label('完成时间')->state(fn () => $this->latestReview()?->created_at?->format('Y-m-d H:i:s') ?? '—'),
                ]),
            Section::make('七维评分')
                ->columns(['default' => 2, 'md' => 4, 'xl' => 7])
                ->schema(collect([
                    'continuity_score' => '事实 / 连续性 · 25%', 'plan_score' => '计划遵循 · 15%', 'character_score' => '人物一致性 · 15%',
                    'progress_score' => '剧情推进 · 15%', 'repetition_score' => '重复度 · 10%', 'pacing_score' => '节奏 / 悬念 · 10%', 'style_score' => '文风 / 可读性 · 10%',
                ])->map(fn ($label, $field) => TextEntry::make("review_{$field}")->label($label)->state(fn () => $this->latestReview()?->{$field} ?? '—'))->values()->all()),
            Section::make('问题清单')
                ->description('包含叙事审校发现和状态校验器的确定性检查结果。')
                ->schema([
                    RepeatableEntry::make('review_findings')->hiddenLabel()->state(fn (): array => $this->localizedReviewFindings())->schema([
                        TextEntry::make('message')->label('问题'),
                        TextEntry::make('dimension')->label('维度')->placeholder('状态一致性'),
                        TextEntry::make('severity')->label('级别')->badge(),
                        TextEntry::make('evidence')->label('证据')->placeholder('—')->columnSpanFull(),
                    ])->columns(3),
                    TextEntry::make('review_empty')->hiddenLabel()->state('暂无审校问题。')->visible(fn (): bool => empty($this->latestReview()?->findings)),
                ]),
            Section::make('重写历史')
                ->description('原稿和最多两次重写均作为不可变产物保留。')
                ->schema([
                    RepeatableEntry::make('rewrite_versions')->hiddenLabel()->state(fn (): array => $this->rewriteVersionRows())->schema([
                        TextEntry::make('label')->label('版本')->badge(),
                        TextEntry::make('scope')->label('范围'),
                        TextEntry::make('content')->label('正文')->prose()->columnSpanFull(),
                    ])->columns(2),
                ]),
            Section::make('重写与复审历程')
                ->description('每一轮都关联触发审校、重写稿、重新提取的事件和重写后的审校结果。')
                ->visible(fn (): bool => $this->rewriteArtifacts()->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('rewrite_journey')->hiddenLabel()->state(fn (): array => $this->rewriteJourneyRows())->schema([
                        TextEntry::make('round')->label('轮次')->badge()->color('warning'),
                        TextEntry::make('scope')->label('重写范围'),
                        TextEntry::make('trigger')->label('触发审校'),
                        TextEntry::make('result')->label('复审结果')->badge(),
                        TextEntry::make('pipeline')->label('产物链路')->columnSpanFull(),
                        TextEntry::make('findings')->label('复审剩余问题')->columnSpanFull(),
                        TextEntry::make('completed_at')->label('完成时间')->placeholder('处理中'),
                    ])->columns(['default' => 1, 'md' => 4]),
                ]),
            Section::make('草稿 / 重写差异')
                ->description('对照重写前后正文，并根据后续审校标记问题是否已解决。')
                ->visible(fn (): bool => $this->rewriteArtifacts()->isNotEmpty())
                ->schema([
                    View::make('filament.components.draft-rewrite-diff')
                        ->viewData(fn (): array => ['diff' => app(DraftRewriteDiff::class)->forChapter($this->chapterId)]),
                ]),
        ];
    }

    /** @return array{action: string, color: string, budget: string, after_pass: string, explanation: string, flow: string} */
    private function reviewNextStep(): array
    {
        $review = $this->latestReview();
        $used = $this->automaticRewriteArtifacts()->count();
        $maximum = (int) config('generation.max_rewrite_attempts', 2);
        $budget = "已使用 {$used} / {$maximum} 次";
        $afterPass = (bool) data_get($this->getRecord()->settings, 'auto_commit', false)
            ? '自动提交正式章节'
            : '页面顶部提交正式章节';
        $flow = '重写场景 → 重新组装章节 → 重新提取事件 → 重建状态补丁 → 重新审校；重写章节从“重新提取事件”继续。';

        if ($review === null) {
            return [
                'action' => '开始审校', 'color' => 'primary', 'budget' => $budget, 'after_pass' => $afterPass,
                'explanation' => '当前稿尚未形成审校结论。', 'flow' => '审校 → 根据结论进入提交、重写或人工处理。',
            ];
        }

        if ($review->decision === ReviewDecision::Pass) {
            return [
                'action' => '提交正式章节', 'color' => 'success', 'budget' => $budget, 'after_pass' => $afterPass,
                'explanation' => '当前 Review 已通过。系统不会额外显示“通过”按钮；PASS 是审校结论，下一步是正式提交。', 'flow' => 'PASS → Canonical Commit → 正式事件与故事状态更新。',
            ];
        }

        if ($review->decision === ReviewDecision::Rewrite && $used < $maximum) {
            $nextAttempt = $used + 1;
            $hasChapterFinding = collect($review->findings)->contains(fn (array $finding): bool => str_starts_with((string) data_get($finding, 'code'), 'CHAPTER_LENGTH_')
                || data_get($finding, 'scene_id') === null
            );
            $scope = $hasChapterFinding ? '重写章节' : '重写场景';

            return [
                'action' => "第 {$nextAttempt} / {$maximum} 次{$scope}", 'color' => 'warning', 'budget' => $budget, 'after_pass' => $afterPass,
                'explanation' => '点击重写一次即可。队列完成整条复审链路后刷新页面查看新结论，不需要手动点击“强制重新审校”。', 'flow' => $flow,
            ];
        }

        if ($review->decision === ReviewDecision::NeedsAttention || $used >= $maximum) {
            return [
                'action' => '需要人工处理', 'color' => 'warning', 'budget' => $budget, 'after_pass' => $afterPass,
                'explanation' => '自动重写次数已耗尽或审校存在歧义，需要人工修改正文或处理相关事实后再审校。', 'flow' => '人工处理 → 重新提取事件 → 重建状态补丁 → 重新审校。',
            ];
        }

        return [
            'action' => '处理阻塞问题', 'color' => 'danger', 'budget' => $budget, 'after_pass' => $afterPass,
            'explanation' => '当前存在不可直接提交的硬冲突，请先处理锁定事实、故事状态或计划冲突。', 'flow' => '处理硬冲突 → 重新生成或重新审校。',
        ];
    }

    private function rewriteActionTooltip(string $scope): string
    {
        $used = $this->automaticRewriteArtifacts()->count();
        $maximum = (int) config('generation.max_rewrite_attempts', 2);

        if ($used >= $maximum) {
            return "已达到最大 {$maximum} 次自动重写，需要人工处理。";
        }

        return $scope === 'scene'
            ? '适合问题明确落在单个场景时使用；完成后会自动重新组装和复审。'
            : '适合字数、节奏或跨场景问题；完成后会自动重新提取事件和复审。';
    }

    /** @return array<int, array<string, mixed>> */
    private function rewriteJourneyRows(): array
    {
        return $this->rewriteArtifacts()->values()->map(function (GenerationArtifact $rewrite, int $index): array {
            $sourceReview = Review::query()->with('artifact')->find(data_get($rewrite->data, 'source_review_id'));
            $reviewedDraft = data_get($rewrite->data, 'scope') === 'scene'
                ? GenerationArtifact::query()
                    ->where('type', ArtifactType::ChapterDraft)
                    ->whereHas('generationRun', fn ($query) => $query
                        ->where('chapter_id', $this->chapterId)
                        ->where('id', '>', $rewrite->generation_run_id))
                    ->oldest('id')->first()
                : $rewrite;
            $eventCandidate = $reviewedDraft === null ? null : GenerationArtifact::query()
                ->where('type', ArtifactType::EventCandidate)
                ->where('data->source_artifact_id', $reviewedDraft->getKey())
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
                ->oldest('id')->first();
            $afterReview = $reviewedDraft === null ? null : Review::query()
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
                ->whereHas('artifact', fn ($query) => $query->where('data->source_artifact_id', $reviewedDraft->getKey()))
                ->oldest('id')->first();
            $wordCount = (int) data_get($rewrite->data, 'word_count', 0);
            $scope = data_get($rewrite->data, 'scope') === 'scene'
                ? '场景 '.($rewrite->generationRun?->scene_id ?? '—')
                : '整章';
            $draftLabel = data_get($rewrite->data, 'scope') === 'scene'
                ? "场景重写稿 v{$rewrite->version}（Artifact #{$rewrite->id}）"
                : "章节重写稿 v{$rewrite->version}（Artifact #{$rewrite->id}，{$wordCount} 字）";
            $pipeline = $draftLabel;
            if ($reviewedDraft !== null && ! $reviewedDraft->is($rewrite)) {
                $pipeline .= " → 章节草稿 v{$reviewedDraft->version}（Artifact #{$reviewedDraft->id}）";
            }
            $pipeline .= $eventCandidate === null
                ? ' → 等待事件提取'
                : " → 事件候选 v{$eventCandidate->version}（Artifact #{$eventCandidate->id}）";
            $pipeline .= $afterReview === null
                ? ' → 等待重新审校'
                : " → 审校 v{$afterReview->artifact?->version}（Artifact #{$afterReview->artifact_id}）";

            return [
                'round' => data_get($rewrite->data, 'manual_edit')
                    ? '人工修订'
                    : '第 '.($index + 1).' 轮',
                'scope' => $scope,
                'trigger' => $sourceReview === null
                    ? '—'
                    : "审校 v{$sourceReview->artifact?->version} · {$sourceReview->decision->getLabel()} · {$sourceReview->score}",
                'result' => $afterReview === null
                    ? '处理中'
                    : "{$afterReview->decision->getLabel()} · {$afterReview->score}",
                'pipeline' => $pipeline,
                'findings' => $afterReview === null
                    ? '等待重新审校。'
                    : (collect($afterReview->findings)->pluck('message')->filter()->implode("\n") ?: '没有剩余问题。'),
                'completed_at' => $afterReview?->created_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    private function latestReview(): ?Review
    {
        return Review::query()
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $this->chapterId)
                ->where('id', '>', $this->currentPlanningRunId()))
            ->with('artifact')->latest('id')->first();
    }

    private function currentReviewDraft(): ?GenerationArtifact
    {
        $artifactId = (int) data_get($this->latestReview()?->artifact?->data, 'source_artifact_id');

        return $artifactId > 0 ? GenerationArtifact::query()->find($artifactId) : null;
    }

    private function hasHardReviewFinding(): bool
    {
        return collect($this->latestReview()?->findings)
            ->contains(fn (array $finding): bool => data_get($finding, 'severity') === 'hard');
    }

    /** @return array<int, array<string, mixed>> */
    private function localizedReviewFindings(): array
    {
        $dimensions = [
            'continuity' => '事实 / 连续性',
            'plan' => '计划遵循',
            'character' => '人物一致性',
            'progress' => '剧情推进',
            'repetition' => '重复度',
            'pacing' => '节奏 / 悬念',
            'style' => '文风 / 可读性',
        ];
        $severities = ['warning' => '警告', 'error' => '错误', 'hard' => '阻断', 'soft' => '提醒'];

        return collect($this->latestReview()?->findings ?? [])
            ->map(fn (array $finding): array => [
                ...$finding,
                'dimension' => $dimensions[(string) ($finding['dimension'] ?? '')] ?? ($finding['dimension'] ?? '状态一致性'),
                'severity' => $severities[(string) ($finding['severity'] ?? '')] ?? ($finding['severity'] ?? '—'),
            ])->all();
    }

    private function canonicalCommitContext(): ?CanonicalCommitData
    {
        $review = $this->latestReview();
        $draftId = (int) data_get($review?->artifact?->data, 'source_artifact_id', 0);
        $draft = $draftId > 0 ? GenerationArtifact::query()->find($draftId) : null;
        $candidate = $draft === null ? null : GenerationArtifact::query()
            ->where('type', ArtifactType::EventCandidate)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
            ->latest('version')->latest('id')->get()
            ->first(fn (GenerationArtifact $artifact): bool => (int) data_get($artifact->data, 'source_artifact_id') === $draft->getKey());
        $patch = $candidate === null ? null : GenerationArtifact::query()
            ->where('type', ArtifactType::StatePatch)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
            ->latest('version')->latest('id')->get()
            ->first(fn (GenerationArtifact $artifact): bool => (int) data_get($artifact->data, 'source_artifact_id') === $candidate->getKey());
        $stateVersion = $this->getRecord()->canonicalStateVersion()->first();

        if ($review?->decision !== ReviewDecision::Pass || $draft === null || $candidate === null || $patch === null || $stateVersion === null) {
            return null;
        }

        return new CanonicalCommitData(
            chapterId: $this->chapterId,
            artifactId: $draft->getKey(),
            reviewId: $review->getKey(),
            eventCandidateArtifactId: $candidate->getKey(),
            statePatchArtifactId: $patch->getKey(),
            expectedStateVersion: $stateVersion->version,
            artifactChecksum: $draft->checksum,
        );
    }

    /** @return array{draft: string, state: string, events: int, facts: int, changes: int} */
    private function canonicalCommitPreview(): array
    {
        $context = $this->canonicalCommitContext();
        $draft = $context === null ? null : GenerationArtifact::query()->find($context->artifactId);
        $candidate = $context === null ? null : GenerationArtifact::query()->find($context->eventCandidateArtifactId);
        $patch = $context === null ? null : GenerationArtifact::query()->find($context->statePatchArtifactId);

        return [
            'draft' => $draft === null ? '—' : match ($draft->type) {
                ArtifactType::RewriteDraft => '重写稿 v'.$draft->version,
                default => '章节草稿 v'.$draft->version,
            },
            'state' => $context === null ? '—' : 'v'.$context->expectedStateVersion.' → v'.($context->expectedStateVersion + 1),
            'events' => count(data_get($candidate?->data, 'events', [])),
            'facts' => count(data_get($patch?->data, 'fact_changes', [])),
            'changes' => count(data_get($patch?->data, 'changes', [])),
        ];
    }

    private function rewriteArtifacts()
    {
        return GenerationArtifact::query()->where('type', ArtifactType::RewriteDraft)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $this->chapterId)
                ->where('id', '>', $this->currentPlanningRunId()))
            ->orderBy('id')->get();
    }

    private function automaticRewriteArtifacts()
    {
        return $this->rewriteArtifacts()
            ->reject(fn (GenerationArtifact $artifact): bool => (bool) data_get($artifact->data, 'manual_edit'));
    }

    /** @return array<int, array<string, mixed>> */
    private function rewriteVersionRows(): array
    {
        $rewrites = $this->rewriteArtifacts();
        $firstRewrite = $rewrites->first();
        $original = $firstRewrite === null
            ? GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)
                ->whereHas('generationRun', fn ($query) => $query
                    ->where('chapter_id', $this->chapterId)
                    ->where('id', '>', $this->currentPlanningRunId()))
                ->latest('id')->first()
            : GenerationArtifact::query()->find(data_get($firstRewrite->data, 'source_artifact_id'));
        $originalScope = $original?->generationRun?->scene_id === null
            ? '整章'
            : '场景 '.$original->generationRun->scene_id;
        $originalType = $original?->type === ArtifactType::SceneDraft ? '场景草稿' : '章节草稿';
        $rows = $original === null ? [] : [[
            'label' => "重写前 · {$originalType} v{$original->version}",
            'scope' => $originalScope,
            'content' => $original->content,
        ]];
        foreach ($rewrites as $index => $artifact) {
            $label = data_get($artifact->data, 'manual_edit')
                ? '人工修订 · v'.$artifact->version
                : '第 '.($index + 1).' 次重写 · v'.$artifact->version;
            $rows[] = [
                'label' => $label,
                'scope' => data_get($artifact->data, 'scope') === 'scene' ? '场景 '.$artifact->generationRun?->scene_id : '整章',
                'content' => $artifact->content,
            ];
        }

        return $rows;
    }

    /** @return array<int, mixed> */
    private function overviewSchema(): array
    {
        return [
            Section::make('章节概览')
                ->description('从计划到正式状态的单章工作入口。')
                ->columns(['default' => 1, 'md' => 4, 'xl' => 8])
                ->schema([
                    TextEntry::make('chapter_status')
                        ->label('章节状态')
                        ->state(fn () => $this->chapter()->status)
                        ->badge(),
                    TextEntry::make('volume')
                        ->label('所属分卷')
                        ->state(fn (): ?string => $this->chapter()->volume === null
                            ? null
                            : "第 {$this->chapter()->volume->sequence} 卷 · {$this->chapter()->volume->title}")
                        ->placeholder('未指定'),
                    TextEntry::make('draft_word_count')
                        ->label('当前稿字数')
                        ->state(function (): ?int {
                            $artifact = $this->currentDraftArtifact();

                            return $artifact === null ? null : app(DraftLengthPolicy::class)->count($artifact->content);
                        })
                        ->numeric()
                        ->placeholder('尚无草稿'),
                    TextEntry::make('target_word_count')
                        ->label('目标字数')
                        ->state(fn (): ?int => $this->chapter()->latestPlan?->target_words)
                        ->numeric()
                        ->placeholder('未建立'),
                    TextEntry::make('word_completion')
                        ->label('字数完成度')
                        ->state(function (): ?string {
                            $artifact = $this->currentDraftArtifact();
                            $target = (int) $this->chapter()->latestPlan?->target_words;

                            return $artifact === null || $target <= 0
                                ? null
                                : app(DraftLengthPolicy::class)->completionPercentage(
                                    app(DraftLengthPolicy::class)->count($artifact->content),
                                    $target,
                                ).'%';
                        })
                        ->placeholder('—'),
                    TextEntry::make('plan_status')
                        ->label('计划')
                        ->state(fn () => $this->chapter()->latestPlan?->status)
                        ->badge()
                        ->placeholder('未建立'),
                    TextEntry::make('scene_count')
                        ->label('场景数')
                        ->state(fn (): int => $this->chapter()->scenes->count()),
                    TextEntry::make('state_version')
                        ->label('状态版本')
                        ->state(fn (): ?string => $this->chapter()->latestStateVersion === null
                            ? null
                            : 'v'.$this->chapter()->latestStateVersion->version)
                        ->placeholder('—'),
                ]),
            $this->pipelineTimelineSection(),
        ];
    }

    /** @return array<int, mixed> */
    private function planSchema(): array
    {
        return [
            Section::make('尚未建立章节计划')
                ->description('返回章节列表建立计划后，这里会显示生成执行基线。')
                ->icon('heroicon-o-clipboard-document-list')
                ->visible(fn (): bool => $this->chapter()->latestPlan === null),
            Section::make('章节目标')
                ->description('完整约束与校验结果可通过右上角“规划预览”查看。')
                ->visible(fn (): bool => $this->chapter()->latestPlan !== null)
                ->columns(['default' => 1, 'lg' => 3])
                ->schema([
                    TextEntry::make('chapter_function')
                        ->label('章节功能')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->chapter_function),
                    TextEntry::make('arc_contribution')
                        ->label('故事线贡献')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->arc_contribution),
                    TextEntry::make('reader_promise')
                        ->label('读者承诺')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->reader_promise),
                    TextEntry::make('pov')
                        ->label('视角角色')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->povCharacter?->name)
                        ->placeholder('未指定'),
                    TextEntry::make('tone')
                        ->label('语气 / 钩子')
                        ->state(fn (): ?string => $this->chapter()->latestPlan === null
                            ? null
                            : $this->chapter()->latestPlan->tone.' · '.$this->chapter()->latestPlan->hook_type),
                    TextEntry::make('validation')
                        ->label('计划检查结果')
                        ->state(fn () => $this->chapter()->latestPlan === null
                            ? null
                            : app(PlanValidator::class)->validate($this->chapter()->latestPlan)->status())
                        ->badge(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function scenesSchema(): array
    {
        $sections = [
            Section::make('尚未同步场景')
                ->description('先在章节列表保存章节计划，再使用“同步场景”。')
                ->icon('heroicon-o-rectangle-stack')
                ->visible(fn (): bool => $this->chapter()->scenes->isEmpty()),
        ];

        foreach ($this->chapter()->scenes as $scene) {
            $sections[] = $this->sceneSection($scene);
        }

        return $sections;
    }

    private function sceneSection(Scene $scene): Section
    {
        $run = $scene->generationRuns->sortByDesc('id')->first();
        $artifact = $scene->currentArtifact;
        $artifacts = $this->sceneArtifacts($scene);

        return Section::make('场景 '.$scene->sequence)
            ->key('scene-'.$scene->getKey())
            ->description($scene->goal)
            ->headerActions([
                Action::make('generateScene'.$scene->getKey())
                    ->label('生成')
                    ->icon('heroicon-o-play')
                    ->visible($scene->status === SceneStatus::Planned)
                    ->disabled(! $this->canGenerateScene($scene))
                    ->tooltip(! $this->canGenerateScene($scene) ? '请等待前序场景生成成功。' : null)
                    ->action(fn () => $this->dispatchScene($scene)),
                Action::make('retryScene'.$scene->getKey())
                    ->label('重试')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible($scene->status === SceneStatus::Failed)
                    ->disabled(! $this->canGenerateScene($scene))
                    ->tooltip(! $this->canGenerateScene($scene) ? '请等待前序场景生成成功。' : null)
                    ->requiresConfirmation()
                    ->modalHeading('重试场景 '.$scene->sequence)
                    ->modalDescription('将重置当前场景及其后续场景并按顺序重新生成。历史产物会保留。')
                    ->modalSubmitActionLabel('确认重试')
                    ->action(fn (RegenerateSceneSequenceAction $regenerate) => $this->regenerateSceneSequence($scene, $regenerate)),
                Action::make('regenerateScene'.$scene->getKey())
                    ->label('重新生成')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible($artifact !== null
                        && in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true)
                        && $this->chapter()->status !== ChapterStatus::Canonical)
                    ->disabled(! $this->canGenerateScene($scene))
                    ->tooltip(! $this->canGenerateScene($scene) ? '请等待前序场景生成成功。' : null)
                    ->requiresConfirmation()
                    ->modalHeading('重新生成场景 '.$scene->sequence)
                    ->modalDescription('将重置当前场景及其后续场景并按顺序重新生成。历史产物会保留；完成后需要重新组装章节并重新审校。')
                    ->modalSubmitActionLabel('确认重新生成')
                    ->action(fn (RegenerateSceneSequenceAction $regenerate) => $this->regenerateSceneSequence($scene, $regenerate)),
                Action::make('viewSceneArtifact'.$scene->getKey())
                    ->label('查看版本（'.$artifacts->count().'）')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible($artifacts->isNotEmpty())
                    ->slideOver()
                    ->modalHeading('场景 '.$scene->sequence.' · 草稿版本')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭')
                    ->infolist([
                        Tabs::make('Scene Artifact Versions '.$scene->getKey())
                            ->tabs($artifacts->map(function (GenerationArtifact $versionArtifact) use ($artifact): Tab {
                                $isCurrent = $artifact?->is($versionArtifact) ?? false;
                                $versionRun = $versionArtifact->generationRun;

                                return Tab::make('v'.$versionArtifact->version.' · '.($isCurrent ? '当前' : '历史'))
                                    ->badge('#'.$versionArtifact->getKey())
                                    ->schema([
                                        Section::make('场景草稿 v'.$versionArtifact->version)
                                            ->description($isCurrent ? '当前场景生成使用的版本。' : '重试或重新生成前保留的历史版本。')
                                            ->columns(['default' => 1, 'md' => 3])
                                            ->schema([
                                                TextEntry::make('scene_artifact_status_'.$versionArtifact->getKey())
                                                    ->label('版本状态')
                                                    ->state($isCurrent ? '当前' : '历史')
                                                    ->badge()
                                                    ->color($isCurrent ? 'success' : 'gray'),
                                                TextEntry::make('scene_artifact_run_'.$versionArtifact->getKey())
                                                    ->label('生成运行记录')
                                                    ->state($versionRun === null ? null : '#'.$versionRun->getKey().' · 第 '.$versionRun->attempt.' 次尝试')
                                                    ->placeholder('—'),
                                                TextEntry::make('scene_artifact_created_'.$versionArtifact->getKey())
                                                    ->label('生成时间')
                                                    ->state($versionArtifact->created_at)
                                                    ->dateTime(),
                                                TextEntry::make('scene_artifact_model_'.$versionArtifact->getKey())
                                                    ->label('模型')
                                                    ->state($versionRun?->model_policy)
                                                    ->placeholder('—'),
                                                TextEntry::make('scene_artifact_prompt_'.$versionArtifact->getKey())
                                                    ->label('提示词版本')
                                                    ->state($versionRun?->prompt_version)
                                                    ->placeholder('—'),
                                                TextEntry::make('scene_artifact_words_'.$versionArtifact->getKey())
                                                    ->label('字数')
                                                    ->state(app(DraftLengthPolicy::class)->count($versionArtifact->content))
                                                    ->numeric(),
                                                TextEntry::make('scene_artifact_content_'.$versionArtifact->getKey())
                                                    ->label('正文')
                                                    ->state($versionArtifact->content)
                                                    ->prose()
                                                    ->copyable()
                                                    ->columnSpanFull(),
                                                TextEntry::make('scene_artifact_delta_'.$versionArtifact->getKey())
                                                    ->label('临时状态变化')
                                                    ->state(fn (): string => json_encode(
                                                        data_get($versionArtifact->data, 'temporary_state_delta', []),
                                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                                                    ) ?: '{}')
                                                    ->fontFamily('mono')
                                                    ->copyable()
                                                    ->columnSpanFull(),
                                            ]),
                                    ]);
                            })->all()),
                    ]),
                Action::make('viewSceneRun'.$scene->getKey())
                    ->label('查看运行记录')
                    ->icon('heroicon-o-command-line')
                    ->color('gray')
                    ->visible($run !== null)
                    ->modalHeading('场景 '.$scene->sequence.' · 生成运行记录')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭')
                    ->infolist([
                        TextEntry::make('scene_run_status_'.$scene->getKey())->label('状态')->state($run?->status)->badge(),
                        TextEntry::make('scene_run_model_'.$scene->getKey())->label('模型')->state($run?->model_policy)->placeholder('—'),
                        TextEntry::make('scene_run_prompt_'.$scene->getKey())->label('提示词版本')->state($run?->prompt_version)->placeholder('—'),
                        TextEntry::make('scene_run_error_'.$scene->getKey())
                            ->label('错误')
                            ->state($run?->error_code === null ? null : $run->error_code.' · '.$run->error_message)
                            ->placeholder('—'),
                    ]),
            ])
            ->columns(['default' => 2, 'md' => 4, 'xl' => 9])
            ->schema([
                TextEntry::make('scene_status_'.$scene->getKey())->label('状态')->state($scene->status)->badge(),
                TextEntry::make('scene_version_'.$scene->getKey())
                    ->label('当前版本')
                    ->state($artifact === null ? null : 'v'.$artifact->version)
                    ->badge()
                    ->color('success')
                    ->placeholder('—'),
                TextEntry::make('scene_words_'.$scene->getKey())->label('字数')->state(mb_strlen($artifact?->content ?? ''))->numeric(),
                TextEntry::make('scene_duration_'.$scene->getKey())
                    ->label('耗时')
                    ->state($run?->durationMilliseconds() === null ? null : $run->durationMilliseconds().' ms')
                    ->placeholder('—'),
                TextEntry::make('scene_cost_'.$scene->getKey())
                    ->label('成本')
                    ->state($run === null ? null : config('ai.cost.currency').' '.number_format((float) $run->usageRecords->sum('estimated_cost'), 4))
                    ->placeholder('—'),
                TextEntry::make('scene_pov_'.$scene->getKey())->label('视角角色')->state($scene->povCharacter?->name)->placeholder('未指定'),
                TextEntry::make('scene_location_'.$scene->getKey())->label('地点')->state($scene->location)->placeholder('未指定'),
                TextEntry::make('scene_conflict_'.$scene->getKey())->label('冲突')->state($scene->conflict),
                TextEntry::make('scene_outcome_'.$scene->getKey())->label('结果')->state($scene->outcome),
            ]);
    }

    private function dispatchScene(Scene $scene): void
    {
        GenerateSceneJob::dispatch($scene->getKey());

        Notification::make()
            ->title('场景已加入生成队列')
            ->body('场景 '.$scene->sequence.' 将在生成队列中执行。')
            ->success()
            ->send();
    }

    private function regenerateSceneSequence(Scene $scene, RegenerateSceneSequenceAction $regenerate): void
    {
        $count = $regenerate->handle($scene);
        $this->cachedChapter = null;

        Notification::make()
            ->title('场景级联重新生成已加入队列')
            ->body("将从场景 {$scene->sequence} 开始，按顺序重新生成 {$count} 个场景。")
            ->success()
            ->send();
    }

    private function canGenerateScene(Scene $scene): bool
    {
        return $this->chapter()->scenes
            ->where('sequence', '<', $scene->sequence)
            ->every(fn (Scene $previous): bool => $previous->current_artifact_id !== null
                && in_array($previous->status, [SceneStatus::Draft, SceneStatus::Accepted], true));
    }

    private function sceneArtifacts(Scene $scene)
    {
        return $scene->generationRuns
            ->flatMap(fn (GenerationRun $run) => $run->artifacts->map(
                fn (GenerationArtifact $artifact): GenerationArtifact => $artifact->setRelation('generationRun', $run),
            ))
            ->where('type', ArtifactType::SceneDraft)
            ->sortByDesc('version')
            ->values();
    }

    private function canRegeneratePlan(): bool
    {
        return $this->chapter()->status === ChapterStatus::Void
            || $this->chapter()->scenes->every(fn (Scene $scene): bool => $scene->status === SceneStatus::Planned
                && $scene->current_artifact_id === null);
    }

    /** @return array<int, mixed> */
    private function draftSchema(): array
    {
        $artifacts = $this->chapterDraftArtifacts();
        $sections = [
            Section::make('章节组装')
                ->description('按场景顺序组装完整章节；每次结果保存为新的不可变产物版本。')
                ->headerActions([
                    Action::make('assembleChapter')
                        ->label('组装章节')
                        ->icon('heroicon-o-document-plus')
                        ->disabled(! $this->canAssembleChapter() || $this->hasActiveAssemblyRun())
                        ->tooltip(match (true) {
                            $this->hasActiveAssemblyRun() => '章节组装正在运行。',
                            ! $this->canAssembleChapter() => '所有 Scene 成功后才能组装 Chapter。',
                            default => null,
                        })
                        ->action(function (): void {
                            AssembleChapterJob::dispatch($this->chapterId);

                            Notification::make()
                                ->title('章节组装已加入队列')
                                ->body('可在“草稿”或“运行记录”页签查看执行状态。')
                                ->success()
                                ->send();
                        }),
                ])
                ->columns(['default' => 1, 'md' => 3, 'xl' => 6])
                ->schema([
                    TextEntry::make('assembly_scene_count')
                        ->label('场景数')
                        ->state($this->chapter()->scenes->count()),
                    TextEntry::make('assembly_ready_count')
                        ->label('已成功')
                        ->state($this->chapter()->scenes->filter(fn (Scene $scene): bool => $this->canUseForAssembly($scene))->count()),
                    TextEntry::make('assembly_status')
                        ->label('组装状态')
                        ->state($this->latestAssemblyRun()?->status)
                        ->badge()
                        ->placeholder('尚未运行'),
                    TextEntry::make('assembly_source_words')
                        ->label('场景合计字数')
                        ->state(fn (): int => $this->chapter()->scenes->sum(
                            fn (Scene $scene): int => app(DraftLengthPolicy::class)->count($scene->currentArtifact?->content),
                        ))
                        ->numeric(),
                    TextEntry::make('assembly_target_words')
                        ->label('章节目标字数')
                        ->state(fn (): ?int => $this->chapter()->latestPlan?->target_words)
                        ->numeric()
                        ->placeholder('未建立'),
                    TextEntry::make('assembly_acceptable_range')
                        ->label('可接受范围')
                        ->state(function (): ?string {
                            $target = (int) $this->chapter()->latestPlan?->target_words;

                            return $target <= 0 ? null : number_format(app(DraftLengthPolicy::class)->chapterMinimum($target))
                                .'～'.number_format(app(DraftLengthPolicy::class)->chapterMaximum($target)).' 字';
                        })
                        ->placeholder('—'),
                ]),
        ];

        if ($artifacts->isEmpty()) {
            $sections[] = Section::make('尚无章节草稿')
                ->description('所有场景生成成功后，使用“组装章节”生成完整草稿。')
                ->icon('heroicon-o-document-text');

            return $sections;
        }

        $sections[] = Tabs::make('Artifact Versions')
            ->tabs($artifacts->map(fn ($artifact): Tab => Tab::make('草稿 v'.$artifact->version)
                ->badge('#'.$artifact->getKey())
                ->schema([
                    Section::make('完整草稿')
                        ->description(
                            '产物 v'.$artifact->version.' · '.app(DraftLengthPolicy::class)->count($artifact->content)
                            .' 字 / 目标 '.($this->chapter()->latestPlan?->target_words ?? '—').' 字 · '.$artifact->checksum,
                        )
                        ->schema([
                            TextEntry::make('chapter_draft_'.$artifact->getKey())
                                ->hiddenLabel()
                                ->state($artifact->content)
                                ->prose()
                                ->copyable(),
                        ]),
                    Tabs::make('来源场景 '.$artifact->getKey())
                        ->tabs($this->chapter()->scenes->map(fn (Scene $scene): Tab => Tab::make('场景 '.$scene->sequence)
                            ->schema([
                                TextEntry::make('draft_source_scene_'.$artifact->getKey().'_'.$scene->getKey())
                                    ->hiddenLabel()
                                    ->state($scene->currentArtifact?->content)
                                    ->prose()
                                    ->copyable(),
                            ]))->all()),
                ]))->all());

        return $sections;
    }

    private function canAssembleChapter(): bool
    {
        return $this->chapter()->latestPlan !== null
            && $this->chapter()->scenes->isNotEmpty()
            && $this->chapter()->scenes->every(fn (Scene $scene): bool => $this->canUseForAssembly($scene));
    }

    /** @return array<int, mixed> */
    private function canonicalSchema(): array
    {
        $artifact = $this->chapter()->canonicalArtifact;
        $review = $this->canonicalReview();
        $stateVersion = $this->chapter()->latestStateVersion;

        if ($artifact === null) {
            return [
                Section::make('尚无正式章节')
                    ->description('当前内容仍是草稿；只有通过审校和正式提交后才会在这里显示正式正文。')
                    ->icon('heroicon-o-check-badge'),
            ];
        }

        $artifactLabel = match ($artifact->type) {
            ArtifactType::RewriteDraft => '重写稿',
            default => '章节草稿',
        };

        return [
            Section::make('正式章节')
                ->description('正式内容只读，作为后续章节与正式故事状态的依据。')
                ->icon('heroicon-o-check-badge')
                ->columns(['default' => 1, 'md' => 3, 'xl' => 6])
                ->schema([
                    TextEntry::make('canonical_artifact')
                        ->label('正式产物')
                        ->state("{$artifactLabel} v{$artifact->version} · #{$artifact->getKey()}"),
                    TextEntry::make('canonical_at')
                        ->label('正式提交时间')
                        ->state($stateVersion?->created_at?->format('Y-m-d H:i:s'))
                        ->placeholder('—'),
                    TextEntry::make('canonical_word_count')
                        ->label('字数')
                        ->state($this->chapter()->word_count)
                        ->numeric(),
                    TextEntry::make('canonical_state_version')
                        ->label('故事状态版本')
                        ->state($stateVersion === null ? null : 'v'.$stateVersion->version)
                        ->placeholder('—'),
                    TextEntry::make('canonical_review')
                        ->label('审校结果')
                        ->state($review?->decision)
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('canonical_cost')
                        ->label('章节累计成本')
                        ->state(config('ai.cost.currency').' '.number_format($this->canonicalCost(), 4)),
                    TextEntry::make('canonical_memory_count')
                        ->hiddenLabel()
                        ->state('已创建记忆：'.$this->chapterMemoryCount())
                        ->icon('heroicon-o-circle-stack')
                        ->url(MemoryPage::getUrl([
                            'tableFilters' => [
                                'novel_id' => ['value' => $this->chapter()->novel_id],
                                'valid_from_chapter' => ['value' => $this->chapter()->sequence],
                            ],
                        ])),
                ]),
            Section::make('正式正文')
                ->headerActions([
                    Action::make('copyCanonicalContent')
                        ->label(new HtmlString('<span aria-live="polite" x-text="{ idle: \'复制\', copying: \'复制中…\', copied: \'已复制\', failed: \'复制失败\' }[copyState]">复制</span>'))
                        ->icon('heroicon-o-clipboard-document')
                        ->color('gray')
                        ->extraAttributes([
                            'x-data' => "{ copyState: 'idle', resetTimer: null }",
                            'x-bind:aria-label' => "{ idle: '复制', copying: '复制中', copied: '已复制', failed: '复制失败' }[copyState]",
                        ])
                        ->alpineClickHandler(<<<'JS'
                            (async () => {
                                const textarea = document.getElementById('canonical-chapter-content')
                                copyState = 'copying'

                                try {
                                    if (! window.navigator.clipboard?.writeText) {
                                        throw new Error('Clipboard API unavailable')
                                    }

                                    await window.navigator.clipboard.writeText(textarea.value)
                                    copyState = 'copied'
                                } catch (error) {
                                    let copiedWithFallback = false

                                    try {
                                        textarea.focus()
                                        textarea.select()
                                        copiedWithFallback = document.execCommand('copy')
                                        textarea.setSelectionRange(0, 0)
                                        textarea.blur()
                                    } catch (fallbackError) {
                                        copiedWithFallback = false
                                    }

                                    copyState = copiedWithFallback ? 'copied' : 'failed'
                                }

                                window.clearTimeout(resetTimer)
                                resetTimer = window.setTimeout(() => copyState = 'idle', 2000)
                            })()
                            JS),
                ])
                ->schema([
                    View::make('filament.components.read-only-chapter-content')
                        ->viewData(['content' => $artifact->content]),
                ]),
        ];
    }

    private function canonicalReview(): ?Review
    {
        $artifactId = $this->chapter()->canonical_artifact_id;

        if ($artifactId === null) {
            return null;
        }

        return Review::query()
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
            ->with('artifact')
            ->latest('id')
            ->get()
            ->first(fn (Review $review): bool => (int) data_get($review->artifact?->data, 'source_artifact_id') === $artifactId);
    }

    private function canonicalCost(): float
    {
        return (float) UsageRecord::query()
            ->where('chapter_id', $this->chapterId)
            ->sum('estimated_cost');
    }

    private function chapterMemoryCount(): int
    {
        return $this->getRecord()->memories()
            ->where('valid_from_chapter', $this->chapter()->sequence)
            ->count();
    }

    private function canUseForAssembly(Scene $scene): bool
    {
        return $scene->currentArtifact?->type === ArtifactType::SceneDraft
            && in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true);
    }

    private function hasActiveAssemblyRun(): bool
    {
        return $this->chapter()->generationRuns()
            ->where('stage', GenerationStage::ChapterAssembly)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->exists();
    }

    private function latestAssemblyRun()
    {
        return $this->chapter()->generationRuns()
            ->where('stage', GenerationStage::ChapterAssembly)
            ->where('id', '>', $this->currentPlanningRunId())
            ->latest('id')
            ->first();
    }

    private function chapterDraftArtifacts()
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::ChapterDraft)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('chapter_id', $this->chapterId)
                ->where('id', '>', $this->currentPlanningRunId()))
            ->orderByDesc('version')
            ->get();
    }

    /** @return array<int, mixed> */
    private function eventsSchema(): array
    {
        $artifact = $this->latestEventCandidateArtifact();
        $events = collect(data_get($artifact?->data, 'events', []))
            ->map(fn (array $event): array => [
                'status' => '候选',
                'type' => str((string) data_get($event, 'event_type'))->headline()->toString(),
                'subject' => filled(data_get($event, 'subject_type'))
                    ? data_get($event, 'subject_type').' · '.(data_get($event, 'subject_id') ?? '—')
                    : '—',
                'confidence' => number_format((float) data_get($event, 'confidence', 0) * 100, 1).'%',
                'story_time' => data_get($event, 'story_time'),
                'payload' => $this->formatTimelineJson(data_get($event, 'payload', [])),
                'evidence' => collect(data_get($event, 'evidence', []))
                    ->map(fn (array $evidence): string => '产物 #'.data_get($evidence, 'artifact_id').' · '.
                        (data_get($evidence, 'scene_id') === null ? '章节草稿' : '场景 #'.data_get($evidence, 'scene_id')).
                        "\n“".data_get($evidence, 'quote').'”')
                    ->implode("\n\n"),
            ])
            ->all();

        return [
            Section::make('故事事件候选')
                ->key('story-event-candidates')
                ->description('候选事件来自章节草稿；只有经过后续验证、审校与正式提交，才能写入正式故事事件。')
                ->icon('heroicon-o-bolt')
                ->headerActions([
                    Action::make('extractStoryEvents')
                        ->label($artifact === null ? '提取事件' : '重新提取事件')
                        ->icon('heroicon-o-sparkles')
                        ->disabled($this->chapterDraftArtifacts()->isEmpty() || $this->hasActiveEventExtractionRun())
                        ->tooltip(match (true) {
                            $this->chapterDraftArtifacts()->isEmpty() => '需要先完成章节组装。',
                            $this->hasActiveEventExtractionRun() => '故事事件提取正在运行。',
                            default => null,
                        })
                        ->requiresConfirmation($artifact !== null)
                        ->modalDescription($artifact === null ? null : '将创建新的不可变候选产物版本，现有候选不会被覆盖。')
                        ->action(function () use ($artifact): void {
                            ExtractStoryEventsJob::dispatch($this->chapterId, $artifact !== null);

                            Notification::make()
                                ->title('故事事件提取已加入队列')
                                ->body('候选事件不会修改正式故事状态。')
                                ->success()
                                ->send();
                        }),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('event_candidate_status')
                        ->label('数据级别')
                        ->state($artifact === null ? null : '候选')
                        ->badge()
                        ->color('warning')
                        ->placeholder('尚未提取'),
                    TextEntry::make('event_candidate_version')
                        ->label('产物版本')
                        ->state($artifact === null ? null : 'v'.$artifact->version.' · #'.$artifact->getKey())
                        ->placeholder('—'),
                    TextEntry::make('event_candidate_source')
                        ->label('来源产物')
                        ->state($artifact === null ? null : '#'.data_get($artifact->data, 'source_artifact_id'))
                        ->placeholder('—'),
                ]),
            Section::make('尚无候选事件')
                ->description($artifact === null ? '完成章节组装后运行“提取事件”。' : '事件提取已完成，本章没有识别到会改变后续故事状态的事件。')
                ->icon('heroicon-o-information-circle')
                ->visible($events === []),
            Section::make('候选事件')
                ->visible($events !== [])
                ->schema([
                    RepeatableEntry::make('event_candidates')
                        ->hiddenLabel()
                        ->state($events)
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('status')->label('级别')->badge()->color('warning'),
                            TextEntry::make('type')->label('类型')->badge()->color('gray'),
                            TextEntry::make('subject')->label('主体'),
                            TextEntry::make('confidence')->label('置信度'),
                            TextEntry::make('story_time')->label('故事时间')->placeholder('—'),
                            TextEntry::make('payload')->label('数据')->fontFamily('mono')->copyable()->columnSpanFull(),
                            TextEntry::make('evidence')->label('证据')->prose()->copyable()->columnSpanFull(),
                        ]),
                ]),
        ];
    }

    private function latestEventCandidateArtifact(): ?GenerationArtifact
    {
        return $this->latestTimelineArtifact(ArtifactType::EventCandidate);
    }

    private function hasActiveEventExtractionRun(): bool
    {
        return $this->chapter()->generationRuns
            ->where('stage', GenerationStage::EventExtraction)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->isNotEmpty();
    }

    private function formatTimelineJson(mixed $value): string
    {
        return json_encode($value ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @return array<int, mixed> */
    private function stateChangesSchema(): array
    {
        $artifact = $this->latestStatePatchArtifact();
        $candidate = $this->latestEventCandidateArtifact();
        $validation = $artifact === null ? null : app(StateValidator::class)->validate($this->chapterId);

        return [
            Section::make('状态补丁预览')
                ->key('state-patch-preview')
                ->description('根据候选事件确定性计算预期状态变化；此操作不会修改正式故事状态。')
                ->icon('heroicon-o-arrows-right-left')
                ->headerActions([
                    Action::make('buildStatePatch')
                        ->label($artifact === null ? '生成状态补丁' : '重新生成状态补丁')
                        ->icon('heroicon-o-wrench-screwdriver')
                        ->disabled($candidate === null)
                        ->tooltip($candidate === null ? '请先生成故事事件候选。' : null)
                        ->action(function (): void {
                            $artifact = app(StatePatchBuilder::class)->build($this->chapterId);
                            $this->cacheSchema('content', null);

                            Notification::make()
                                ->title('状态补丁已生成')
                                ->body('产物 v'.$artifact->version.'，正式故事状态未被修改。')
                                ->success()
                                ->send();
                        }),
                ])
                ->columns(['default' => 1, 'md' => 4])
                ->schema([
                    TextEntry::make('patch_status')
                        ->label('状态')
                        ->state(fn (): string => $artifact === null ? '尚未生成' : '候选')
                        ->badge()
                        ->color(fn (): string => $artifact === null ? 'gray' : 'warning'),
                    TextEntry::make('patch_version')
                        ->label('产物')
                        ->state(fn (): ?string => $artifact === null ? null : 'v'.$artifact->version)
                        ->placeholder('—'),
                    TextEntry::make('expected_state_version')
                        ->label('预期状态版本')
                        ->state(fn (): ?string => $artifact === null ? null : 'v'.data_get($artifact->data, 'expected_state_version'))
                        ->placeholder('—'),
                    TextEntry::make('source_event_artifact')
                        ->label('来源事件')
                        ->state(fn (): ?string => $artifact === null ? null : '#'.data_get($artifact->data, 'source_artifact_id'))
                        ->placeholder('—'),
                ]),
            Section::make('尚无正式状态变化')
                ->description($candidate === null ? '请先生成故事事件候选。' : '点击“生成状态补丁”查看本章提交后会改变什么。')
                ->icon('heroicon-o-document-magnifying-glass')
                ->visible($artifact === null),
            Section::make('预期状态变化')
                ->description('按确定性事件应用器生成；变更前和变更后均为只读预览。')
                ->visible($artifact !== null && data_get($artifact?->data, 'changes', []) !== [])
                ->schema([
                    RepeatableEntry::make('state_changes')
                        ->hiddenLabel()
                        ->state(fn (): array => collect(data_get($artifact?->data, 'changes', []))->map(fn (array $change): array => [
                            ...$change,
                            'before_display' => $change['before_missing'] ? '（不存在）' : $this->formatTimelineJson($change['before']),
                            'after_display' => $change['after_missing'] ? '（已删除）' : $this->formatTimelineJson($change['after']),
                            'source_event' => '#'.($change['source_event_index'] + 1).' · '.$change['source_event_type'].' · '.($change['source_subject_type'] ?? '—').' '.($change['source_subject_id'] ?? ''),
                        ])->all())
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 5])
                        ->schema([
                            TextEntry::make('path')->label('路径')->copyable(),
                            TextEntry::make('operation')->label('操作')->badge(),
                            TextEntry::make('before_display')->label('变更前')->fontFamily('mono'),
                            TextEntry::make('after_display')->label('变更后')->fontFamily('mono'),
                            TextEntry::make('source_event')->label('来源事件'),
                        ]),
                ]),
            Section::make('没有状态变化')
                ->description('候选事件没有可由当前确定性事件应用器安全映射的状态变化。')
                ->icon('heroicon-o-check-circle')
                ->visible($artifact !== null && data_get($artifact?->data, 'changes', []) === []),
            Section::make('状态检查结果')
                ->description('确定性规则优先；任何阻断项都会阻止后续正式提交。')
                ->icon('heroicon-o-shield-exclamation')
                ->visible($validation !== null)
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextEntry::make('validation_decision')
                        ->label('结论')
                        ->state(fn (): ?string => $validation?->decision())
                        ->badge()
                        ->color(fn (): string => $validation?->isBlocked() ? 'danger' : 'success'),
                    TextEntry::make('validation_count')
                        ->label('问题数')
                        ->state(fn (): int => count($validation?->findings ?? [])),
                    RepeatableEntry::make('state_findings')
                        ->hiddenLabel()
                        ->visible($validation?->findings !== [])
                        ->columnSpanFull()
                        ->state(fn (): array => collect($validation?->findings ?? [])->map(fn ($finding): array => [
                            ...$finding->toArray(),
                            'severity_label' => $finding->severity->getLabel(),
                            'related_fact' => $finding->relatedFactId === null ? '—' : '#'.$finding->relatedFactId,
                            'related_state' => $finding->relatedStatePath ?? '—',
                            'evidence_display' => $this->formatTimelineJson($finding->evidence),
                        ])->all())
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                        ->schema([
                            TextEntry::make('severity_label')
                                ->label('级别')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'BLOCK' => 'danger',
                                    '计划内例外' => 'info',
                                    default => 'warning',
                                }),
                            TextEntry::make('code')->label('规则代码')->copyable(),
                            TextEntry::make('message')->label('说明'),
                            TextEntry::make('evidence_display')->label('证据')->fontFamily('mono'),
                            TextEntry::make('related_fact')->label('关联事实'),
                            TextEntry::make('related_state')->label('关联状态')->copyable(),
                        ]),
                ]),
            Section::make('正式故事状态版本')
                ->description('这里只展示本章关联的只读正式状态版本；具体差异可在故事状态检查器中查看。')
                ->visible(fn (): bool => $this->chapter()->latestStateVersion !== null)
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('canonical_version')
                        ->label('版本')
                        ->state(fn (): ?string => $this->chapter()->latestStateVersion === null
                            ? null
                            : 'v'.$this->chapter()->latestStateVersion->version)
                        ->badge(),
                    TextEntry::make('checksum')
                        ->label('校验值')
                        ->state(fn (): ?string => $this->chapter()->latestStateVersion?->checksum)
                        ->copyable(),
                    TextEntry::make('created_at')
                        ->label('创建时间')
                        ->state(fn () => $this->chapter()->latestStateVersion?->created_at)
                        ->dateTime(),
                ]),
        ];
    }

    private function latestStatePatchArtifact(): ?GenerationArtifact
    {
        return $this->latestTimelineArtifact(ArtifactType::StatePatch);
    }

    private function futureSection(string $heading, string $description): Section
    {
        return Section::make($heading)
            ->description($description)
            ->icon('heroicon-o-clock');
    }

    /** @return array<int, mixed> */
    private function runsSchema(): array
    {
        return [
            Section::make('尚无生成运行记录')
                ->description('运行章节规划、场景生成或章节组装后，状态和错误会显示在这里。')
                ->icon('heroicon-o-command-line')
                ->visible(fn (): bool => $this->chapter()->generationRuns()->doesntExist()),
            Section::make('生成运行记录')
                ->description('按最近执行顺序展示模型、提示词、状态版本与失败原因。')
                ->visible(fn (): bool => $this->chapter()->generationRuns()->exists())
                ->schema([
                    RepeatableEntry::make('planning_runs')
                        ->hiddenLabel()
                        ->state(fn (): array => $this->chapter()->generationRuns()
                            ->latest('id')
                            ->get()
                            ->map(fn ($run): array => [
                                'run' => '#'.$run->getKey().' · 第 '.$run->attempt.' 次尝试',
                                'status' => $run->status,
                                'model' => $run->model_policy,
                                'prompt_version' => $run->prompt_version,
                                'state_version' => $run->state_version === null ? '—' : 'v'.$run->state_version,
                                'error' => $run->error_code === null ? '—' : $run->error_code.' · '.$run->error_message,
                            ])
                            ->all())
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('run')->label('运行记录'),
                            TextEntry::make('status')->label('状态')->badge(),
                            TextEntry::make('model')->label('模型')->placeholder('—'),
                            TextEntry::make('prompt_version')->label('提示词版本')->placeholder('—'),
                            TextEntry::make('state_version')->label('状态版本'),
                            TextEntry::make('error')->label('错误'),
                        ]),
                ]),
        ];
    }

    private function pipelineTimelineSection(): Section
    {
        $timeline = $this->pipelineTimeline();
        $scenes = array_values(array_filter($timeline, fn (array $item): bool => str_starts_with($item['key'], 'scene-')));
        $beforeScenes = array_values(array_filter($timeline, fn (array $item): bool => in_array($item['key'], ['plan', 'context'], true)));
        $afterScenes = array_values(array_filter($timeline, fn (array $item): bool => ! in_array($item['key'], ['plan', 'context'], true) && ! str_starts_with($item['key'], 'scene-')));

        return Section::make('生成流水线')
            ->description('按实际持久化结果展示章节进度；点击任一阶段查看运行记录、产物与错误详情。')
            ->icon('heroicon-o-list-bullet')
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema(array_map($this->timelineStageSection(...), $beforeScenes)),
                Section::make('场景')
                    ->description($scenes === [] ? '尚未从章节计划同步场景。' : '场景按顺序生成；前序场景完成后才会进入下一场景。')
                    ->icon('heroicon-o-rectangle-stack')
                    ->compact()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->schema(array_map($this->timelineStageSection(...), $scenes)),
                    ]),
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema(array_map($this->timelineStageSection(...), $afterScenes)),
            ]);
    }

    /** @param array<string, mixed> $item */
    private function timelineStageSection(array $item): Section
    {
        return Section::make($item['label'])
            ->key('timeline-stage-'.$item['key'])
            ->description($item['detail'])
            ->icon($this->timelineIcon($item['state']))
            ->compact()
            ->headerActions([
                Action::make('inspectTimeline'.str($item['key'])->studly())
                    ->label('查看详情')
                    ->icon('heroicon-o-chevron-right')
                    ->color($this->timelineColor($item['state']))
                    ->slideOver()
                    ->modalHeading($item['label'].' · '.$item['status'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭')
                    ->infolist($this->timelineDetails($item)),
            ])
            ->schema([
                TextEntry::make('timeline_status_'.$item['key'])
                    ->hiddenLabel()
                    ->state($item['status'])
                    ->badge()
                    ->color($this->timelineColor($item['state'])),
            ]);
    }

    /** @return array<int, mixed> */
    private function timelineDetails(array $item): array
    {
        /** @var GenerationRun|null $run */
        $run = $item['run'];
        /** @var GenerationArtifact|null $artifact */
        $artifact = $item['artifact'];
        $usage = $run?->usageRecords;

        return [
            TextEntry::make('timeline_detail_status_'.$item['key'])->label('状态')->state($item['status'])->badge()->color($this->timelineColor($item['state'])),
            TextEntry::make('timeline_detail_description_'.$item['key'])->label('说明')->state($item['detail']),
            TextEntry::make('timeline_detail_run_'.$item['key'])->label('生成运行记录')->state($run === null ? null : '#'.$run->getKey().' · 第 '.$run->attempt.' 次尝试')->placeholder('尚无运行记录'),
            TextEntry::make('timeline_detail_duration_'.$item['key'])->label('耗时')->state($run?->durationMilliseconds() === null ? null : $run->durationMilliseconds().' ms')->placeholder('—'),
            TextEntry::make('timeline_detail_model_'.$item['key'])->label('模型')->state($run?->model_policy)->placeholder('—'),
            TextEntry::make('timeline_detail_tokens_'.$item['key'])->label('令牌数')->state($usage === null ? null : number_format((int) $usage->sum(fn ($record): int => $record->input_tokens + $record->output_tokens)))->placeholder('—'),
            TextEntry::make('timeline_detail_cost_'.$item['key'])->label('费用')->state($usage === null ? null : config('ai.cost.currency').' '.number_format((float) $usage->sum('estimated_cost'), 4))->placeholder('—'),
            TextEntry::make('timeline_detail_prompt_'.$item['key'])->label('提示词版本')->state($run?->prompt_version)->placeholder('—'),
            TextEntry::make('timeline_detail_state_version_'.$item['key'])->label('状态版本')->state($run?->state_version === null ? null : 'v'.$run->state_version)->placeholder('—'),
            TextEntry::make('timeline_detail_artifact_'.$item['key'])->label('产物')->state($artifact === null ? null : $artifact->type->getLabel().' v'.$artifact->version.' · #'.$artifact->getKey())->placeholder('尚无产物'),
            TextEntry::make('timeline_detail_artifact_content_'.$item['key'])->label('产物内容')->state($artifact?->content)->placeholder('—')->prose()->copyable(),
            TextEntry::make('timeline_detail_context_'.$item['key'])
                ->label('上下文快照')
                ->state($item['key'] !== 'context' || $run?->context_snapshot === null ? null : json_encode($run->context_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->placeholder('—')
                ->fontFamily('mono')
                ->copyable(),
            ...($item['key'] === 'context' && $run?->context_snapshot !== null
                ? ContextInspectorSchema::make(fn (mixed $record): GenerationRun => $run, 'timeline_context_')
                : []),
            TextEntry::make('timeline_detail_error_'.$item['key'])->label('错误')->state($run?->error_code === null ? null : $run->error_code.' · '.$run->error_message)->placeholder('—'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function pipelineTimeline(): array
    {
        $chapter = $this->chapter();
        $items = [];
        $planningRun = $this->latestTimelineRun(GenerationStage::ChapterPlanning);
        $contextRun = $chapter->generationRuns->whereNotNull('context_snapshot')->sortByDesc('id')->first();

        $items[] = $this->timelineItem('plan', '计划', $planningRun, $this->latestTimelineArtifact(ArtifactType::ChapterPlan), $chapter->latestPlan !== null, $chapter->latestPlan === null ? '等待建立章节计划' : '计划 v'.$chapter->latestPlan->version);
        $items[] = $this->timelineItem('context', '上下文', $contextRun, $this->latestTimelineArtifact(ArtifactType::Context), $contextRun !== null, $contextRun === null ? '等待冻结 L0 / L1 / L2 上下文快照' : '上下文快照已冻结');

        foreach ($chapter->scenes as $scene) {
            $run = $scene->generationRuns->sortByDesc('id')->first();
            $complete = $scene->currentArtifact !== null && in_array($scene->status, [SceneStatus::Draft, SceneStatus::Accepted], true);
            $items[] = $this->timelineItem('scene-'.$scene->getKey(), '场景 '.$scene->sequence, $run, $scene->currentArtifact, $complete, $complete ? mb_strlen($scene->currentArtifact->content ?? '').' 字' : $scene->goal);
        }

        $assemblyArtifact = $this->latestTimelineArtifact(ArtifactType::ChapterDraft);
        $eventArtifact = $this->latestTimelineArtifact(ArtifactType::EventCandidate) ?? $this->latestTimelineArtifact(ArtifactType::StatePatch);
        $reviewArtifact = $this->latestTimelineArtifact(ArtifactType::ReviewResult);
        $memoryRun = $this->latestTimelineRun(GenerationStage::MemorySummary) ?? $this->latestTimelineRun(GenerationStage::Embedding);

        $items[] = $this->timelineItem('assembly', '章节组装', $this->latestTimelineRun(GenerationStage::ChapterAssembly), $assemblyArtifact, $assemblyArtifact !== null, $assemblyArtifact === null ? '等待组装章节草稿' : '章节草稿 v'.$assemblyArtifact->version);
        $items[] = $this->timelineItem('events', '事件', $this->latestTimelineRun(GenerationStage::EventExtraction), $eventArtifact, $eventArtifact !== null, $eventArtifact === null ? '等待提取故事事件' : '事件候选已生成');
        $items[] = $this->timelineItem('review', '审校', $this->latestTimelineRun(GenerationStage::Review), $reviewArtifact, $reviewArtifact !== null, $reviewArtifact === null ? '等待审校' : '审校结果已生成');
        $items[] = $this->timelineItem('commit', '正式提交', $this->latestTimelineRun(GenerationStage::Commit), null, $chapter->canonical_artifact_id !== null || $chapter->latestStateVersion !== null, $chapter->latestStateVersion === null ? '等待正式提交' : '已提交状态 v'.$chapter->latestStateVersion->version);
        $items[] = $this->timelineItem('memory', '记忆', $memoryRun, $this->latestTimelineArtifact(ArtifactType::Summary), $memoryRun?->status === RunStatus::Succeeded, $memoryRun === null ? '等待正式章节写入记忆' : '记忆更新'.$memoryRun->status->getLabel());

        $blocked = false;
        $currentAssigned = false;

        foreach ($items as &$item) {
            if ($blocked && $item['state'] === 'waiting') {
                $item['state'] = 'waiting';
                $item['status'] = '等待中';

                continue;
            }

            if (in_array($item['state'], ['failed', 'running'], true)) {
                $blocked = true;
                $currentAssigned = true;

                continue;
            }

            if (! $currentAssigned && $item['state'] === 'waiting') {
                $item['state'] = 'current';
                $item['status'] = '当前阶段';
                $currentAssigned = true;
                $blocked = true;
            }
        }
        unset($item);

        return $items;
    }

    /** @return array<string, mixed> */
    private function timelineItem(string $key, string $label, ?GenerationRun $run, ?GenerationArtifact $artifact, bool $complete, string $detail): array
    {
        $state = match ($run?->status) {
            RunStatus::Failed, RunStatus::Cancelled => 'failed',
            RunStatus::Queued, RunStatus::Running => 'running',
            default => $complete ? 'completed' : 'waiting',
        };

        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'status' => match ($state) {
                'completed' => '已完成',
                'running' => $run?->status->getLabel() ?? '运行中',
                'failed' => $run?->status->getLabel() ?? '失败',
                default => '等待中',
            },
            'detail' => $detail,
            'run' => $run,
            'artifact' => $artifact,
        ];
    }

    private function latestTimelineRun(GenerationStage $stage): ?GenerationRun
    {
        return $this->chapter()->generationRuns
            ->where('id', '>=', $this->currentPlanningRunId())
            ->where('stage', $stage)
            ->sortByDesc('id')
            ->first();
    }

    private function latestTimelineArtifact(ArtifactType $type): ?GenerationArtifact
    {
        return $this->chapter()->generationRuns
            ->where('id', '>=', $this->currentPlanningRunId())
            ->flatMap->artifacts
            ->where('type', $type)
            ->sortByDesc('version')
            ->first();
    }

    private function timelineColor(string $state): string
    {
        return match ($state) {
            'completed' => 'success',
            'running', 'current' => 'primary',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    private function timelineIcon(string $state): string
    {
        return match ($state) {
            'completed' => 'heroicon-o-check-circle',
            'running' => 'heroicon-o-arrow-path',
            'current' => 'heroicon-o-play-circle',
            'failed' => 'heroicon-o-exclamation-triangle',
            default => 'heroicon-o-clock',
        };
    }

    private function chapter(): Chapter
    {
        return $this->cachedChapter ??= Chapter::query()
            ->whereKey($this->chapterId)
            ->where('novel_id', $this->getRecord()->getKey())
            ->with([
                'volume:id,sequence,title',
                'latestPlan.povCharacter:id,name',
                'scenes.povCharacter:id,name',
                'scenes.currentArtifact',
                'scenes.generationRuns.usageRecords',
                'scenes.generationRuns.artifacts',
                'generationRuns.usageRecords',
                'generationRuns.artifacts',
                'canonicalArtifact',
                'latestStateVersion',
            ])
            ->firstOrFail();
    }

    private function hasActivePlanningRun(): bool
    {
        return $this->chapter()->generationRuns()
            ->where('stage', GenerationStage::ChapterPlanning)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->exists();
    }

    private function currentDraftArtifact(): ?GenerationArtifact
    {
        return $this->chapter()->generationRuns
            ->whereNull('scene_id')
            ->where('id', '>', $this->currentPlanningRunId())
            ->flatMap->artifacts
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->sortByDesc('id')
            ->first();
    }

    private function currentPlanningRunId(): int
    {
        return $this->cachedPlanningRunId ??= (int) $this->chapter()->generationRuns()
            ->where('stage', GenerationStage::ChapterPlanning)
            ->where('status', RunStatus::Succeeded)
            ->latest('id')
            ->value('id');
    }
}
