<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Jobs\AssembleChapterJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\Scene;
use App\Services\PlanValidator;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ViewNovelChapter extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = 'Chapter Detail';

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
        return $this->getRecord()->title.' · Chapter Detail 工作台';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generatePlan')
                ->label('AI Generate Plan')
                ->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => $this->chapter()->latestPlan === null)
                ->disabled(fn (): bool => $this->hasActivePlanningRun())
                ->tooltip(fn (): ?string => $this->hasActivePlanningRun() ? 'Chapter Planning 已在运行。' : null)
                ->action(function (): void {
                    PlanChapterJob::dispatch($this->chapterId);

                    Notification::make()
                        ->title('Chapter Plan 已加入生成队列')
                        ->body('可在 Runs 页签查看执行状态。')
                        ->success()
                        ->send();
                }),
            Action::make('regeneratePlan')
                ->label('Regenerate Plan')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->chapter()->latestPlan !== null)
                ->disabled(fn (): bool => $this->hasActivePlanningRun())
                ->tooltip(fn (): ?string => $this->hasActivePlanningRun() ? 'Chapter Planning 已在运行。' : null)
                ->requiresConfirmation()
                ->modalDescription('将生成新的 Plan 版本；当前版本与 Artifact 会保留用于追踪。')
                ->action(function (): void {
                    PlanChapterJob::dispatch($this->chapterId, true);

                    Notification::make()
                        ->title('Chapter Plan 重新生成已排队')
                        ->body('可在 Runs 页签查看执行状态。')
                        ->success()
                        ->send();
                }),
            Action::make('planningPreview')
                ->label('Planning Preview')
                ->icon('heroicon-o-eye')
                ->visible(fn (): bool => $this->chapter()->latestPlan !== null)
                ->url(fn (): string => NovelResource::getUrl('planning-preview', [
                    'record' => $this->getRecord(),
                    'chapter' => $this->chapter(),
                ])),
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
            Tabs::make('Chapter Workspace')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Overview')
                        ->icon('heroicon-o-squares-2x2')
                        ->schema($this->overviewSchema()),
                    Tab::make('Plan')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->badge(fn (): string => $this->chapter()->latestPlan === null ? '未建立' : 'v'.$this->chapter()->latestPlan->version)
                        ->schema($this->planSchema()),
                    Tab::make('Scenes')
                        ->icon('heroicon-o-rectangle-stack')
                        ->badge(fn (): int => $this->chapter()->scenes->count())
                        ->schema($this->scenesSchema()),
                    Tab::make('Draft')
                        ->icon('heroicon-o-document-text')
                        ->badge(fn (): int => $this->chapterDraftArtifacts()->count())
                        ->schema($this->draftSchema()),
                    Tab::make('Events')
                        ->icon('heroicon-o-bolt')
                        ->schema([$this->futureSection('Story Events 尚未接入', '正式 Story Events 必须由通过 Review 的 Canonical Chapter 产生。')]),
                    Tab::make('Review')
                        ->icon('heroicon-o-shield-check')
                        ->schema([$this->futureSection('Review 尚未接入', 'Review 结果和人工处理动作将在后续 Review 工作流任务中接入。')]),
                    Tab::make('State Changes')
                        ->icon('heroicon-o-arrows-right-left')
                        ->schema($this->stateChangesSchema()),
                    Tab::make('Runs')
                        ->icon('heroicon-o-command-line')
                        ->badge(fn (): int => $this->chapter()->generationRuns()->count())
                        ->schema($this->runsSchema()),
                ]),
        ]);
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
                        ->label('Chapter Status')
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
                        ->label('Plan')
                        ->state(fn () => $this->chapter()->latestPlan?->status)
                        ->badge()
                        ->placeholder('未建立'),
                    TextEntry::make('scene_count')
                        ->label('Scenes')
                        ->state(fn (): int => $this->chapter()->scenes->count()),
                    TextEntry::make('state_version')
                        ->label('State Version')
                        ->state(fn (): ?string => $this->chapter()->latestStateVersion === null
                            ? null
                            : 'v'.$this->chapter()->latestStateVersion->version)
                        ->placeholder('—'),
                ]),
            Section::make('流水线状态')
                ->description('当前阶段只汇总已经落地的 Chapter、Plan、Scene 与 Story State 数据。')
                ->schema([
                    RepeatableEntry::make('pipeline')
                        ->label('')
                        ->state(fn (): array => $this->pipelineStages())
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('stage')->label('阶段'),
                            TextEntry::make('status')->label('状态')->badge(),
                            TextEntry::make('detail')->label('说明'),
                        ]),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function planSchema(): array
    {
        return [
            Section::make('尚未建立 Chapter Plan')
                ->description('返回章节列表建立 Plan 后，这里会显示生成执行基线。')
                ->icon('heroicon-o-clipboard-document-list')
                ->visible(fn (): bool => $this->chapter()->latestPlan === null),
            Section::make('章节目标')
                ->description('完整约束与校验结果可通过右上角 Planning Preview 查看。')
                ->visible(fn (): bool => $this->chapter()->latestPlan !== null)
                ->columns(['default' => 1, 'lg' => 3])
                ->schema([
                    TextEntry::make('chapter_function')
                        ->label('Chapter Function')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->chapter_function),
                    TextEntry::make('arc_contribution')
                        ->label('Arc Contribution')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->arc_contribution),
                    TextEntry::make('reader_promise')
                        ->label('Reader Promise')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->reader_promise),
                    TextEntry::make('pov')
                        ->label('POV')
                        ->state(fn (): ?string => $this->chapter()->latestPlan?->povCharacter?->name)
                        ->placeholder('未指定'),
                    TextEntry::make('tone')
                        ->label('语气 / 钩子')
                        ->state(fn (): ?string => $this->chapter()->latestPlan === null
                            ? null
                            : $this->chapter()->latestPlan->tone.' · '.$this->chapter()->latestPlan->hook_type),
                    TextEntry::make('validation')
                        ->label('Plan Findings')
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
            Section::make('尚未同步 Scenes')
                ->description('先在章节列表保存 Chapter Plan，再使用“同步 Scenes”。')
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

        return Section::make('Scene '.$scene->sequence)
            ->description($scene->goal)
            ->headerActions([
                Action::make('generateScene'.$scene->getKey())
                    ->label('Generate')
                    ->icon('heroicon-o-play')
                    ->visible($scene->status === SceneStatus::Planned)
                    ->disabled(! $this->canGenerateScene($scene))
                    ->tooltip(! $this->canGenerateScene($scene) ? '请等待前序 Scene 成功。' : null)
                    ->action(fn () => $this->dispatchScene($scene)),
                Action::make('retryScene'.$scene->getKey())
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible($scene->status === SceneStatus::Failed)
                    ->disabled(! $this->canGenerateScene($scene))
                    ->tooltip(! $this->canGenerateScene($scene) ? '请等待前序 Scene 成功。' : null)
                    ->action(fn () => $this->dispatchScene($scene, true)),
                Action::make('viewSceneArtifact'.$scene->getKey())
                    ->label('View Artifact')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible($artifact !== null)
                    ->modalHeading('Scene '.$scene->sequence.' · Draft Artifact')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭')
                    ->infolist([
                        TextEntry::make('scene_artifact_content_'.$scene->getKey())
                            ->label('正文')
                            ->state($artifact?->content)
                            ->prose()
                            ->copyable(),
                        TextEntry::make('scene_artifact_delta_'.$scene->getKey())
                            ->label('Temporary State Delta')
                            ->state(fn (): string => json_encode(
                                data_get($artifact?->data, 'temporary_state_delta', []),
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ) ?: '{}')
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),
                Action::make('viewSceneRun'.$scene->getKey())
                    ->label('View Run')
                    ->icon('heroicon-o-command-line')
                    ->color('gray')
                    ->visible($run !== null)
                    ->modalHeading('Scene '.$scene->sequence.' · Generation Run')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭')
                    ->infolist([
                        TextEntry::make('scene_run_status_'.$scene->getKey())->label('状态')->state($run?->status)->badge(),
                        TextEntry::make('scene_run_model_'.$scene->getKey())->label('模型')->state($run?->model_policy)->placeholder('—'),
                        TextEntry::make('scene_run_prompt_'.$scene->getKey())->label('Prompt Version')->state($run?->prompt_version)->placeholder('—'),
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
                TextEntry::make('scene_pov_'.$scene->getKey())->label('POV')->state($scene->povCharacter?->name)->placeholder('未指定'),
                TextEntry::make('scene_location_'.$scene->getKey())->label('地点')->state($scene->location)->placeholder('未指定'),
                TextEntry::make('scene_conflict_'.$scene->getKey())->label('冲突')->state($scene->conflict),
                TextEntry::make('scene_outcome_'.$scene->getKey())->label('结果')->state($scene->outcome),
            ]);
    }

    private function dispatchScene(Scene $scene, bool $regenerate = false): void
    {
        GenerateSceneJob::dispatch($scene->getKey(), $regenerate);

        Notification::make()
            ->title($regenerate ? 'Scene 重试已加入队列' : 'Scene 已加入生成队列')
            ->body('Scene '.$scene->sequence.' 将在 generation 队列中执行。')
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
            Section::make('Chapter Assembly')
                ->description('按 Scene 顺序组装完整章节；每次结果保存为新的不可变 Artifact 版本。')
                ->headerActions([
                    Action::make('assembleChapter')
                        ->label('Assemble Chapter')
                        ->icon('heroicon-o-document-plus')
                        ->disabled(! $this->canAssembleChapter() || $this->hasActiveAssemblyRun())
                        ->tooltip(match (true) {
                            $this->hasActiveAssemblyRun() => 'Chapter Assembly 已在运行。',
                            ! $this->canAssembleChapter() => '所有 Scene 成功后才能组装 Chapter。',
                            default => null,
                        })
                        ->action(function (): void {
                            AssembleChapterJob::dispatch($this->chapterId);

                            Notification::make()
                                ->title('Chapter Assembly 已加入队列')
                                ->body('可在 Draft 或 Runs 页签查看执行状态。')
                                ->success()
                                ->send();
                        }),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('assembly_scene_count')
                        ->label('Scenes')
                        ->state($this->chapter()->scenes->count()),
                    TextEntry::make('assembly_ready_count')
                        ->label('已成功')
                        ->state($this->chapter()->scenes->filter(fn (Scene $scene): bool => $this->canUseForAssembly($scene))->count()),
                    TextEntry::make('assembly_status')
                        ->label('Assembly Status')
                        ->state($this->latestAssemblyRun()?->status)
                        ->badge()
                        ->placeholder('尚未运行'),
                ]),
        ];

        if ($artifacts->isEmpty()) {
            $sections[] = Section::make('尚无 Chapter Draft')
                ->description('所有 Scene 成功后，使用 Assemble Chapter 生成完整草稿。')
                ->icon('heroicon-o-document-text');

            return $sections;
        }

        $sections[] = Tabs::make('Artifact Versions')
            ->tabs($artifacts->map(fn ($artifact): Tab => Tab::make('Draft v'.$artifact->version)
                ->badge('#'.$artifact->getKey())
                ->schema([
                    Section::make('完整 Draft')
                        ->description('Artifact v'.$artifact->version.' · '.$artifact->checksum)
                        ->schema([
                            TextEntry::make('chapter_draft_'.$artifact->getKey())
                                ->hiddenLabel()
                                ->state($artifact->content)
                                ->prose()
                                ->copyable(),
                        ]),
                    Tabs::make('Source Scenes '.$artifact->getKey())
                        ->tabs($this->chapter()->scenes->map(fn (Scene $scene): Tab => Tab::make('Scene '.$scene->sequence)
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
    private function stateChangesSchema(): array
    {
        return [
            Section::make('尚无正式 State Changes')
                ->description('只有 Canonical Commit 才能创建新的 Story State Version。')
                ->icon('heroicon-o-arrows-right-left')
                ->visible(fn (): bool => $this->chapter()->latestStateVersion === null),
            Section::make('Canonical State Version')
                ->description('这里只展示本章关联的只读正式状态版本；具体 Diff 继续由 Story State Inspector 查看。')
                ->visible(fn (): bool => $this->chapter()->latestStateVersion !== null)
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('canonical_version')
                        ->label('Version')
                        ->state(fn (): ?string => $this->chapter()->latestStateVersion === null
                            ? null
                            : 'v'.$this->chapter()->latestStateVersion->version)
                        ->badge(),
                    TextEntry::make('checksum')
                        ->label('Checksum')
                        ->state(fn (): ?string => $this->chapter()->latestStateVersion?->checksum)
                        ->copyable(),
                    TextEntry::make('created_at')
                        ->label('创建时间')
                        ->state(fn () => $this->chapter()->latestStateVersion?->created_at)
                        ->dateTime(),
                ]),
        ];
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
            Section::make('尚无 Generation Run')
                ->description('运行 Planning、Scene Generation 或 Assembly 后，状态和错误会显示在这里。')
                ->icon('heroicon-o-command-line')
                ->visible(fn (): bool => $this->chapter()->generationRuns()->doesntExist()),
            Section::make('Generation Runs')
                ->description('按最近执行顺序展示模型、Prompt、State Version 与失败原因。')
                ->visible(fn (): bool => $this->chapter()->generationRuns()->exists())
                ->schema([
                    RepeatableEntry::make('planning_runs')
                        ->label('')
                        ->state(fn (): array => $this->chapter()->generationRuns()
                            ->latest('id')
                            ->get()
                            ->map(fn ($run): array => [
                                'run' => '#'.$run->getKey().' · Attempt '.$run->attempt,
                                'status' => $run->status,
                                'model' => $run->model_policy,
                                'prompt_version' => $run->prompt_version,
                                'state_version' => $run->state_version === null ? '—' : 'v'.$run->state_version,
                                'error' => $run->error_code === null ? '—' : $run->error_code.' · '.$run->error_message,
                            ])
                            ->all())
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('run')->label('Run'),
                            TextEntry::make('status')->label('Status')->badge(),
                            TextEntry::make('model')->label('Model')->placeholder('—'),
                            TextEntry::make('prompt_version')->label('Prompt Version')->placeholder('—'),
                            TextEntry::make('state_version')->label('State Version'),
                            TextEntry::make('error')->label('Error'),
                        ]),
                ]),
        ];
    }

    /** @return array<int, array{stage: string, status: string, detail: string}> */
    private function pipelineStages(): array
    {
        $chapter = $this->chapter();

        return [
            [
                'stage' => 'Plan',
                'status' => $chapter->latestPlan === null ? '未建立' : $chapter->latestPlan->status->getLabel(),
                'detail' => $chapter->latestPlan === null ? '等待手工建立 Chapter Plan' : 'Plan v'.$chapter->latestPlan->version,
            ],
            [
                'stage' => 'Scenes',
                'status' => $chapter->scenes->isEmpty() ? '未同步' : '已同步',
                'detail' => $chapter->scenes->count().' 个 Scene',
            ],
            [
                'stage' => 'Assembly',
                'status' => $this->chapterDraftArtifacts()->isEmpty() ? '尚未组装' : '已生成 Draft',
                'detail' => $this->chapterDraftArtifacts()->count().' 个 Chapter Draft 版本',
            ],
            [
                'stage' => 'Review → Commit',
                'status' => '尚未接入',
                'detail' => '由后续 Generation Pipeline 任务接入',
            ],
        ];
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
