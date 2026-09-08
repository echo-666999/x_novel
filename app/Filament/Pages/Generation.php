<?php

namespace App\Filament\Pages;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\ReviewDecision;
use App\Enums\RunStatus;
use App\Filament\Pages\Review as ReviewPage;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Support\ContextInspectorSchema;
use App\Jobs\AssembleChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review as ReviewModel;
use App\Services\StalledRunRecoveryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Generation extends Page implements HasTable
{
    use InteractsWithTable;

    private const RETRYABLE_ERROR_CODES = [
        'provider_timeout',
        'provider_connection_failed',
        'provider_rate_limited',
        'worker_interrupted',
        StalledRunRecoveryService::ERROR_CODE,
    ];

    private const REBUILD_ERROR_CODES = [
        'state_version_conflict',
        'stale_context',
        'stale_plan',
        'scene_artifact_conflict',
    ];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = '生成';

    protected static ?string $title = '生成';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('恢复中心')
                ->description('集中查看需要人工处理的生成异常，并从已持久化的 Run、Artifact 和 Review 继续。')
                ->columns(['default' => 2, 'lg' => 4])
                ->schema([
                    TextEntry::make('recovery_failed')
                        ->label('Failed')
                        ->state(fn (): int => $this->recoveryCounts()['failed'])
                        ->badge()
                        ->color(fn (): string => $this->recoveryCounts()['failed'] > 0 ? 'danger' : 'gray'),
                    TextEntry::make('recovery_blocked')
                        ->label('Blocked')
                        ->state(fn (): int => $this->recoveryCounts()['blocked'])
                        ->badge()
                        ->color(fn (): string => $this->recoveryCounts()['blocked'] > 0 ? 'danger' : 'gray'),
                    TextEntry::make('recovery_recoverable')
                        ->label('Recoverable')
                        ->state(fn (): int => $this->recoveryCounts()['recoverable'])
                        ->badge()
                        ->color(fn (): string => $this->recoveryCounts()['recoverable'] > 0 ? 'warning' : 'gray'),
                    TextEntry::make('recovery_needs_attention')
                        ->label('Needs Attention')
                        ->state(fn (): int => $this->recoveryCounts()['needs_attention'])
                        ->badge()
                        ->color(fn (): string => $this->recoveryCounts()['needs_attention'] > 0 ? 'warning' : 'gray'),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resumeNext')
                ->label('Resume')
                ->icon('heroicon-o-play')
                ->visible(fn (): bool => $this->nextRecoverableRun() !== null)
                ->action(fn () => $this->resumeNext()),
            Action::make('retryNext')
                ->label('Retry')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->nextRetryableRun() !== null)
                ->requiresConfirmation()
                ->modalDescription('重新执行最近一个可重试的失败阶段；已经成功的 Artifact 会保留。')
                ->action(fn () => $this->retryNext()),
            Action::make('openReview')
                ->label('Open Review')
                ->icon('heroicon-o-inbox')
                ->color('gray')
                ->url(ReviewPage::getUrl()),
            Action::make('openContext')
                ->label('Open Context')
                ->icon('heroicon-o-document-magnifying-glass')
                ->color('gray')
                ->visible(fn (): bool => $this->contextRun() !== null)
                ->url(fn (): string => static::getUrl([
                    'tableAction' => 'inspect',
                    'tableActionRecord' => $this->contextRun()?->getKey(),
                ])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(GenerationRun::query())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['novel:id,title', 'chapter:id,novel_id,sequence,title'])
                ->withSum('usageRecords', 'estimated_cost'))
            ->columns([
                TextColumn::make('novel.title')->label('小说')->searchable()->weight('medium'),
                TextColumn::make('chapter.sequence')
                    ->label('章节')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '第 '.$state.' 章')
                    ->placeholder('—'),
                TextColumn::make('stage')
                    ->label('阶段')
                    ->formatStateUsing(fn (GenerationStage $state): string => $state->getLabel())
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->label('状态')
                    ->formatStateUsing(fn (RunStatus $state, GenerationRun $record): string => match (true) {
                        $record->error_code === StalledRunRecoveryService::ERROR_CODE => 'Worker 丢失',
                        app(StalledRunRecoveryService::class)->isStalled($record) => '已停滞',
                        default => $state->getLabel(),
                    })
                    ->badge()
                    ->color(fn (RunStatus $state, GenerationRun $record): string => $record->error_code === StalledRunRecoveryService::ERROR_CODE
                        || app(StalledRunRecoveryService::class)->isStalled($record) ? 'danger' : $state->getColor()),
                TextColumn::make('error_code')
                    ->label('错误码')
                    ->badge()
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('model_policy')->label('模型')->placeholder('—'),
                TextColumn::make('duration')
                    ->label('耗时')
                    ->state(fn (GenerationRun $record): ?int => $record->durationMilliseconds())
                    ->formatStateUsing(fn (int $state): string => number_format($state).' ms')
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('usage_records_sum_estimated_cost')
                    ->label('成本')
                    ->formatStateUsing(fn (mixed $state): string => config('ai.cost.currency').' '.number_format((float) $state, 4))
                    ->alignEnd(),
                TextColumn::make('created_at')->label('创建时间')->dateTime('Y-m-d H:i:s')->sortable(),
            ])
            ->filters([
                SelectFilter::make('stage')->label('阶段')->options(collect(GenerationStage::cases())->mapWithKeys(
                    fn (GenerationStage $stage): array => [$stage->value => $stage->getLabel()],
                )),
                SelectFilter::make('status')->label('状态')->options(collect(RunStatus::cases())->mapWithKeys(
                    fn (RunStatus $status): array => [$status->value => $status->getLabel()],
                )),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordAction('inspect')
            ->recordActions([
                ViewAction::make('inspect')
                    ->label('检查')
                    ->icon('heroicon-o-magnifying-glass')
                    ->modalHeading(fn (GenerationRun $record): string => "Run #{$record->getKey()} · {$record->stage->getLabel()}")
                    ->modalDescription(fn (GenerationRun $record): string => $record->novel->title)
                    ->modalWidth('3xl')
                    ->slideOver()
                    ->modalCancelActionLabel('关闭')
                    ->schema($this->runInspectorSchema()),
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (GenerationRun $record): bool => $this->canRetry($record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (GenerationRun $record): string => '将重新执行 '.$record->stage->getLabel().'，成功的其他阶段与 Artifact 会保留。')
                    ->action(fn (GenerationRun $record) => $this->dispatchRun($record, regenerate: true)),
                Action::make('resume')
                    ->label('Resume')
                    ->icon('heroicon-o-play')
                    ->visible(fn (GenerationRun $record): bool => $this->canResume($record))
                    ->action(fn (GenerationRun $record) => $this->dispatchRun($record, regenerate: false)),
                Action::make('recover')
                    ->label('恢复')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->visible(fn (GenerationRun $record): bool => $this->canRecover($record))
                    ->requiresConfirmation()
                    ->modalHeading('恢复 Worker 丢失的任务')
                    ->modalDescription('系统会根据已保存的 Run、Artifact 和章节状态检测恢复点，不会依赖 Redis 中是否仍有原 Job。')
                    ->action(function (GenerationRun $record, StalledRunRecoveryService $recovery): void {
                        $point = $recovery->recover($record);

                        Notification::make()
                            ->title('恢复任务已排队')
                            ->body('恢复点：'.$point->label)
                            ->success()
                            ->send();
                    }),
                Action::make('openChapter')
                    ->label('Open Chapter')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (GenerationRun $record): bool => $record->chapter_id !== null)
                    ->url(fn (GenerationRun $record): string => NovelResource::getUrl('chapter', [
                        'record' => $record->novel_id,
                        'chapter' => $record->chapter_id,
                    ])),
            ])
            ->emptyStateHeading('暂无生成记录')
            ->emptyStateDescription('章节生成开始后，每个流水线阶段都会在这里留下可调试的 Run。')
            ->emptyStateIcon('heroicon-o-bolt');
    }

    /** @return array<int, mixed> */
    private function runInspectorSchema(): array
    {
        return [
            Section::make('Run')->columns(2)->schema([
                TextEntry::make('id')->label('Run ID')->fontFamily('mono')->copyable(),
                TextEntry::make('idempotency_key')->label('Idempotency Key')->fontFamily('mono')->copyable(),
                TextEntry::make('prompt_version')->label('Prompt Version')->placeholder('—'),
                TextEntry::make('input_hash')->label('Input Hash')->fontFamily('mono')->copyable(),
                TextEntry::make('state_version')->label('State Version')->placeholder('—'),
                TextEntry::make('bible_version')->label('Bible Version')->placeholder('—'),
            ]),
            Section::make('原始 Context Snapshot')->schema([
                TextEntry::make('context_snapshot')
                    ->hiddenLabel()
                    ->state(fn (GenerationRun $record): string => $this->formatJson($record->context_snapshot))
                    ->fontFamily('mono')
                    ->copyable(),
            ]),
            ...ContextInspectorSchema::make(fn (GenerationRun $record): GenerationRun => $record),
            Section::make('Artifacts')->schema([
                RepeatableEntry::make('artifacts')
                    ->hiddenLabel()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('type')
                            ->formatStateUsing(fn (mixed $state): string => $state instanceof ArtifactType
                                ? $state->getLabel()
                                : str((string) $state)->headline()->toString())
                            ->badge(),
                        TextEntry::make('version')->label('Version')->numeric(),
                        TextEntry::make('checksum')->fontFamily('mono')->copyable()->columnSpanFull(),
                        TextEntry::make('content')->placeholder('No text content')->fontFamily('mono')->columnSpanFull(),
                        TextEntry::make('artifact_data')
                            ->label('Data')
                            ->state(fn (GenerationArtifact $record): string => $this->formatJson($record->data))
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ]),
            ]),
            Section::make('Error')
                ->visible(fn (GenerationRun $record): bool => filled($record->error_code) || filled($record->error_message))
                ->columns(2)
                ->schema([
                    TextEntry::make('error_code')->label('Error Code')->badge()->color('danger'),
                    TextEntry::make('failure_stage')
                        ->label('失败阶段')
                        ->state(fn (GenerationRun $record): string => $record->stage->getLabel()),
                    TextEntry::make('failed_at')
                        ->label('失败时间')
                        ->state(fn (GenerationRun $record) => $record->finished_at ?? $record->updated_at)
                        ->dateTime('Y-m-d H:i:s'),
                    TextEntry::make('retryable')
                        ->label('可重试')
                        ->state(fn (GenerationRun $record): string => $this->retryabilityLabel($record))
                        ->badge()
                        ->color(fn (GenerationRun $record): string => $this->isRetryableError($record) ? 'warning' : 'gray'),
                    TextEntry::make('recommended_action')
                        ->label('推荐动作')
                        ->state(fn (GenerationRun $record): string => $this->recommendedAction($record))
                        ->badge()
                        ->color(fn (GenerationRun $record): string => $this->recommendedActionColor($record)),
                    TextEntry::make('error_message')->label('错误信息')->columnSpanFull(),
                ]),
            Section::make('Usage')->schema([
                RepeatableEntry::make('usageRecords')
                    ->hiddenLabel()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('model')->label('Provider / Model')
                            ->formatStateUsing(fn (string $state, $record): string => $record->provider.' / '.$state),
                        TextEntry::make('request_id')->label('Request ID')->fontFamily('mono')->placeholder('—'),
                        TextEntry::make('input_tokens')->label('Input Tokens')->numeric(),
                        TextEntry::make('output_tokens')->label('Output Tokens')->numeric(),
                        TextEntry::make('cached_tokens')->label('Cached Tokens')->numeric(),
                        TextEntry::make('latency_ms')->label('Latency')->suffix(' ms')->numeric(),
                        TextEntry::make('estimated_cost')
                            ->label('Cost')
                            ->formatStateUsing(fn (mixed $state): string => config('ai.cost.currency').' '.number_format((float) $state, 4)),
                    ]),
            ]),
        ];
    }

    /** @param array<string, mixed>|null $value */
    private function formatJson(?array $value): string
    {
        return json_encode($value ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function canRetry(GenerationRun $run): bool
    {
        return $run->status === RunStatus::Failed
            && $this->isRetryableError($run)
            && ! in_array($run->error_code, ['worker_interrupted', StalledRunRecoveryService::ERROR_CODE], true)
            && $this->supportsDispatch($run)
            && $this->isLatestRunForScope($run);
    }

    private function canResume(GenerationRun $run): bool
    {
        return $run->status === RunStatus::Failed
            && $run->error_code === 'worker_interrupted'
            && $this->supportsDispatch($run)
            && $this->isLatestRunForScope($run);
    }

    private function canRecover(GenerationRun $run): bool
    {
        return ($run->error_code === StalledRunRecoveryService::ERROR_CODE
                || app(StalledRunRecoveryService::class)->isStalled($run))
            && data_get($run->context_snapshot, 'recovery.claimed_at') === null
            && $this->supportsRecovery($run)
            && $this->isLatestRunForScope($run);
    }

    private function supportsRecovery(GenerationRun $run): bool
    {
        return $run->stage === GenerationStage::SceneGeneration
            ? $run->scene_id !== null
            : $run->chapter_id !== null && in_array($run->stage, [
                GenerationStage::ChapterPlanning,
                GenerationStage::ChapterAssembly,
                GenerationStage::EventExtraction,
                GenerationStage::Review,
                GenerationStage::Rewrite,
                GenerationStage::Commit,
            ], true);
    }

    private function supportsDispatch(GenerationRun $run): bool
    {
        return match ($run->stage) {
            GenerationStage::ChapterPlanning, GenerationStage::ChapterAssembly, GenerationStage::Review, GenerationStage::Rewrite => $run->chapter_id !== null,
            GenerationStage::EventExtraction => $run->chapter_id !== null,
            GenerationStage::SceneGeneration => $run->scene_id !== null,
            default => false,
        };
    }

    private function isLatestRunForScope(GenerationRun $run): bool
    {
        return ! GenerationRun::query()
            ->where('stage', $run->stage)
            ->where('scope_type', $run->scope_type)
            ->where('scope_id', $run->scope_id)
            ->whereKeyNot($run->getKey())
            ->where('id', '>', $run->getKey())
            ->exists();
    }

    private function isRetryableError(GenerationRun $run): bool
    {
        return in_array($run->error_code, self::RETRYABLE_ERROR_CODES, true);
    }

    private function retryabilityLabel(GenerationRun $run): string
    {
        if ($this->isRetryableError($run)) {
            return '是';
        }

        if ($run->error_code === 'provider_request_failed') {
            return '需检查 HTTP 状态';
        }

        return '否';
    }

    private function recommendedAction(GenerationRun $run): string
    {
        if ($run->error_code === StalledRunRecoveryService::ERROR_CODE) {
            return '恢复';
        }

        if ($run->error_code === 'worker_interrupted') {
            return 'Resume';
        }

        if ($this->isRetryableError($run)) {
            return 'Retry';
        }

        if (in_array($run->error_code, self::REBUILD_ERROR_CODES, true)) {
            return '重建 Context 后重试';
        }

        if ($run->error_code === 'novel_paused') {
            return '恢复小说后继续';
        }

        if (str_starts_with((string) $run->error_code, 'budget_')) {
            return '调整预算设置';
        }

        return '检查输入并人工处理';
    }

    private function recommendedActionColor(GenerationRun $run): string
    {
        return match ($this->recommendedAction($run)) {
            'Retry', 'Resume', '恢复' => 'warning',
            '检查输入并人工处理' => 'danger',
            default => 'gray',
        };
    }

    private function dispatchRun(GenerationRun $run, bool $regenerate): void
    {
        match ($run->stage) {
            GenerationStage::ChapterPlanning => PlanChapterJob::dispatch($run->chapter_id, $regenerate),
            GenerationStage::SceneGeneration => GenerateSceneJob::dispatch($run->scene_id, $regenerate),
            GenerationStage::ChapterAssembly => AssembleChapterJob::dispatch($run->chapter_id, $regenerate),
            GenerationStage::EventExtraction => ExtractStoryEventsJob::dispatch($run->chapter_id, $regenerate),
            GenerationStage::Review => ReviewChapterJob::dispatch($run->chapter_id, $regenerate),
            GenerationStage::Rewrite => RewriteChapterJob::dispatch($run->chapter_id, $run->scene_id),
            default => null,
        };

        Notification::make()
            ->title($regenerate ? '失败阶段已重新排队' : '恢复任务已排队')
            ->body($run->stage->getLabel().' 将从现有 Run / Artifact 状态继续。')
            ->success()
            ->send();
    }

    /** @return array{failed: int, blocked: int, recoverable: int, needs_attention: int} */
    private function recoveryCounts(): array
    {
        $runs = $this->latestRuns();

        return [
            'failed' => $runs->where('status', RunStatus::Failed)->count(),
            'blocked' => $this->latestReviews()->where('decision', ReviewDecision::Block)->count(),
            'recoverable' => $runs->filter(fn (GenerationRun $run): bool => $this->canRetry($run) || $this->canResume($run) || $this->canRecover($run))->count(),
            'needs_attention' => $this->latestReviews()->where('decision', ReviewDecision::NeedsAttention)->count(),
        ];
    }

    /** @return Collection<int, GenerationRun> */
    private function latestRuns(): Collection
    {
        return GenerationRun::query()
            ->whereIn('id', DB::table('generation_runs')
                ->selectRaw('MAX(id)')
                ->groupBy('stage', 'scope_type', 'scope_id'))
            ->latest('id')
            ->get();
    }

    /** @return Collection<int, ReviewModel> */
    private function latestReviews(): Collection
    {
        return ReviewModel::query()
            ->whereIn('id', $this->latestReviewIds())
            ->get();
    }

    private function latestReviewIds(): QueryBuilder
    {
        $grammar = DB::connection()->getQueryGrammar();
        $reviews = $grammar->wrapTable('reviews');
        $id = $grammar->wrap('id');

        return DB::table('reviews')
            ->join('generation_runs', 'generation_runs.id', '=', 'reviews.generation_run_id')
            ->whereNotNull('generation_runs.chapter_id')
            ->groupBy('generation_runs.chapter_id')
            ->selectRaw("MAX({$reviews}.{$id})");
    }

    private function nextRetryableRun(): ?GenerationRun
    {
        return $this->latestRuns()->first(fn (GenerationRun $run): bool => $this->canRetry($run));
    }

    private function nextRecoverableRun(): ?GenerationRun
    {
        return $this->latestRuns()->first(fn (GenerationRun $run): bool => $this->canResume($run) || $this->canRecover($run));
    }

    private function contextRun(): ?GenerationRun
    {
        return GenerationRun::query()->whereNotNull('context_snapshot')->latest('id')->first();
    }

    private function retryNext(): void
    {
        $run = $this->nextRetryableRun();
        if ($run !== null) {
            $this->dispatchRun($run, regenerate: true);
        }
    }

    private function resumeNext(): void
    {
        $run = $this->nextRecoverableRun();
        if ($run === null) {
            return;
        }

        if ($this->canRecover($run)) {
            $point = app(StalledRunRecoveryService::class)->recover($run);
            Notification::make()->title('恢复任务已排队')->body('恢复点：'.$point->label)->success()->send();

            return;
        }

        $this->dispatchRun($run, regenerate: false);
    }
}
