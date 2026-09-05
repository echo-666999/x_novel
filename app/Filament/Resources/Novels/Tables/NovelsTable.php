<?php

namespace App\Filament\Resources\Novels\Tables;

use App\Enums\NovelStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NovelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('标题')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('genre')
                    ->label('题材')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
                TextColumn::make('target_words')
                    ->label('目标字数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('current_chapter_sequence')
                    ->label('当前章节')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : "第 {$state} 章")
                    ->alignEnd(),
                TextColumn::make('updated_at')
                    ->label('更新时间')
                    ->dateTime('Y-m-d H:i')
                    ->sinceTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(NovelStatus::class),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('暂无小说')
            ->emptyStateDescription('创建第一部小说，开始建立长篇故事工作台。')
            ->emptyStateIcon('heroicon-o-book-open')
            ->recordActions([
                EditAction::make()->label('编辑'),
            ]);
    }
}
