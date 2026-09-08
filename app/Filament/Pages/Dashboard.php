<?php

namespace App\Filament\Pages;

use App\Actions\Generation\StartSmokeRunAction;
use App\Filament\Actions\EmergencyStopAction;
use App\Filament\Widgets\DueForeshadowingsWidget;
use App\Models\Novel;
use App\Models\UsageRecord;
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
