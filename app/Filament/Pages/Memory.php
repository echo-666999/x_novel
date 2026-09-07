<?php

namespace App\Filament\Pages;

use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Enums\RunStatus;
use App\Jobs\GenerateEmbeddingJob;
use App\Models\Memory as MemoryModel;
use App\Models\Novel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class Memory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = '记忆';

    protected static ?string $title = '记忆';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
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
