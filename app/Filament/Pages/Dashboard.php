<?php

namespace App\Filament\Pages;

use App\Actions\Generation\StartMvpSoakRunAction;
use App\Actions\Generation\StartReliabilityRunAction;
use App\Actions\Generation\StartSmokeRunAction;
use App\Filament\Actions\EmergencyStopAction;
use App\Filament\Widgets\DueForeshadowingsWidget;
use App\Models\Novel;
use App\Models\UsageRecord;
use App\Services\MvpSoakRunService;
use App\Services\ReliabilityRunService;
use App\Services\SmokeRunService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Widgets\StatsOverviewWidget\Stat;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = '仪表盘';

    protected static ?string $title = '仪表盘';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startMvpSoakRun')
                ->label('启动 100 章浸泡测试')
                ->icon('heroicon-o-rocket-launch')
                ->color('warning')
                ->modalHeading('启动 100 章 MVP Soak Test')
                ->modalDescription('系统会沿用现有生成主链串行推进 100 个正式章节，并审计状态完整性与费用可追踪性。')
                ->modalSubmitActionLabel('启动浸泡测试')
                ->schema([
                    Select::make('novel_id')
                        ->label('小说')
                        ->options(fn (): array => Novel::query()
                            ->whereIn('status', ['generating', 'completing'])
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, StartMvpSoakRunAction $start): void {
                    $chapter = $start->handle(Novel::query()->findOrFail($data['novel_id']));

                    Notification::make()
                        ->title('100 章 MVP 浸泡测试已启动')
                        ->body('已排队第 '.$chapter->sequence.' 章，达到 100 章边界后将自动停止。')
                        ->success()
                        ->send();
                }),
            Action::make('startReliabilityRun')
                ->label('启动 50 章可靠性长跑')
                ->icon('heroicon-o-shield-check')
                ->color('success')
                ->modalHeading('启动 50 章 Reliability Run')
                ->modalDescription('系统会串行推进 50 个正式章节，并持续汇总重试、恢复、记忆、审校、成本和上下文指标。')
                ->modalSubmitActionLabel('启动可靠性长跑')
                ->schema([
                    Select::make('novel_id')
                        ->label('小说')
                        ->options(fn (): array => Novel::query()
                            ->whereIn('status', ['generating', 'completing'])
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, StartReliabilityRunAction $start): void {
                    $chapter = $start->handle(Novel::query()->findOrFail($data['novel_id']));

                    Notification::make()
                        ->title('50 章可靠性长跑已启动')
                        ->body('已排队第 '.$chapter->sequence.' 章，达到 50 章边界后将自动停止。')
                        ->success()
                        ->send();
                }),
            Action::make('startSmokeRun')
                ->label('启动 20 章长跑')
                ->icon('heroicon-o-play-circle')
                ->color('primary')
                ->modalHeading('启动 20 章 Smoke Run')
                ->modalDescription('系统会沿用现有自动生成主链，每次只推进下一章，并在连续完成 20 个 Canonical Chapter 后自动停止。')
                ->modalSubmitActionLabel('启动长跑')
                ->schema([
                    Select::make('novel_id')
                        ->label('小说')
                        ->options(fn (): array => Novel::query()
                            ->whereIn('status', ['generating', 'completing'])
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, StartSmokeRunAction $start): void {
                    $chapter = $start->handle(Novel::query()->findOrFail($data['novel_id']));

                    Notification::make()
                        ->title('20 章长跑已启动')
                        ->body('已排队第 '.$chapter->sequence.'章，后续章节将在上一章正式提交后依次启动。')
                        ->success()
                        ->send();
                }),
            EmergencyStopAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'md' => 2,
                'xl' => 5,
            ])->schema([
                Stat::make('活跃小说', 0)
                    ->description('暂无活跃小说')
                    ->descriptionIcon('heroicon-o-book-open')
                    ->color('gray'),
                Stat::make('当前章节', '—')
                    ->description('尚未选择章节')
                    ->descriptionIcon('heroicon-o-document-text')
                    ->color('gray'),
                Stat::make('今日成本', fn (): string => $this->todayCost())
                    ->description(fn (): string => $this->hasUsageToday() ? '成功 Provider 请求' : '暂无用量记录')
                    ->descriptionIcon('heroicon-o-banknotes')
                    ->color(fn (): string => $this->hasUsageToday() ? 'info' : 'gray'),
                Stat::make('今日 Tokens', fn (): string => number_format($this->todayTokens()))
                    ->description(fn (): string => $this->hasUsageToday() ? 'Input + Output' : '暂无用量记录')
                    ->descriptionIcon('heroicon-o-calculator')
                    ->color(fn (): string => $this->hasUsageToday() ? 'info' : 'gray'),
                Stat::make('需要处理', 0)
                    ->description('暂无待处理事项')
                    ->descriptionIcon('heroicon-o-check-circle')
                    ->color('success'),
            ]),
            Grid::make([
                'default' => 1,
                'xl' => 2,
            ])->schema([
                Section::make('最近生成')
                    ->description('最近的章节生成活动')
                    ->schema([
                        EmptyState::make('暂无生成活动')
                            ->description('章节生成流程启动后，最近的生成记录会显示在这里。')
                            ->icon('heroicon-o-bolt')
                            ->contained(false),
                    ]),
                ...$this->getWidgetsSchemaComponents([
                    DueForeshadowingsWidget::class,
                ]),
            ]),
            Section::make('20 章长跑进度')
                ->description('Long Run Progress · 连续章节、Canonical State 与实际 Provider 成本')
                ->icon('heroicon-o-arrow-trending-up')
                ->schema([
                    RepeatableEntry::make('smoke_runs')
                        ->hiddenLabel()
                        ->state(fn (): array => $this->smokeRunProgress())
                        ->columns(['default' => 2, 'lg' => 6])
                        ->schema([
                            TextEntry::make('novel')->label('小说')->weight('medium'),
                            TextEntry::make('status')
                                ->label('状态')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    '运行中' => 'info',
                                    '已完成' => 'success',
                                    '已停止' => 'danger',
                                    default => 'gray',
                                }),
                            TextEntry::make('progress')->label('章节进度')->badge()->color('info'),
                            TextEntry::make('cost')->label('可追踪成本')->fontFamily('mono'),
                            TextEntry::make('sequence_health')
                                ->label('章节连续性')
                                ->badge()
                                ->color(fn (string $state): string => $state === '正常' ? 'success' : 'danger'),
                            TextEntry::make('state_health')
                                ->label('State 连续性')
                                ->badge()
                                ->color(fn (string $state): string => $state === '正常' ? 'success' : 'danger'),
                        ]),
                    EmptyState::make('尚未启动 20 章长跑')
                        ->description('点击页面顶部“启动 20 章长跑”选择小说。')
                        ->icon('heroicon-o-clock')
                        ->contained(false)
                        ->visible(fn (): bool => app(SmokeRunService::class)->allProgress()->isEmpty()),
                ]),
            Section::make('50 章可靠性汇总')
                ->description('Reliability Summary · 生成恢复、记忆、审校、成本与上下文趋势')
                ->icon('heroicon-o-shield-check')
                ->schema([
                    RepeatableEntry::make('reliability_runs')
                        ->hiddenLabel()
                        ->state(fn (): array => $this->reliabilityRunSummaries())
                        ->columns(['default' => 2, 'lg' => 4])
                        ->schema([
                            TextEntry::make('novel')->label('小说')->weight('medium'),
                            TextEntry::make('status')->label('状态')->badge()->color(fn (string $state): string => match ($state) {
                                '运行中' => 'info', '已完成' => 'success', '已停止' => 'danger', default => 'gray',
                            }),
                            TextEntry::make('progress')->label('章节进度')->badge()->color('info'),
                            TextEntry::make('continuity')->label('连续性')->badge()->color(fn (string $state): string => $state === '正常' ? 'success' : 'danger'),
                            TextEntry::make('recovery')->label('重试 / Worker 恢复'),
                            TextEntry::make('memory')->label('记忆 / 向量'),
                            TextEntry::make('story')->label('伏笔 / 审校 / 重写'),
                            TextEntry::make('cost_context')->label('成本 / 上下文')->fontFamily('mono'),
                        ]),
                    EmptyState::make('尚未启动 50 章可靠性长跑')
                        ->description('点击页面顶部“启动 50 章可靠性长跑”选择小说。')
                        ->icon('heroicon-o-chart-bar-square')
                        ->contained(false)
                        ->visible(fn (): bool => app(ReliabilityRunService::class)->allSummaries()->isEmpty()),
                ]),
            Section::make('MVP Readiness')
                ->description('100 章浸泡测试的 Canonical、Story State、安全门禁与费用验收')
                ->icon('heroicon-o-rocket-launch')
                ->schema([
                    RepeatableEntry::make('mvp_readiness')
                        ->hiddenLabel()
                        ->state(fn (): array => $this->mvpReadiness())
                        ->columns(['default' => 2, 'lg' => 4])
                        ->schema([
                            TextEntry::make('novel')->label('小说')->weight('medium'),
                            TextEntry::make('readiness')->label('就绪状态')->badge()->color(fn (string $state): string => $state === '已就绪' ? 'success' : 'warning'),
                            TextEntry::make('progress')->label('Canonical 进度')->badge()->color('info'),
                            TextEntry::make('integrity')->label('正式数据完整性')->badge()->color(fn (string $state): string => $state === '正常' ? 'success' : 'danger'),
                            TextEntry::make('guards')->label('生成安全门禁'),
                            TextEntry::make('story_guards')->label('故事规则门禁'),
                            TextEntry::make('usage')->label('费用可追踪性')->badge()->color(fn (string $state): string => str_contains($state, '异常 0') ? 'success' : 'danger'),
                            TextEntry::make('status')->label('运行状态'),
                        ]),
                    EmptyState::make('尚未启动 100 章 MVP 浸泡测试')
                        ->description('点击页面顶部“启动 100 章浸泡测试”选择小说。')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->contained(false)
                        ->visible(fn (): bool => app(MvpSoakRunService::class)->allSummaries()->isEmpty()),
                ]),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function smokeRunProgress(): array
    {
        return app(SmokeRunService::class)->allProgress()
            ->map(fn ($progress): array => [
                'novel' => $progress->novelTitle,
                'status' => $progress->statusLabel(),
                'progress' => "{$progress->canonicalChapters}/20 · {$progress->percent()}%",
                'cost' => config('ai.cost.currency').' '.number_format($progress->cost, 6),
                'sequence_health' => $progress->sequenceHealthy() ? '正常' : '异常',
                'state_health' => $progress->stateContinuous ? '正常' : '异常',
            ])
            ->all();
    }

    /** @return array<int, array<string, string>> */
    private function reliabilityRunSummaries(): array
    {
        return app(ReliabilityRunService::class)->allSummaries()
            ->map(function ($summary): array {
                $drift = $summary->costDriftPercent === null
                    ? '样本不足'
                    : sprintf('%+.2f%%', $summary->costDriftPercent);

                return [
                    'novel' => $summary->novelTitle,
                    'status' => $summary->statusLabel(),
                    'progress' => "{$summary->canonicalChapters}/50 · {$summary->percent()}%",
                    'continuity' => $summary->sequenceContinuous && $summary->stateContinuous ? '正常' : '异常',
                    'recovery' => "重试 {$summary->retryRuns} · Worker {$summary->workerRecoveries}/{$summary->workerCrashes}",
                    'memory' => "{$summary->memories} 条 · 已向量化 {$summary->embeddedMemories}",
                    'story' => "伏笔 {$summary->foreshadowingEvents} · 审校 {$summary->reviews} · 重写 {$summary->rewrites}",
                    'cost_context' => config('ai.cost.currency').' '.number_format($summary->cost, 6)." · 漂移 {$drift} · Context 平均 ".number_format($summary->averageContextTokens).' / 最大 '.number_format($summary->maximumContextTokens),
                ];
            })
            ->all();
    }

    /** @return array<int, array<string, string>> */
    private function mvpReadiness(): array
    {
        return app(MvpSoakRunService::class)->allSummaries()
            ->map(fn ($summary): array => [
                'novel' => $summary->novelTitle,
                'readiness' => $summary->isReady() ? '已就绪' : '验收中',
                'progress' => "{$summary->canonicalChapters}/100 · {$summary->percent()}%",
                'integrity' => $summary->duplicateCanonicalCommits === 0 && $summary->missingCanonicalChapters === 0 && $summary->stateIntegrityIssues === 0 ? '正常' : '异常',
                'guards' => '暂停禁止 Commit · Resume 连续性',
                'story_guards' => 'Locked Fact · Critical Foreshadowing',
                'usage' => config('ai.cost.currency').' '.number_format($summary->trackedCost, 6)." · 异常 {$summary->untraceableUsageRecords}",
                'status' => $summary->statusLabel()." · 重复 {$summary->duplicateCanonicalCommits} · 跳章 {$summary->missingCanonicalChapters} · State 异常 {$summary->stateIntegrityIssues}",
            ])
            ->all();
    }

    private function hasUsageToday(): bool
    {
        return UsageRecord::query()->whereDate('created_at', today())->exists();
    }

    private function todayCost(): string
    {
        $cost = (float) UsageRecord::query()
            ->whereDate('created_at', today())
            ->sum('estimated_cost');

        return config('ai.cost.currency').' '.number_format($cost, 6);
    }

    private function todayTokens(): int
    {
        return (int) UsageRecord::query()
            ->whereDate('created_at', today())
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as total_tokens')
            ->value('total_tokens');
    }
}
