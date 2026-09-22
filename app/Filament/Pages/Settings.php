<?php

namespace App\Filament\Pages;

use App\AI\AiSettingsResolver;
use App\AI\AiSettingsService;
use App\AI\BudgetService;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\BudgetUsage;
use App\AI\Exceptions\AiProviderException;
use App\AI\PromptVersionResolver;
use App\Enums\AiStage;
use App\Filament\Actions\EmergencyStopAction;
use App\Services\SystemHealthService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
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

    /** @var array{success: bool, model: string, latency_ms: int|null, message: string}|null */
    public ?array $aiConnectionResult = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(app(AiSettingsService::class)->editableSettings());
    }

    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [static::getRouteName(), AiDebugTest::getRouteName()];
    }

    protected function getHeaderActions(): array
    {
        $settings = app(AiSettingsService::class);

        return [
            EmergencyStopAction::make(),
            Action::make('testAiConnection')
                ->label('Test Connection')
                ->icon('heroicon-o-signal')
                ->action(function (AiProvider $provider): void {
                    $resolved = app(AiSettingsResolver::class)->resolve(AiStage::Planner);
                    $this->testAiConnection($provider, $resolved->provider, $resolved->model);
                }),
            ...collect($settings->registeredProviders())->map(function (string $provider): Action {
                return Action::make('test'.ucfirst($provider).'Connection')
                    ->label('测试 '.$this->providerLabel($provider))
                    ->icon('heroicon-o-signal')
                    ->schema([
                        TextInput::make('model')
                            ->label($this->providerLabel($provider).' Model')
                            ->default(fn (): ?string => $this->connectionTestModel($provider))
                            ->required(),
                    ])
                    ->action(fn (array $data, AiProvider $router) => $this->testAiConnection($router, $provider, trim((string) $data['model'])));
            })->all(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('AI 运行配置在此维护；API Key 使用 Laravel 应用密钥加密保存，页面不会回显原值。')
                ->color('gray'),
            Grid::make([
                'default' => 1,
                'xl' => 2,
            ])->schema([
                $this->aiSection()->columnSpanFull(),
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
                $this->budgetSection()->columnSpanFull(),
                $this->systemHealthSection()->columnSpanFull(),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(AiSettingsService::class);
        $providerOptions = collect($settings->registeredProviders())
            ->mapWithKeys(fn (string $provider): array => [$provider => $this->providerLabel($provider)])
            ->all();

        return $schema
            ->statePath('data')
            ->components([
                Hidden::make('schema_version')
                    ->default(AiSettingsService::SCHEMA_VERSION),
                Select::make('default_provider')
                    ->label('默认文本生成 Provider')
                    ->options($providerOptions)
                    ->required()
                    ->native(false),
                ...collect($settings->registeredProviders())
                    ->flatMap(fn (string $provider): array => [
                        Toggle::make("providers.{$provider}.enabled")
                            ->label($this->providerLabel($provider).' 已启用')
                            ->helperText('只有已注册、已启用且已经配置 API Key 的 Provider 才能保存为生效配置。'),
                        TextInput::make("providers.{$provider}.base_url")
                            ->label($this->providerLabel($provider).' Base URL')
                            ->url()
                            ->required()
                            ->maxLength(AiSettingsService::URL_MAX_LENGTH),
                        TextInput::make("providers.{$provider}.api_key")
                            ->label($this->providerLabel($provider).' API Key')
                            ->password()
                            ->revealable()
                            ->autocomplete(false)
                            ->helperText(fn (): string => $settings->isCredentialConfigured($provider)
                                ? '已配置。留空保留现有密钥；填写新值会替换并加密保存。'
                                : '未配置。保存后使用 Laravel 应用密钥加密存入 system_settings.ai。'),
                        Toggle::make("providers.{$provider}.clear_api_key")
                            ->label('清除 '.$this->providerLabel($provider).' API Key')
                            ->helperText('启用后保存会删除数据库密钥；环境变量仍可作为兼容回退。'),
                        TextInput::make("providers.{$provider}.connect_timeout")
                            ->label($this->providerLabel($provider).' 连接超时（秒）')
                            ->numeric()
                            ->integer()
                            ->minValue(AiSettingsService::MIN_TIMEOUT_SECONDS)
                            ->maxValue(AiSettingsService::MAX_CONNECT_TIMEOUT_SECONDS)
                            ->required(),
                        TextInput::make("providers.{$provider}.timeout")
                            ->label($this->providerLabel($provider).' 请求超时（秒）')
                            ->numeric()
                            ->integer()
                            ->minValue(AiSettingsService::MIN_TIMEOUT_SECONDS)
                            ->maxValue(AiSettingsService::MAX_REQUEST_TIMEOUT_SECONDS)
                            ->required(),
                    ])
                    ->all(),
                ...collect($settings->stages())
                    ->flatMap(fn (AiStage $stage): array => [
                        Select::make("stages.{$stage->value}.provider")
                            ->label($stage->getLabel().' Provider')
                            ->options($providerOptions)
                            ->required()
                            ->native(false),
                        TextInput::make("stages.{$stage->value}.model")
                            ->label($stage->getLabel().' Model')
                            ->required()
                            ->maxLength(AiSettingsService::MODEL_MAX_LENGTH),
                    ])
                    ->all(),
                TextInput::make('cost.currency')->label('成本货币代码')->required()->length(3),
                TextInput::make('cost.input_per_million')->label('输入 Token 单价／百万')->numeric()->minValue(0)->required(),
                TextInput::make('cost.cached_input_per_million')->label('缓存输入 Token 单价／百万')->numeric()->minValue(0)->required(),
                TextInput::make('cost.output_per_million')->label('输出 Token 单价／百万')->numeric()->minValue(0)->required(),
                TextInput::make('budget.daily_hard_limit')->label('每日成本硬限制')->numeric()->minValue(0)->nullable(),
                TextInput::make('budget.novel_total_limit')->label('单小说总成本限制')->numeric()->minValue(0)->nullable(),
                TextInput::make('budget.chapter_max_cost')->label('单章成本限制')->numeric()->minValue(0)->nullable(),
            ]);
    }

    private function aiSection(): Section
    {
        $settings = app(AiSettingsService::class);

        return Section::make('AI')
            ->description('Provider、Stage Model 与 Timeout。保存只影响之后创建的生成任务。')
            ->icon('heroicon-o-cpu-chip')
            ->afterHeader([
                Action::make('openAiDebug')
                    ->label('AI Debug')
                    ->icon('heroicon-o-command-line')
                    ->color('gray')
                    ->url(AiDebugTest::getUrl()),
            ])
            ->schema([
                Text::make(fn (): string => '当前生效来源：'.match ($settings->current()['source']) {
                    'database' => 'system_settings.ai',
                    'environment_invalid_database' => 'config/ai.php（数据库记录无效）',
                    default => 'config/ai.php',
                })
                    ->icon('heroicon-o-information-circle')
                    ->color('gray'),
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema(collect($settings->registeredProviders())
                        ->map(fn (string $provider): TextEntry => TextEntry::make("{$provider}_credential_status")
                            ->label($this->providerLabel($provider).' API Key')
                            ->state(fn (): string => $settings->isCredentialConfigured($provider) ? '已配置' : '未配置')
                            ->badge()
                            ->color(fn (): string => $settings->isCredentialConfigured($provider) ? 'success' : 'gray'))
                        ->all()),
                Form::make([EmbeddedSchema::make('form')])
                    ->id('ai-settings-form')
                    ->livewireSubmitHandler('saveAiSettings')
                    ->columns(['default' => 1, 'md' => 2])
                    ->footer([
                        Actions::make([
                            Action::make('saveAiSettings')
                                ->label('保存 AI 配置')
                                ->icon('heroicon-o-check')
                                ->submit('saveAiSettings'),
                        ]),
                    ]),
                RepeatableEntry::make('prompt_versions')
                    ->label('Prompt Versions')
                    ->state(fn (): array => collect(app(PromptVersionResolver::class)->all())
                        ->map(fn (string $version, string $stage): array => [
                            'stage' => AiStage::from($stage)->getLabel(),
                            'version' => $version,
                            'source' => 'config/prompts.php',
                        ])
                        ->values()
                        ->all())
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('stage')->label('Stage')->badge(),
                        TextEntry::make('version')->label('Current Version')->copyable(),
                        TextEntry::make('source')->label('Source')->color('gray'),
                    ]),
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('ai_test_success')
                            ->label('Test Result')
                            ->state(fn (): ?string => $this->aiConnectionResult === null
                                ? null
                                : ($this->aiConnectionResult['success'] ? 'Success' : 'Failed'))
                            ->badge()
                            ->color(fn (): string => ($this->aiConnectionResult['success'] ?? false) ? 'success' : 'danger')
                            ->visible(fn (): bool => $this->aiConnectionResult !== null),
                        TextEntry::make('ai_test_model')
                            ->label('Response Model')
                            ->state(fn (): ?string => $this->aiConnectionResult['model'] ?? null)
                            ->visible(fn (): bool => $this->aiConnectionResult !== null),
                        TextEntry::make('ai_test_latency')
                            ->label('Latency')
                            ->state(fn (): ?string => isset($this->aiConnectionResult['latency_ms'])
                                ? $this->aiConnectionResult['latency_ms'].' ms'
                                : null)
                            ->placeholder('—')
                            ->visible(fn (): bool => $this->aiConnectionResult !== null),
                        TextEntry::make('ai_test_message')
                            ->label('说明')
                            ->state(fn (): ?string => $this->aiConnectionResult['message'] ?? null)
                            ->visible(fn (): bool => $this->aiConnectionResult !== null),
                    ]),
            ]);
    }

    public function saveAiSettings(AiSettingsService $settings): void
    {
        $result = $settings->save($this->form->getState(), auth()->id());
        $this->form->fill($result['settings']);

        Notification::make()
            ->title($result['changed'] ? 'AI 配置已保存' : 'AI 配置没有变化')
            ->success()
            ->send();
    }

    private function testAiConnection(AiProvider $provider, string $providerName, string $model): void
    {
        try {
            $response = $provider->generate(new AiRequest(
                model: $model,
                provider: $providerName,
                systemPrompt: 'You are a connection test. Reply briefly.',
                prompt: 'Reply with OK.',
                temperature: 0,
                maxTokens: 8,
                promptVersion: app(PromptVersionResolver::class)->resolve(AiStage::Planner),
                metadata: ['purpose' => 'connection_test'],
            ));

            $this->aiConnectionResult = [
                'success' => true,
                'model' => $response->model,
                'latency_ms' => $response->latencyMs,
                'message' => 'AI Provider 连接成功。',
            ];

            Notification::make()
                ->title('AI Provider 连接成功')
                ->success()
                ->send();
        } catch (AiProviderException $exception) {
            $this->aiConnectionResult = [
                'success' => false,
                'model' => $model,
                'latency_ms' => null,
                'message' => $exception->errorCode.' · '.$exception->getMessage(),
            ];

            Notification::make()
                ->title('AI Provider 连接失败')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private function connectionTestModel(string $provider): ?string
    {
        foreach ((array) data_get(app(AiSettingsService::class)->settings(), 'stages', []) as $stage) {
            if (is_array($stage) && ($stage['provider'] ?? null) === $provider && filled($stage['model'] ?? null)) {
                return (string) $stage['model'];
            }
        }

        return null;
    }

    private function budgetSection(): Section
    {
        return Section::make('预算')
            ->description('Hard Limit 达到后，新的 Provider Request 会在发送前被阻止。')
            ->icon('heroicon-o-banknotes')
            ->afterHeader([
                Text::make('只读')
                    ->badge()
                    ->color('gray'),
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
                Text::make('来源：system_settings.ai 与 Novel settings；数据库未配置时回退 config。空值表示无限制。')
                    ->icon('heroicon-o-information-circle')
                    ->color('gray')
                    ->columnSpanFull(),
            ]);
    }

    private function formatBudget(BudgetUsage $usage): string
    {
        return data_get(app(AiSettingsService::class)->costSettings(), 'currency', 'USD').' '.number_format($usage->used, 4).' / '.$this->formatLimit($usage->limit, false);
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

        return $withCurrency ? data_get(app(AiSettingsService::class)->costSettings(), 'currency', 'USD').' '.$value : $value;
    }

    private function providerLabel(string $provider): string
    {
        return $provider === 'openai' ? 'OpenAI' : ucfirst($provider);
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
                Text::make('只读')
                    ->badge()
                    ->color('gray'),
            ])
            ->schema([
                Text::make($source)
                    ->icon('heroicon-o-information-circle')
                    ->color('gray'),
            ]);
    }
}
