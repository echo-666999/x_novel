<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\AI\AiSettingsService;
use App\AI\BudgetService;
use App\AI\Data\BudgetUsage;
use App\Enums\NovelStatus;
use App\Filament\Pages\Generation;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use App\Services\ClosureDebtService;
use App\Services\NovelGenerationReadiness;
use App\Services\NovelOperationsOverview;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NovelOverview
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('小说概览')
                ->description('当前小说的核心定位与正式状态。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 4,
                ])
                ->schema([
                    TextEntry::make('title')
                        ->label('标题')
                        ->weight('medium'),
                    TextEntry::make('genre')
                        ->label('题材'),
                    TextEntry::make('status')
                        ->label('状态')
                        ->badge(),
                    TextEntry::make('updated_at')
                        ->label('更新时间')
                        ->dateTime('Y-m-d H:i'),
                    TextEntry::make('premise')
                        ->label('故事前提')
                        ->placeholder('尚未填写故事前提')
                        ->columnSpanFull(),
                ]),
            Section::make('正文生成准备度')
                ->description('开始正文生成前必须全部满足；缺项会直接显示对应修复入口。')
                ->visible(fn (Novel $record): bool => in_array($record->status, [NovelStatus::Draft, NovelStatus::Planning], true))
                ->schema([
                    RepeatableEntry::make('generation_readiness')
                        ->hiddenLabel()
                        ->state(fn (Novel $record): array => collect(app(NovelGenerationReadiness::class)->evaluate($record))
                            ->map(fn (array $item): array => [
                                ...$item,
                                'detail' => $item['ready'] ? '条件已满足' : $item['repair_hint'],
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('label')
                                ->label('检查项')
                                ->weight('medium'),
                            TextEntry::make('ready')
                                ->label('状态')
                                ->formatStateUsing(fn (bool $state): string => $state ? '已就绪' : '缺失')
                                ->badge()
                                ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                            TextEntry::make('detail')
                                ->label('说明')
                                ->color('gray')
                                ->columnSpanFull(),
                        ])
                        ->columns(['default' => 1, 'md' => 2]),
                ]),
            Section::make('进度与状态')
                ->description('仅统计已正式提交的章节字数，并显示当前 Active Volume 与正式故事版本。')
                ->schema([
                    RepeatableEntry::make('progress_snapshot')
                        ->hiddenLabel()
                        ->state(fn (Novel $record): array => [app(NovelOperationsOverview::class)->progress($record)])
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('target_words')->label('目标字数')->numeric(),
                            TextEntry::make('current_words')->label('当前字数')->numeric(),
                            TextEntry::make('current_volume')->label('当前卷'),
                            TextEntry::make('current_chapter')->label('当前章节'),
                            TextEntry::make('current_state_version')->label('当前故事版本')->badge(),
                            TextEntry::make('generation_status')->label('生成状态')->badge(),
                            TextEntry::make('auto_generation_status')->label('自动生成')->badge(),
                            TextEntry::make('pause_position')->label('暂停位置')->color('gray'),
                            TextEntry::make('auto_stop_reason')->label('自动生成已停止')->color('gray'),
                            TextEntry::make('auto_stop_recommended_action')->label('推荐操作')->color('gray'),
                        ]),
                ]),
            Section::make('当前流水线')
                ->description('显示当前章节、最新阶段、耗时、停止原因与唯一推荐操作。')
                ->schema([
                    RepeatableEntry::make('pipeline_snapshot')
                        ->hiddenLabel()
                        ->state(fn (Novel $record): array => [self::pipelineSnapshot($record)])
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('chapter')->label('当前章节')->weight('medium'),
                            TextEntry::make('run')->label('Generation Run'),
                            TextEntry::make('stage')->label('当前阶段')->badge(),
                            TextEntry::make('status')->label('状态')->badge(),
                            TextEntry::make('elapsed')->label('耗时'),
                            TextEntry::make('stop_reason')->label('停止原因')->columnSpan(['xl' => 2]),
                            TextEntry::make('failure_category')->label('失败分类')->badge(),
                            TextEntry::make('next_action')->label('推荐操作')->weight('medium'),
                            TextEntry::make('action_url')
                                ->label('操作入口')
                                ->formatStateUsing(fn (string $state): string => '打开')
                                ->url(fn (string $state): string => $state)
                                ->icon('heroicon-o-arrow-right-circle')
                                ->weight('medium'),
                        ]),
                ]),
            Section::make('收束债务')
                ->description('汇总当前阻碍小说完结的开放故事义务；展开明细可查看具体原因。')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextEntry::make('closure_debt_total')
                        ->label('Closure Debt')
                        ->state(fn (Novel $record): int => app(ClosureDebtService::class)->calculate($record)->total())
                        ->badge()
                        ->color(fn (Novel $record): string => app(ClosureDebtService::class)->calculate($record)->total() > 0 ? 'warning' : 'success'),
                    TextEntry::make('closure_debt_critical')
                        ->label('关键债务')
                        ->state(fn (Novel $record): int => app(ClosureDebtService::class)->calculate($record)->critical())
                        ->badge()
                        ->color(fn (Novel $record): string => app(ClosureDebtService::class)->calculate($record)->critical() > 0 ? 'danger' : 'success'),
                    Section::make('债务明细')
                        ->description('按来源列出尚未完成、兑现或解决的事项。')
                        ->collapsible()
                        ->collapsed()
                        ->columnSpanFull()
                        ->schema([
                            RepeatableEntry::make('closure_debt_items')
                                ->hiddenLabel()
                                ->state(fn (Novel $record): array => app(ClosureDebtService::class)->calculate($record)->toArray())
                                ->schema([
                                    TextEntry::make('category_label')
                                        ->label('来源')
                                        ->badge()
                                        ->color('gray'),
                                    TextEntry::make('severity')
                                        ->label('级别')
                                        ->badge()
                                        ->color(fn (string $state): string => $state === '关键' ? 'danger' : 'warning'),
                                    TextEntry::make('title')
                                        ->label('事项')
                                        ->weight('medium'),
                                    TextEntry::make('reason')
                                        ->label('未完结原因'),
                                ])
                                ->columns(['default' => 1, 'md' => 2]),
                        ]),
                ]),
            Section::make('预算')
                ->description('当前小说和当前章节的实际成本；达到 Hard Limit 后不再发送新模型请求。')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextEntry::make('novel_budget')
                        ->label('Novel Used / Limit')
                        ->state(fn (Novel $record): string => self::formatBudget(
                            app(BudgetService::class)->novelUsage($record),
                        ))
                        ->badge()
                        ->color(fn (Novel $record): string => app(BudgetService::class)->novelUsage($record)->reached() ? 'danger' : 'gray'),
                    TextEntry::make('chapter_budget')
                        ->label('Current Chapter Used / Limit')
                        ->state(function (Novel $record): string {
                            $chapter = $record->chapters()
                                ->where('sequence', $record->current_chapter_sequence)
                                ->first();

                            if ($chapter === null) {
                                return '无当前章节';
                            }

                            return self::formatBudget(app(BudgetService::class)->chapterUsage($chapter, $record));
                        })
                        ->badge(),
                ]),
            Section::make('质量与运营')
                ->description('基于当前小说的正式章节、最新 Run、最新 Review、Usage 与到期伏笔实时汇总。')
                ->schema([
                    RepeatableEntry::make('quality_snapshot')
                        ->hiddenLabel()
                        ->state(fn (Novel $record): array => [app(NovelOperationsOverview::class)->quality($record)])
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('today_cost')->label('今日成本')->fontFamily('mono'),
                            TextEntry::make('review_pass_rate')->label('首轮 Review 通过率'),
                            TextEntry::make('rewrite_rate')->label('Rewrite 比例'),
                            TextEntry::make('due_foreshadowings')->label('待处理伏笔')->badge(),
                            TextEntry::make('failed_runs')->label('Failed / Stalled')->badge()->color('danger'),
                            TextEntry::make('blocked_reviews')->label('Blocked')->badge()->color('danger'),
                            TextEntry::make('needs_attention_reviews')->label('Needs Attention')->badge()->color('warning'),
                        ]),
                ]),
        ]);
    }

    private static function formatBudget(BudgetUsage $usage): string
    {
        $limit = $usage->limit === null ? '无限制' : number_format($usage->limit, 4);

        return data_get(app(AiSettingsService::class)->costSettings(), 'currency', 'USD').' '.number_format($usage->used, 4).' / '.$limit;
    }

    /** @return array<string, mixed> */
    private static function pipelineSnapshot(Novel $novel): array
    {
        $snapshot = app(NovelOperationsOverview::class)->pipeline($novel);

        return [
            ...$snapshot,
            'action_url' => match ($snapshot['next_action_key']) {
                'complete_planning' => NovelResource::getUrl('outline', ['record' => $novel]),
                'retry', 'recover', 'inspect_failure' => Generation::getUrl(['tableAction' => 'inspect', 'tableActionRecord' => $snapshot['run_id']]),
                'view_chapter', 'commit', 'handle_review' => $snapshot['chapter_id'] === null
                    ? NovelResource::getUrl('view', ['record' => $novel])
                    : NovelResource::getUrl('chapter', ['record' => $novel, 'chapter' => $snapshot['chapter_id']]),
                default => NovelResource::getUrl('view', ['record' => $novel]),
            },
        ];
    }
}
