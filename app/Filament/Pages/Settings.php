<?php

namespace App\Filament\Pages;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use BackedEnum;
use Filament\Actions\Action;
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

    /** @var array{success: bool, model: string, latency_ms: int|null, message: string}|null */
    public ?array $aiConnectionResult = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testAiConnection')
                ->label('Test Connection')
                ->icon('heroicon-o-signal')
                ->action(fn (AiProvider $provider) => $this->testAiConnection($provider)),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('此处仅展示配置来源。各设置项将由对应任务开放编辑。')
                ->color('gray'),
            Grid::make([
                'default' => 1,
                'xl' => 2,
            ])->schema([
                $this->aiSection(),
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
                $this->placeholderSection(
                    heading: '预算',
                    description: '每日、单本小说、单章与重写成本限制。',
                    source: '来源：config 与小说设置',
                    icon: 'heroicon-o-banknotes',
                )->columnSpanFull(),
            ]),
        ]);
    }

    private function aiSection(): Section
    {
        return Section::make('AI')
            ->description('当前 Provider 配置与连接检查。凭据仅从环境配置读取。')
            ->icon('heroicon-o-cpu-chip')
            ->columns(['default' => 1, 'md' => 3])
            ->schema([
                Text::make('来源：.env 与 config/services.php；AI 运行配置：config/ai.php')
                    ->icon('heroicon-o-information-circle')
                    ->color('gray')
                    ->columnSpanFull(),
                TextEntry::make('ai_provider')
                    ->label('Provider')
                    ->state(fn (): string => (string) config('ai.provider'))
                    ->badge(),
                TextEntry::make('ai_model')
                    ->label('Model')
                    ->state(fn (): string => (string) config('ai.model')),
                TextEntry::make('ai_connection_status')
                    ->label('Connection Status')
                    ->state(fn (): string => filled(config('ai.providers.openai.api_key')) ? '已配置' : '未配置')
                    ->badge()
                    ->color(fn (): string => filled(config('ai.providers.openai.api_key')) ? 'success' : 'gray'),
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
                    ->columnSpanFull()
                    ->visible(fn (): bool => $this->aiConnectionResult !== null),
            ]);
    }

    private function testAiConnection(AiProvider $provider): void
    {
        try {
            $response = $provider->generate(new AiRequest(
                model: (string) config('ai.model'),
                systemPrompt: 'You are a connection test. Reply briefly.',
                prompt: 'Reply with OK.',
                temperature: 0,
                maxTokens: 8,
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
                'model' => (string) config('ai.model'),
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
