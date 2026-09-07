<?php

namespace App\Filament\Pages;

use App\Enums\ReviewDecision;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Review as ReviewModel;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
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
use Illuminate\Support\Facades\DB;

class Review extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = '审校';

    protected static ?string $title = '审校收件箱';

    public static function getNavigationBadge(): ?string
    {
        $count = ReviewModel::query()
            ->whereIn('decision', [ReviewDecision::NeedsAttention, ReviewDecision::Block])
            ->whereIn('reviews.id', self::latestReviewIds())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ReviewModel::query()->whereIn('reviews.id', self::latestReviewIds()))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'artifact:id,version',
                'generationRun:id,novel_id,chapter_id',
                'generationRun.novel:id,title',
                'generationRun.chapter:id,novel_id,sequence,title',
            ]))
            ->columns([
                TextColumn::make('generationRun.novel.title')->label('小说')->searchable()->weight('medium'),
                TextColumn::make('generationRun.chapter.sequence')
                    ->label('章节')
                    ->formatStateUsing(fn (?int $state, ReviewModel $record): string => $state === null ? '—' : '第 '.$state.' 章 · '.$record->generationRun->chapter->title)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'generationRun.chapter',
                        fn (Builder $chapter): Builder => $chapter->where('title', 'like', "%{$search}%"),
                    )),
                TextColumn::make('decision')->label('Decision')
                    ->formatStateUsing(fn (ReviewDecision $state): string => $state->getLabel())
                    ->badge()->color(fn (ReviewDecision $state): string => $state->getColor()),
                TextColumn::make('top_finding')->label('首要问题')
                    ->state(fn (ReviewModel $record): string => $this->topFinding($record)['message'] ?? '无 Findings')
                    ->description(fn (ReviewModel $record): ?string => $this->findingDescription($this->topFinding($record)))
                    ->wrap()->limit(80),
                TextColumn::make('score')->label('总分')->suffix(' / 100')->numeric(2)->alignEnd()->sortable(),
                TextColumn::make('created_at')->label('Age')->since()
                    ->tooltip(fn (ReviewModel $record): string => $record->created_at->format('Y-m-d H:i:s'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('inbox')->label('显示范围')
                    ->options(['attention' => '仅待处理', 'all' => '全部 Decision'])->default('attention')
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? 'attention') === 'all'
                        ? $query
                        : $query->whereIn('decision', [ReviewDecision::NeedsAttention, ReviewDecision::Block])),
                SelectFilter::make('decision')->label('Decision')
                    ->options(collect(ReviewDecision::cases())->mapWithKeys(
                        fn (ReviewDecision $decision): array => [$decision->value => $decision->getLabel()],
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (ReviewModel $record): string => $this->chapterUrl($record))
            ->recordActions([
                ViewAction::make('inspect')->label('查看 Findings')->icon('heroicon-o-magnifying-glass')
                    ->modalHeading(fn (ReviewModel $record): string => '第 '.$record->generationRun->chapter->sequence.' 章 · '.$record->decision->getLabel())
                    ->modalDescription(fn (ReviewModel $record): string => $record->generationRun->novel->title.' · '.$record->generationRun->chapter->title)
                    ->modalWidth('3xl')->slideOver()->modalCancelActionLabel('关闭')
                    ->schema($this->reviewInspectorSchema()),
            ])
            ->emptyStateHeading('审校收件箱为空')
            ->emptyStateDescription('当前没有需要人工处理或已阻塞的章节。')
            ->emptyStateIcon('heroicon-o-inbox');
    }

    /** @return array<int, mixed> */
    private function reviewInspectorSchema(): array
    {
        return [
            Section::make('审校结果')->columns(3)->schema([
                TextEntry::make('decision')->label('Decision')->badge(),
                TextEntry::make('score')->label('总分')->suffix(' / 100'),
                TextEntry::make('artifact.version')->label('Review Version')->formatStateUsing(fn (int $state): string => 'v'.$state),
            ]),
            Section::make('七维评分')->columns(2)->schema([
                TextEntry::make('continuity_score')->label('事实 / 连续性 · 25%'),
                TextEntry::make('plan_score')->label('计划遵循 · 15%'),
                TextEntry::make('character_score')->label('人物一致性 · 15%'),
                TextEntry::make('progress_score')->label('剧情推进 · 15%'),
                TextEntry::make('repetition_score')->label('重复度 · 10%'),
                TextEntry::make('pacing_score')->label('节奏 / 悬念 · 10%'),
                TextEntry::make('style_score')->label('文风 / 可读性 · 10%'),
            ]),
            Section::make('Findings')->schema([
                RepeatableEntry::make('findings')->hiddenLabel()->schema([
                    TextEntry::make('message')->label('问题')->columnSpanFull(),
                    TextEntry::make('severity')->label('级别')->badge(),
                    TextEntry::make('dimension')->label('维度')->placeholder('状态一致性'),
                    TextEntry::make('code')->label('规则代码')->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('evidence')->label('证据')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('related_fact_id')->label('相关 Fact')->formatStateUsing(fn (int $state): string => '#'.$state)->placeholder('—'),
                    TextEntry::make('related_state_path')->label('Story State Path')->fontFamily('mono')->placeholder('—'),
                ])->columns(3),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function topFinding(ReviewModel $review): array
    {
        return collect($review->findings)->sortBy(fn (array $finding): int => match ($finding['severity'] ?? null) {
            'hard' => 0, 'error' => 1, 'ambiguous' => 2, 'warning', 'soft' => 3, default => 4,
        })->first() ?? [];
    }

    /** @param array<string, mixed> $finding */
    private function findingDescription(array $finding): ?string
    {
        return $finding === [] ? null : collect([$finding['code'] ?? null, $finding['dimension'] ?? null])->filter()->implode(' · ');
    }

    private function chapterUrl(ReviewModel $review): string
    {
        return NovelResource::getUrl('chapter', [
            'record' => $review->generationRun->novel_id,
            'chapter' => $review->generationRun->chapter_id,
            'tab' => 'review',
        ]);
    }

    private static function latestReviewIds(): QueryBuilder
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
}
