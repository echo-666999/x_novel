<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\AI\BudgetService;
use App\AI\Data\BudgetUsage;
use App\Enums\NovelStatus;
use App\Models\Novel;
use App\Services\ClosureDebtService;
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
            Section::make('进度与状态')
                ->description('尚未接入的数据会明确标记，不推测正式故事状态。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 3,
                ])
                ->schema([
                    TextEntry::make('target_words')
                        ->label('目标字数')
                        ->numeric(),
                    TextEntry::make('current_words')
                        ->label('当前字数')
                        ->state(0)
                        ->numeric(),
                    TextEntry::make('current_volume')
                        ->label('当前卷')
                        ->state('尚未接入')
                        ->color('gray'),
                    TextEntry::make('current_chapter_sequence')
                        ->label('当前章节')
                        ->placeholder('尚无正式章节')
                        ->formatStateUsing(fn (?int $state): string => $state === null ? '尚无正式章节' : "第 {$state} 章"),
                    TextEntry::make('current_state_version')
                        ->label('当前故事版本')
                        ->state(fn (Novel $record): string => $record->canonicalStateVersion
                            ? '当前版本: '.$record->canonicalStateVersion->version
                            : '故事状态: 未初始化')
                        ->badge()
                        ->color(fn (Novel $record): string => $record->canonical_state_version_id ? 'success' : 'warning'),
                    TextEntry::make('generation_status')
                        ->label('生成状态')
                        ->state(fn (Novel $record): string => match ($record->status->value) {
                            'generating' => '生成中',
                            'paused' => '已暂停',
                            'failed' => '生成失败',
                            default => '尚未运行',
                        })
                        ->badge()
                        ->color(fn (Novel $record): string => match ($record->status->value) {
                            'generating' => 'info',
                            'failed' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('auto_generation_status')
                        ->label('自动生成')
                        ->state(fn (Novel $record): string => (bool) data_get($record->settings, 'auto_generate', false) ? 'Auto: ON' : 'Auto: OFF')
                        ->badge()
                        ->color(fn (Novel $record): string => (bool) data_get($record->settings, 'auto_generate', false) ? 'success' : 'gray'),
                    TextEntry::make('pause_position')
                        ->label('暂停位置')
                        ->state(fn (Novel $record): string => 'Paused at: '.data_get($record->settings, 'pause.label', '等待下一阶段'))
                        ->badge()
                        ->color('gray')
                        ->visible(fn (Novel $record): bool => $record->status === NovelStatus::Paused),
                    TextEntry::make('auto_stop_reason')
                        ->label('自动生成已停止')
                        ->state(fn (Novel $record): ?string => data_get($record->settings, 'auto_stop.reason'))
                        ->badge()
                        ->color('danger')
                        ->visible(fn (Novel $record): bool => filled(data_get($record->settings, 'auto_stop.reason'))),
                    TextEntry::make('auto_stop_recommended_action')
                        ->label('推荐操作')
                        ->state(fn (Novel $record): ?string => data_get($record->settings, 'auto_stop.recommended_action'))
                        ->color('gray')
                        ->visible(fn (Novel $record): bool => filled(data_get($record->settings, 'auto_stop.reason'))),
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
                ->description('后续任务接入 Review、Generation、Usage 与 Foreshadowing 数据后自动填充。')
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 5,
                ])
                ->schema([
                    TextEntry::make('today_cost')
                        ->label('今日成本')
                        ->state('¥0.00'),
                    TextEntry::make('review_pass_rate')
                        ->label('Review 通过率')
                        ->state('尚未接入')
                        ->color('gray'),
                    TextEntry::make('rewrite_rate')
                        ->label('Rewrite 比例')
                        ->state('尚未接入')
                        ->color('gray'),
                    TextEntry::make('due_foreshadowings')
                        ->label('待处理伏笔')
                        ->state(0),
                    TextEntry::make('needs_attention')
                        ->label('需要处理')
                        ->state(0),
                ]),
        ]);
    }

    private static function formatBudget(BudgetUsage $usage): string
    {
        $limit = $usage->limit === null ? '无限制' : number_format($usage->limit, 6);

        return config('ai.cost.currency').' '.number_format($usage->used, 6).' / '.$limit;
    }
}
