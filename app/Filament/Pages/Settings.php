<?php

namespace App\Filament\Pages;

use App\AI\AiSettingsService;
use App\AI\BudgetService;
use App\AI\Data\BudgetUsage;
use App\Filament\Actions\EmergencyStopAction;
use App\Services\SystemHealthService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = '设置';

    protected static ?string $title = '设置';

    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [static::getRouteName(), AiDebugTest::getRouteName()];
    }

    protected function getHeaderActions(): array
    {
        return [EmergencyStopAction::make()];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'xl' => 2,
            ])->schema([
                $this->placeholderSection(
                    heading: '生成',
                    description: '生成默认值、重试行为与章节工作流策略。',
                    source: '来源：config 与小说设置',
                    icon: 'heroicon-o-bolt',
                ),
                $this->placeholderSection(
                    heading: '审校',
                    description: '审校阈值、决策与重写次数限制。',
                    source: '来源：config 与小说设置',
                    icon: 'heroicon-o-clipboard-document-check',
                ),
                $this->placeholderSection(
                    heading: '记忆',
                    description: '检索数量、上下文预算与嵌入策略。',
                    source: '来源：config 与小说设置',
                    icon: 'heroicon-o-circle-stack',
                ),
                $this->budgetSection(),
                $this->systemHealthSection()->columnSpanFull(),
            ]),
        ]);
    }

    private function budgetSection(): Section
    {
        return Section::make('预算')
            ->description('Hard Limit 达到后，新的 Provider Request 会在发送前被阻止。')
            ->icon('heroicon-o-banknotes')
            ->afterHeader([
                Text::make('只读')->badge()->color('gray'),
            ])
            ->columns(['default' => 1, 'md' => 3])
            ->schema([
                TextEntry::make('daily_budget')
                    ->label('Daily Used / Limit')
                    ->state(fn (): string => $this->formatBudget(app(BudgetService::class)->dailyUsage()))
                    ->badge()
                    ->color(fn (): string => app(BudgetService::class)->dailyUsage()->reached() ? 'danger' : 'gray'),
                TextEntry::make('novel_budget_default')
                    ->label('Novel Total Default')
                    ->state(fn (): string => $this->formatLimit(data_get(app(AiSettingsService::class)->budgetSettings(), 'novel_total_limit'))),
                TextEntry::make('chapter_budget_default')
                    ->label('Chapter Max Default')
                    ->state(fn (): string => $this->formatLimit(data_get(app(AiSettingsService::class)->budgetSettings(), 'chapter_max_cost'))),
                Text::make('来源：config 与 Novel settings。空值表示无限制。')
                    ->icon('heroicon-o-information-circle')
                    ->color('gray')
                    ->columnSpanFull(),
            ]);
    }

    private function formatBudget(BudgetUsage $usage): string
    {
        return $this->currency().' '.number_format($usage->used, 4).' / '.$this->formatLimit($usage->limit, false);
    }

    private function systemHealthSection(): Section
    {
        return Section::make('系统健康')
            ->description('System Health · MVP 发布、恢复与长期单人运行检查')
            ->icon('heroicon-o-heart')
            ->afterHeader([
                Action::make('refreshSystemHealth')
                    ->label('重新检查')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(fn () => Notification::make()->title('系统健康状态已刷新')->success()->send()),
            ])
            ->schema([
                RepeatableEntry::make('system_health_checks')
                    ->hiddenLabel()
                    ->state(fn (): array => app(SystemHealthService::class)->checks()
                        ->map(fn ($check): array => [
                            'label' => $check->label,
                            'status' => $check->statusLabel(),
                            'status_key' => $check->status,
                            'detail' => $check->detail,
                        ])
                        ->all())
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('label')->label('检查项')->weight('medium'),
                        TextEntry::make('status')
                            ->label('状态')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                '正常' => 'success',
                                '需要处理' => 'danger',
                                default => 'warning',
                            }),
                        TextEntry::make('detail')->label('说明')->wrap(),
                    ]),
                Text::make('备份、隔离恢复演练和 Queue 进程重启必须在目标部署环境执行；具体命令见 docs/development/MVP_RELEASE_CHECKLIST.md。')
                    ->icon('heroicon-o-information-circle')
                    ->color('gray'),
            ]);
    }

    private function formatLimit(mixed $limit, bool $withCurrency = true): string
    {
        if (! is_numeric($limit)) {
            return '无限制';
        }

        $value = number_format((float) $limit, 4);

        return $withCurrency ? $this->currency().' '.$value : $value;
    }

    private function currency(): string
    {
        return strtoupper((string) config('ai.cost.currency', 'USD'));
    }

    private function placeholderSection(
        string $heading,
        string $description,
        string $source,
        string $icon,
    ): Section {
        return Section::make($heading)
            ->description($description)
            ->icon($icon)
            ->afterHeader([
                Text::make('只读')->badge()->color('gray'),
            ])
            ->schema([
                Text::make($source)
                    ->icon('heroicon-o-information-circle')
                    ->color('gray'),
            ]);
    }
}
