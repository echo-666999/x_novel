<?php

namespace App\Filament\Pages;

use App\AI\BudgetService;
use App\AI\Data\BudgetUsage;
use App\Filament\Actions\EmergencyStopAction;
use App\Services\GenerationSettingsService;
use App\Services\SystemHealthService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

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
        return [
            $this->generationSettingsAction(),
            EmergencyStopAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'xl' => 2,
            ])->schema([
                $this->generationSettingsSection(),
                $this->reviewSettingsSection(),
                $this->memorySettingsSection(),
                $this->budgetSection(),
                $this->systemHealthSection()->columnSpanFull(),
            ]),
        ]);
    }

    private function generationSettingsAction(): Action
    {
        return Action::make('configureGenerationSettings')
            ->label('生成参数')
            ->icon('heroicon-o-adjustments-horizontal')
            ->modalHeading('配置生成参数')
            ->modalDescription('保存后只影响新建或重新开始的任务；继续执行的任务与已冻结的生成记录仍使用原值。')
            ->modalWidth('7xl')
            ->fillForm(fn (): array => app(GenerationSettingsService::class)->settings())
            ->schema([
                Hidden::make('schema_version')->default(GenerationSettingsService::SCHEMA_VERSION),
                Tabs::make('生成参数')
                    ->tabs([
                        Tab::make('通用')
                            ->schema([
                                Section::make('费用控制')
                                    ->description('币种用于模型价格匹配和费用展示；费用上限留空表示无限制。')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('currency')
                                            ->label('计价币种')
                                            ->required()
                                            ->minLength(3)
                                            ->maxLength(3)
                                            ->helperText('使用三个字母的货币代码，例如美元代码或人民币代码。'),
                                        $this->costLimitInput('budget.daily_hard_limit', '每日费用上限'),
                                        $this->costLimitInput('budget.novel_total_limit', '单本小说总费用上限'),
                                        $this->costLimitInput('budget.chapter_max_cost', '单章费用上限'),
                                    ]),
                                Section::make('审校与向量')
                                    ->description('向量维度必须与数据库字段一致；更换维度需要先执行数据库迁移和全量重建向量。')
                                    ->columns(2)
                                    ->schema([
                                        $this->tokenInput('review_context_token_budget', '审校上下文 Token 预算'),
                                        $this->tokenInput('embedding_dimensions', '嵌入向量维度')
                                            ->helperText(fn (): string => '当前数据库记忆向量字段维度：'.app(GenerationSettingsService::class)->databaseEmbeddingDimensions()),
                                    ]),
                            ]),
                        Tab::make('全书大纲')
                            ->schema($this->outlineBudgetSections()),
                        Tab::make('章节阶段')
                            ->schema($this->chapterBudgetSections()),
                        Tab::make('修复子阶段')
                            ->schema($this->repairBudgetSections()),
                    ]),
            ])
            ->action(function (array $data, GenerationSettingsService $settings, Action $action): void {
                try {
                    $settings->save($data, auth()->id());
                } catch (ValidationException $exception) {
                    $statePath = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $this->addError("{$statePath}.{$field}", $message);
                        }
                    }

                    Notification::make()
                        ->title('生成参数未保存')
                        ->body(collect($exception->errors())->flatten()->unique()->take(3)->implode('；'))
                        ->danger()
                        ->persistent()
                        ->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('生成参数已保存')->success()->send();
            });
    }

    private function generationSettingsSection(): Section
    {
        return Section::make('生成')
            ->description('Outline、章节阶段和修复子阶段的请求预算。')
            ->icon('heroicon-o-bolt')
            ->afterHeader([Text::make('数据库配置')->badge()->color('success')])
            ->schema([
                TextEntry::make('generation_settings_source')
                    ->label('配置来源')
                    ->state(fn (): string => $this->generationSettingsSource()),
                Text::make('服务商连接在“AI 与成本 → 服务商连接”维护；模型、容量、价格和各阶段路由在“模型价格”维护。')
                    ->color('gray'),
            ]);
    }

    private function reviewSettingsSection(): Section
    {
        return Section::make('审校')
            ->description('新 Plan Admission 冻结的审校上下文预算。')
            ->icon('heroicon-o-clipboard-document-check')
            ->afterHeader([Text::make('数据库配置')->badge()->color('success')])
            ->schema([
                TextEntry::make('review_context_budget')
                    ->label('上下文 Token 预算')
                    ->state(fn (): string => number_format(app(GenerationSettingsService::class)->reviewContextTokenBudget())),
            ]);
    }

    private function memorySettingsSection(): Section
    {
        return Section::make('记忆')
            ->description('Embedding 模型由模型路由维护，向量维度由生成参数维护。')
            ->icon('heroicon-o-circle-stack')
            ->afterHeader([Text::make('数据库配置')->badge()->color('success')])
            ->schema([
                TextEntry::make('embedding_dimensions')
                    ->label('向量维度')
                    ->state(fn (): string => number_format(app(GenerationSettingsService::class)->embeddingDimensions())),
                Text::make('修改模型前必须确认其输出维度与 PostgreSQL memories.embedding 一致。')
                    ->color('gray'),
            ]);
    }

    /** @return array<int, Section> */
    private function outlineBudgetSections(): array
    {
        $labels = [
            'outline_foundation' => '基础设定',
            'outline_structure' => '整体结构',
            'outline_arc_beats' => '故事线节点',
            'outline_beat_detail' => '节点细化',
        ];

        return collect($labels)->map(fn (string $label, string $stage): Section => Section::make($label)
            ->columns(2)
            ->schema($this->budgetInputs("outline_request_budgets.{$stage}")))
            ->values()
            ->all();
    }

    /** @return array<int, Section> */
    private function chapterBudgetSections(): array
    {
        $stages = [
            'planner' => ['章节规划', ['initial', 'retry', 'final']],
            'writer' => ['场景写作', ['initial', 'retry', 'final']],
            'extractor' => ['事件提取', ['initial', 'retry', 'final']],
            'reviewer' => ['叙事审校', ['initial']],
            'rewrite' => ['章节重写', ['initial', 'retry', 'final']],
            'summary' => ['摘要生成', ['initial', 'retry', 'final']],
        ];

        return collect($stages)->map(fn (array $definition, string $stage): Section => Section::make($definition[0])
            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
            ->schema($this->tierSections("chapter_request_budgets.{$stage}", $definition[1])))
            ->values()
            ->all();
    }

    /** @return array<int, Section> */
    private function repairBudgetSections(): array
    {
        $repairs = [
            'extractor.scene_structure' => ['事件提取 · 场景结构修复', ['initial', 'retry']],
            'extractor.coverage_evidence' => ['事件提取 · 计划覆盖证据修复', ['initial', 'retry']],
            'extractor.foreshadowing_coverage' => ['事件提取 · 伏笔覆盖修复', ['initial', 'retry']],
            'extractor.event_evidence' => ['事件提取 · 事件证据修复', ['initial', 'retry']],
            'reviewer.coverage_judgment' => ['审校 · 计划覆盖判定', ['initial', 'retry']],
            'reviewer.review_schema' => ['审校 · 响应结构修复', ['initial']],
            'reviewer.arc_completion' => ['审校 · 故事线完成度修复', ['initial']],
            'rewrite.coverage_evidence' => ['重写 · 计划覆盖证据修复', ['initial', 'retry']],
            'rewrite.length_repair' => ['重写 · 长度修复', ['initial', 'retry']],
        ];

        return collect($repairs)->map(function (array $definition, string $path): Section {
            $base = 'chapter_repair_request_budgets.'.str_replace('.', '.', $path);

            return Section::make($definition[0])
                ->columns(['default' => 1, 'md' => 2])
                ->schema($this->tierSections($base, $definition[1]));
        })->values()->all();
    }

    /** @param array<int, string> $tiers @return array<int, Section> */
    private function tierSections(string $base, array $tiers): array
    {
        $labels = ['initial' => '首次', 'retry' => '重试', 'final' => '最终重试'];

        return collect($tiers)->map(fn (string $tier): Section => Section::make($labels[$tier])
            ->columns(2)
            ->compact()
            ->schema($this->budgetInputs("{$base}.{$tier}")))
            ->all();
    }

    /** @return array<int, TextInput> */
    private function budgetInputs(string $base): array
    {
        return [
            $this->tokenInput("{$base}.output_tokens", '可见输出 Token'),
            $this->tokenInput("{$base}.reasoning_reserve_tokens", '推理预留 Token', 0),
        ];
    }

    private function tokenInput(string $name, string $label, int $minimum = 1): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue($minimum)
            ->required();
    }

    private function costLimitInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->placeholder('无限制');
    }

    private function generationSettingsSource(): string
    {
        return match (app(GenerationSettingsService::class)->current()['source']) {
            'database' => 'PostgreSQL · system_settings',
            'application_default_invalid_database' => '数据库配置无效，当前使用应用默认值',
            default => '应用默认值（保存后写入 PostgreSQL）',
        };
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
                    ->state(fn (): string => $this->formatLimit(data_get(app(GenerationSettingsService::class)->budgetSettings(), 'novel_total_limit'))),
                TextEntry::make('chapter_budget_default')
                    ->label('Chapter Max Default')
                    ->state(fn (): string => $this->formatLimit(data_get(app(GenerationSettingsService::class)->budgetSettings(), 'chapter_max_cost'))),
                Text::make('来源：设置 → 生成参数与 Novel settings。空值表示无限制。')
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
        return app(GenerationSettingsService::class)->currency();
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
