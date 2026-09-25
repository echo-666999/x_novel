<?php

namespace App\Filament\Widgets;

use App\Enums\ChapterStatus;
use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\ForeshadowingTimingStatus;
use App\Enums\RunStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\Foreshadowing;
use App\Services\DueForeshadowingQuery;
use App\Services\ForeshadowingLifecycleResolver;
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
            ->description('已进入兑现窗口或逾期')
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
                        '已逾期', '关键 · 兑现窗口' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('status')
                    ->label('内容状态')
                    ->state(fn (Foreshadowing $record): string => $this->canonicalStatus($record)->value)
                    ->formatStateUsing(fn (string $state): string => ForeshadowingStatus::from($state)->getLabel())
                    ->color(fn (string $state): string => ForeshadowingStatus::from($state)->getColor())
                    ->badge()
                    ->description(fn (Foreshadowing $record): string => $this->projectionDrift($record) ? '警告：表投影已漂移' : '表投影一致'),
                TextColumn::make('novel.current_chapter_sequence')
                    ->label('最新正式章节')
                    ->formatStateUsing(fn (int $state): string => '第 '.$state.' 章')
                    ->placeholder('尚未开始')
                    ->alignEnd(),
                TextColumn::make('active_chapter_sequence')
                    ->label('活跃章节')
                    ->formatStateUsing(fn (int $state): string => '第 '.$state.' 章')
                    ->placeholder('无活跃工作流')
                    ->alignEnd(),
                TextColumn::make('due_window')
                    ->label('兑现窗口')
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
        $activeChapter = Chapter::query()
            ->select('sequence')
            ->whereColumn('chapters.novel_id', 'foreshadowings.novel_id')
            ->where(function (Builder $query): void {
                $query->whereIn('chapters.status', [
                    ChapterStatus::Generating->value,
                    ChapterStatus::Review->value,
                    ChapterStatus::Rewrite->value,
                    ChapterStatus::Blocked->value,
                ])->orWhereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('generation_runs')
                        ->whereColumn('generation_runs.chapter_id', 'chapters.id')
                        ->whereIn('generation_runs.status', [RunStatus::Queued->value, RunStatus::Running->value]);
                });
            })
            ->orderBy('sequence')
            ->limit(1);

        return Foreshadowing::query()
            ->select('foreshadowings.*')
            ->addSelect(['active_chapter_sequence' => $activeChapter])
            ->join('novels', 'novels.id', '=', 'foreshadowings.novel_id')
            ->with('novel.canonicalStateVersion')
            ->whereIn('foreshadowings.id', $this->attentionIds())
            ->orderByTimingByNovelProgress()
            ->orderBy('foreshadowings.due_to_chapter')
            ->limit(8);
    }

    private function attentionLabel(Foreshadowing $foreshadowing): string
    {
        $timing = ForeshadowingTimingStatus::forTargetChapter(
            $this->canonicalStatus($foreshadowing),
            $foreshadowing->due_from_chapter,
            $foreshadowing->due_to_chapter,
            Foreshadowing::nextChapterSequence($foreshadowing->novel->current_chapter_sequence),
        );

        if ($timing === ForeshadowingTimingStatus::Overdue) {
            return '已逾期';
        }

        if ($foreshadowing->importance === ForeshadowingImportance::Critical) {
            return '关键 · 兑现窗口';
        }

        return '兑现窗口';
    }

    private function attentionFilter(Foreshadowing $foreshadowing): string
    {
        return match ($this->attentionLabel($foreshadowing)) {
            '已逾期' => 'overdue',
            '关键 · 兑现窗口' => 'critical',
            default => 'due',
        };
    }

    /** @return array<int> */
    private function attentionIds(): array
    {
        return app(DueForeshadowingQuery::class)->attentionIds();
    }

    private function canonicalStatus(Foreshadowing $foreshadowing): ForeshadowingStatus
    {
        return app(ForeshadowingLifecycleResolver::class)->status($foreshadowing, $foreshadowing->novel);
    }

    private function projectionDrift(Foreshadowing $foreshadowing): bool
    {
        $canonicalCount = data_get(
            $foreshadowing->novel->canonicalStateVersion?->state,
            "foreshadowings.{$foreshadowing->getKey()}.reinforce_count",
        );

        return $this->canonicalStatus($foreshadowing) !== $foreshadowing->status
            || (is_numeric($canonicalCount) && (int) $canonicalCount !== $foreshadowing->reinforce_count);
    }
}
