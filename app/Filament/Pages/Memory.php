<?php

namespace App\Filament\Pages;

use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Models\Memory as MemoryModel;
use App\Models\Novel;
use BackedEnum;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('novel:id,title'))
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
                    ->label('Embedding')
                    ->state(fn (MemoryModel $record): string => $record->hasEmbedding() ? '已就绪' : '待生成')
                    ->badge()
                    ->color(fn (string $state): string => $state === '已就绪' ? 'success' : 'gray')
                    ->description(fn (MemoryModel $record): ?string => $record->embedding_model),
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
}
