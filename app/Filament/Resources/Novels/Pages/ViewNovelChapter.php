<?php

namespace App\Filament\Resources\Novels\Pages;

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
use Illuminate\Validation\ValidationException;

class ViewNovelChapter extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = '章节详情';

    public int $chapterId;

    protected ?Chapter $cachedChapter = null;

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
                ->disabled(fn (): bool => $this->hasActivePlanningRun())
                ->tooltip(fn (): ?string => $this->hasActivePlanningRun() ? '章节规划正在运行。' : null)
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
                ->visible(fn (): bool => $this->latestReview()?->decision === ReviewDecision::Pass
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
                    Tab::make('正式版本')
                        ->icon('heroicon-o-check-badge')
                        ->badge(fn (): string => $this->chapter()->canonicalArtifact === null ? '未提交' : '正式')
                        ->schema($this->canonicalSchema()),
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
        return [
            Section::make('叙事审校')
                ->description('七维评分结合确定性状态检查结果形成最终审校决策。')
                ->headerActions([
                    Action::make('rewriteScene')
                        ->label('重写场景')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn (): bool => in_array($this->latestReview()?->decision, [ReviewDecision::Rewrite, ReviewDecision::NeedsAttention], true))
                        ->disabled(fn (): bool => $this->rewriteArtifacts()->count() >= (int) config('generation.max_rewrite_attempts', 2))
                        ->schema([
                            Select::make('scene_id')->label('场景')->required()->options(fn (): array => $this->chapter()->scenes->mapWithKeys(fn (Scene $scene): array => [$scene->getKey() => '场景 '.$scene->sequence.' · '.$scene->goal])->all()),
                        ])
                        ->action(function (array $data): void {
                            RewriteChapterJob::dispatch($this->chapterId, (int) $data['scene_id']);
                            Notification::make()->title('场景重写已加入队列')->success()->send();
                        }),
                    Action::make('rewriteChapter')
                        ->label('重写章节')
                        ->icon('heroicon-o-document-text')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => in_array($this->latestReview()?->decision, [ReviewDecision::Rewrite, ReviewDecision::NeedsAttention], true))
                        ->disabled(fn (): bool => $this->rewriteArtifacts()->count() >= (int) config('generation.max_rewrite_attempts', 2))
                        ->action(function (): void {
                            RewriteChapterJob::dispatch($this->chapterId);
                            Notification::make()->title('章节重写已加入队列')->success()->send();
                        }),
                    Action::make('runReview')
                        ->label(fn (): string => $this->latestReview() ? '重新审校' : '开始审校')
                        ->icon('heroicon-o-shield-check')
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
                    RepeatableEntry::make('review_findings')->hiddenLabel()->state(fn () => $this->latestReview()?->findings ?? [])->schema([
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
            Section::make('草稿 / 重写差异')
                ->description('对照重写前后正文，并根据后续审校标记问题是否已解决。')
                ->visible(fn (): bool => $this->rewriteArtifacts()->isNotEmpty())
                ->schema([
                    View::make('filament.components.draft-rewrite-diff')
                        ->viewData(fn (): array => ['diff' => app(DraftRewriteDiff::class)->forChapter($this->chapterId)]),
                ]),
        ];
    }

    private function latestReview(): ?Review
    {
        return Review::query()->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))->with('artifact')->latest('id')->first();
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
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
            ->orderBy('id')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function rewriteVersionRows(): array
    {
        $original = GenerationArtifact::query()->where('type', ArtifactType::ChapterDraft)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
            ->oldest('id')->first();
        $rows = $original === null ? [] : [['label' => 'Original', 'scope' => '整章', 'content' => $original->content]];
        foreach ($this->rewriteArtifacts() as $index => $artifact) {
            $rows[] = ['label' => 'Rewrite #'.($index + 1), 'scope' => data_get($artifact->data, 'scope') === 'scene' ? 'Scene' : '整章', 'content' => $artifact->content];
        }

        return $rows;
    }

    /** @return array<int, mixed> */
    private function overviewSchema(): array
    {
        return [
            Section::make('章节概览')
                ->description('从计划到正式状态的单章工作入口。')
                ->columns(['default' => 1, 'md' => 3, 'xl' => 6])
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
                    TextEntry::make('word_count')
                        ->label('字数')
                        ->state(fn (): int => $this->chapter()->word_count)
                        ->numeric(),
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

        return Section::make('场景 '.$scene->sequence)
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
                    ->action(fn () => $this->dispatchScene($scene, true)),
                Action::make('viewSceneArtifact'.$scene->getKey())
                    ->label('查看产物')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible($artifact !== null)
                    ->modalHeading('场景 '.$scene->sequence.' · 草稿产物')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭')
                    ->infolist([
                        TextEntry::make('scene_artifact_content_'.$scene->getKey())
                            ->label('正文')
                            ->state($artifact?->content)
                            ->prose()
                            ->copyable(),
                        TextEntry::make('scene_artifact_delta_'.$scene->getKey())
                            ->label('临时状态变化')
                            ->state(fn (): string => json_encode(
                                data_get($artifact?->data, 'temporary_state_delta', []),
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ) ?: '{}')
                            ->fontFamily('mono')
                            ->copyable(),
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
            ->columns(['default' => 2, 'md' => 4, 'xl' => 8])
            ->schema([
                TextEntry::make('scene_status_'.$scene->getKey())->label('状态')->state($scene->status)->badge(),
                TextEntry::make('scene_words_'.$scene->getKey())->label('字数')->state(mb_strlen($artifact?->content ?? ''))->numeric(),
                TextEntry::make('scene_duration_'.$scene->getKey())
                    ->label('耗时')
                    ->state($run?->durationMilliseconds() === null ? null : $run->durationMilliseconds().' ms')
                    ->placeholder('—'),
                TextEntry::make('scene_cost_'.$scene->getKey())
                    ->label('成本')
                    ->state($run === null ? null : config('ai.cost.currency').' '.number_format((float) $run->usageRecords->sum('estimated_cost'), 6))
                    ->placeholder('—'),
                TextEntry::make('scene_pov_'.$scene->getKey())->label('视角角色')->state($scene->povCharacter?->name)->placeholder('未指定'),
                TextEntry::make('scene_location_'.$scene->getKey())->label('地点')->state($scene->location)->placeholder('未指定'),
                TextEntry::make('scene_conflict_'.$scene->getKey())->label('冲突')->state($scene->conflict),
                TextEntry::make('scene_outcome_'.$scene->getKey())->label('结果')->state($scene->outcome),
            ]);
    }

    private function dispatchScene(Scene $scene, bool $regenerate = false): void
    {
        GenerateSceneJob::dispatch($scene->getKey(), $regenerate);

        Notification::make()
            ->title($regenerate ? '场景重试已加入队列' : '场景已加入生成队列')
            ->body('场景 '.$scene->sequence.' 将在生成队列中执行。')
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
                ->columns(['default' => 1, 'md' => 3])
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
                        ->description('产物 v'.$artifact->version.' · '.$artifact->checksum)
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
                        ->state(config('ai.cost.currency').' '.number_format($this->canonicalCost(), 6)),
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
                ->schema([
                    TextEntry::make('canonical_content')
                        ->hiddenLabel()
                        ->state($artifact->content)
                        ->prose()
                        ->copyable(),
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
            ->latest('id')
            ->first();
    }

    private function chapterDraftArtifacts()
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::ChapterDraft)
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $this->chapterId))
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
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 5])
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
            TextEntry::make('timeline_detail_cost_'.$item['key'])->label('费用')->state($usage === null ? null : config('ai.cost.currency').' '.number_format((float) $usage->sum('estimated_cost'), 6))->placeholder('—'),
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
            ->where('stage', $stage)
            ->sortByDesc('id')
            ->first();
    }

    private function latestTimelineArtifact(ArtifactType $type): ?GenerationArtifact
    {
        return $this->chapter()->generationRuns
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
}
