<?php

namespace App\Filament\Pages;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Jobs\AssembleChapterJob;
use App\Jobs\ExtractStoryEventsJob;
use App\Jobs\GenerateSceneJob;
use App\Jobs\PlanChapterJob;
use App\Jobs\ReviewChapterJob;
use App\Jobs\RewriteChapterJob;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
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

class Generation extends Page implements HasTable
{
    use InteractsWithTable;

    private const RETRYABLE_ERROR_CODES = [
        'provider_timeout',
        'provider_connection_failed',
        'provider_rate_limited',
        'worker_interrupted',
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
            EmbeddedTable::make(),
        ]);
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
                    ->formatStateUsing(fn (RunStatus $state): string => $state->getLabel())
                    ->badge()
                    ->color(fn (RunStatus $state): string => $state->getColor()),
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
                    ->formatStateUsing(fn (mixed $state): string => config('ai.cost.currency').' '.number_format((float) $state, 6))
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
            Section::make('Context Snapshot')->schema([
                TextEntry::make('context_snapshot')
                    ->hiddenLabel()
                    ->state(fn (GenerationRun $record): string => $this->formatJson($record->context_snapshot))
                    ->fontFamily('mono')
                    ->copyable(),
            ]),
            Section::make('L0 · Hard Constraints')
                ->description('不可因 Token Budget 删除的 Bible、Locked Fact、World Rule 与 Plan 约束。')
                ->visible(fn (GenerationRun $record): bool => data_get($record->context_snapshot, 'l0') !== null)
                ->schema([
                    TextEntry::make('context_l0')
                        ->hiddenLabel()
                        ->state(fn (GenerationRun $record): string => $this->formatJsonValue(data_get($record->context_snapshot, 'l0')))
                        ->fontFamily('mono')
                        ->copyable(),
                ]),
            Section::make('L1 · Current State')
                ->description('来自该 Run 绑定的不可变 Canonical Story State Version。')
                ->visible(fn (GenerationRun $record): bool => data_get($record->context_snapshot, 'l1') !== null)
                ->columns(2)
                ->schema([
                    TextEntry::make('context_state_version')
                        ->label('State Version')
                        ->state(fn (GenerationRun $record): string => 'v'.data_get($record->context_snapshot, 'state_version', $record->state_version ?? '—'))
                        ->badge(),
                    TextEntry::make('context_l1')
                        ->label('Canonical State')
                        ->state(fn (GenerationRun $record): string => $this->formatJsonValue(data_get($record->context_snapshot, 'l1')))
                        ->fontFamily('mono')
                        ->copyable()
                        ->columnSpanFull(),
                ]),
            Section::make('L2 · 近期故事')
                ->description('来自当前章节之前的正式章节摘要与上一章结尾，不使用向量检索。')
                ->visible(fn (GenerationRun $record): bool => data_get($record->context_snapshot, 'l2') !== null)
                ->schema([
                    TextEntry::make('context_l2')
                        ->hiddenLabel()
                        ->state(fn (GenerationRun $record): string => $this->formatJsonValue(data_get($record->context_snapshot, 'l2')))
                        ->fontFamily('mono')
                        ->copyable(),
                ]),
            Section::make('Token Allocation')
                ->description('显示总预算、实际占用和各 Context Section 的分配。')
                ->visible(fn (GenerationRun $record): bool => data_get($record->context_snapshot, 'token_allocation') !== null)
                ->schema([
                    TextEntry::make('context_token_allocation')
                        ->hiddenLabel()
                        ->state(fn (GenerationRun $record): string => $this->formatJsonValue(data_get($record->context_snapshot, 'token_allocation')))
                        ->fontFamily('mono')
                        ->copyable(),
                ]),
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
                            ->formatStateUsing(fn (mixed $state): string => config('ai.cost.currency').' '.number_format((float) $state, 6)),
                    ]),
            ]),
        ];
    }

    /** @param array<string, mixed>|null $value */
    private function formatJson(?array $value): string
    {
        return json_encode($value ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function formatJsonValue(mixed $value): string
    {
        return json_encode($value ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function canRetry(GenerationRun $run): bool
    {
        return $run->status === RunStatus::Failed
            && $this->isRetryableError($run)
            && $run->error_code !== 'worker_interrupted'
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
            'Retry', 'Resume' => 'warning',
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
}
