<?php

namespace App\Filament\Pages;

use App\AI\Exceptions\AiProviderException;
use App\Data\MemoryQuery;
use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Enums\RunStatus;
use App\Jobs\GenerateEmbeddingJob;
use App\Models\Memory as MemoryModel;
use App\Models\Novel;
use App\Services\MemoryQueryBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class Memory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = '记忆';

    protected static ?string $title = '记忆';

    /** @var array<string, mixed> */
    public array $retrieval = [];

    /** @var array<int, array<string, mixed>> */
    public array $retrievalResults = [];

    public function mount(): void
    {
        $this->retrieval = [
            'novel_id' => null,
            'query' => '',
            'types' => [],
            'chapter_from' => null,
            'chapter_to' => null,
            'candidate_k' => (int) config('context.memory_candidate_k', 30),
            'final_k' => (int) config('context.memory_final_k', 10),
        ];
    }

    public function retrievalForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('retrieval')
            ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->components([
                Select::make('novel_id')
                    ->label('小说')
                    ->options(fn (): array => Novel::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('types')
                    ->label('记忆类型')
                    ->options(MemoryType::class)
                    ->multiple(),
                TextInput::make('chapter_from')->label('起始章节')->integer()->minValue(1),
                TextInput::make('chapter_to')->label('结束章节')->integer()->minValue(1),
                Textarea::make('query')
                    ->label('查询内容')
                    ->placeholder('输入场景目标、人物、地点、冲突或需要回忆的历史信息。')
                    ->rows(3)
                    ->maxLength(5_000)
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('candidate_k')
                    ->label('候选数量')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(100)
                    ->required(),
                TextInput::make('final_k')
                    ->label('结果数量')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(30)
                    ->required(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('记忆检索检查器')
                ->description('先按小说、状态、模型和筛选条件限定范围，再使用向量相似度返回长期记忆。')
                ->schema([
                    Form::make([EmbeddedSchema::make('retrievalForm')])
                        ->id('memory-retrieval-form')
                        ->livewireSubmitHandler('runRetrieval')
                        ->footer([
                            Actions::make([
                                Action::make('runRetrieval')
                                    ->label('执行检索')
                                    ->icon('heroicon-o-magnifying-glass')
                                    ->submit('runRetrieval'),
                            ]),
                        ]),
                    RepeatableEntry::make('retrieval_results')
                        ->label('最相似结果')
                        ->state(fn (): array => $this->retrievalResults)
                        ->schema([
                            Grid::make(['default' => 1, 'md' => 4])->schema([
                                TextEntry::make('summary')->label('记忆摘要')->columnSpan(['md' => 2])->wrap(),
                                TextEntry::make('similarity')
                                    ->label('相似度')
                                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 4))
                                    ->badge()
                                    ->color('info'),
                                TextEntry::make('salience')
                                    ->label('显著度')
                                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 3)),
                                TextEntry::make('type')->label('类型')->badge(),
                                TextEntry::make('source')->label('来源'),
                                TextEntry::make('source_chapter')
                                    ->label('来源章节')
                                    ->formatStateUsing(fn (mixed $state): string => '第 '.(int) $state.' 章'),
                            ]),
                        ])
                        ->visible(fn (): bool => $this->retrievalResults !== []),
                    TextEntry::make('retrieval_empty')
                        ->hiddenLabel()
                        ->state('尚未执行检索，或当前筛选条件下没有匹配的向量记忆。')
                        ->color('gray')
                        ->visible(fn (): bool => $this->retrievalResults === []),
                ])
                ->collapsible(),
            EmbeddedTable::make(),
        ]);
    }

    public function runRetrieval(MemoryQueryBuilder $queryBuilder): void
    {
        $data = $this->retrievalForm->getState();

        try {
            $this->retrievalResults = $queryBuilder->search(new MemoryQuery(
                novelId: (int) $data['novel_id'],
                queryText: (string) $data['query'],
                types: $data['types'] ?? [],
                chapterFrom: filled($data['chapter_from'] ?? null) ? (int) $data['chapter_from'] : null,
                chapterTo: filled($data['chapter_to'] ?? null) ? (int) $data['chapter_to'] : null,
                candidateK: (int) $data['candidate_k'],
                finalK: (int) $data['final_k'],
            ))->map(fn ($result): array => $result->toArray())->all();

            Notification::make()
                ->title('记忆检索完成')
                ->body('返回 '.count($this->retrievalResults).' 条结果。')
                ->success()
                ->send();
        } catch (AiProviderException|InvalidArgumentException $exception) {
            $this->retrievalResults = [];
            Notification::make()->title('记忆检索失败')->body($exception->getMessage())->danger()->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MemoryModel::query())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['novel:id,title', 'embeddingRun']))
            ->columns([
                TextColumn::make('novel.title')->label('小说')->searchable()->weight('medium'),
                TextColumn::make('type')->label('类型')->badge(),
                TextColumn::make('summary')->label('摘要')->searchable()->wrap()->limit(100),
                TextColumn::make('salience')->label('显著度')->numeric(3)->sortable()->alignEnd(),
                TextColumn::make('source')
                    ->label('来源')
                    ->state(fn (MemoryModel $record): string => $record->sourceLabel())
                    ->description(fn (MemoryModel $record): string => $record->source_type),
                TextColumn::make('status')->label('状态')->badge(),
                TextColumn::make('embedding_status')
                    ->label('向量状态')
                    ->state(fn (MemoryModel $record): string => $this->embeddingStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        '向量已就绪' => 'success',
                        '生成失败' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (MemoryModel $record): ?string => $record->embedding_model),
            ])
            ->recordActions([
                Action::make('retryEmbedding')
                    ->label('重试向量化')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (MemoryModel $record): bool => $record->embeddingRun?->status === RunStatus::Failed)
                    ->action(function (MemoryModel $record): void {
                        GenerateEmbeddingJob::dispatch($record->getKey());
                        Notification::make()->title('向量化任务已重新排队')->success()->send();
                    }),
            ])
            ->filters([
                SelectFilter::make('novel_id')
                    ->label('小说')
                    ->options(fn (): array => Novel::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')->label('类型')->options(MemoryType::class),
                SelectFilter::make('valid_from_chapter')
                    ->label('来源章节')
                    ->options(fn (): array => MemoryModel::query()
                        ->select('valid_from_chapter')
                        ->distinct()
                        ->orderBy('valid_from_chapter')
                        ->pluck('valid_from_chapter', 'valid_from_chapter')
                        ->mapWithKeys(fn (mixed $sequence): array => [(int) $sequence => '第 '.(int) $sequence.' 章'])
                        ->all()),
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(MemoryStatus::class)
                    ->default(MemoryStatus::Active->value),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('暂无已索引记忆')
            ->emptyStateDescription('Canonical Chapter 生成的长期记忆会显示在这里。')
            ->emptyStateIcon('heroicon-o-circle-stack');
    }

    private function embeddingStatus(MemoryModel $memory): string
    {
        if ($memory->hasEmbedding()) {
            return '向量已就绪';
        }

        return $memory->embeddingRun?->status === RunStatus::Failed ? '生成失败' : '待生成';
    }
}
