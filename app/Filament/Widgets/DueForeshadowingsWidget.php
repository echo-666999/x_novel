<?php

namespace App\Filament\Widgets;

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Foreshadowing;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class DueForeshadowingsWidget extends TableWidget
{
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->heading('待处理伏笔')
            ->description('即将到期、到期和逾期')
            ->query($this->attentionQuery())
            ->columns([
                TextColumn::make('novel.title')
                    ->label('小说')
                    ->weight('medium'),
                TextColumn::make('title')
                    ->label('伏笔')
                    ->description(fn (Foreshadowing $record): string => (string) str($record->promised_payoff)->limit(36))
                    ->weight('medium'),
                TextColumn::make('dashboard_attention')
                    ->label('提醒')
                    ->state(fn (Foreshadowing $record): string => $this->attentionLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Overdue', 'Critical Due' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('novel.current_chapter_sequence')
                    ->label('当前章节')
                    ->formatStateUsing(fn (int $state): string => '第 '.$state.' 章')
                    ->placeholder('尚未开始')
                    ->alignEnd(),
                TextColumn::make('due_window')
                    ->label('到期窗口')
                    ->state(fn (Foreshadowing $record): string => "第 {$record->due_from_chapter}–{$record->due_to_chapter} 章")
                    ->alignEnd(),
            ])
            ->recordUrl(fn (Foreshadowing $record): string => NovelResource::getUrl('foreshadowings', [
                'record' => $record->novel,
                'tableSearch' => $record->title,
                'tableFilters' => [
                    'attention' => ['value' => $this->attentionFilter($record)],
                ],
            ]))
            ->paginated(false)
            ->emptyStateHeading('暂无待处理伏笔')
            ->emptyStateDescription('到期、逾期或关键待兑现的伏笔会显示在这里。')
            ->emptyStateIcon('heroicon-o-flag');
    }

    private function attentionQuery(): Builder
    {
        $terminalStatuses = [
            ForeshadowingStatus::PaidOff->value,
            ForeshadowingStatus::Abandoned->value,
        ];

        return Foreshadowing::query()
            ->select('foreshadowings.*')
            ->join('novels', 'novels.id', '=', 'foreshadowings.novel_id')
            ->with('novel')
            ->whereNotIn('foreshadowings.status', $terminalStatuses)
            ->where(function (Builder $query): void {
                $query->where('foreshadowings.status', ForeshadowingStatus::Due)
                    ->orWhere(function (Builder $query): void {
                        $query->whereNotNull('novels.current_chapter_sequence')
                            ->whereColumn('novels.current_chapter_sequence', '>=', 'foreshadowings.due_from_chapter');
                    });
            })
            ->orderByRaw(
                'CASE
                    WHEN current_chapter_sequence > due_to_chapter THEN 0
                    WHEN importance = ? THEN 1
                    ELSE 2
                END',
                [ForeshadowingImportance::Critical->value],
            )
            ->orderBy('foreshadowings.due_to_chapter')
            ->limit(8);
    }

    private function attentionLabel(Foreshadowing $foreshadowing): string
    {
        if ($foreshadowing->isOverdue($foreshadowing->novel->current_chapter_sequence)) {
            return 'Overdue';
        }

        if ($foreshadowing->importance === ForeshadowingImportance::Critical) {
            return 'Critical Due';
        }

        return 'Due Soon';
    }

    private function attentionFilter(Foreshadowing $foreshadowing): string
    {
        return match ($this->attentionLabel($foreshadowing)) {
            'Overdue' => 'overdue',
            'Critical Due' => 'critical',
            default => 'due',
        };
    }
}
