<?php

namespace App\Filament\Pages;

use App\AI\AiDebugService;
use App\AI\AiSettingsResolver;
use App\AI\Exceptions\AiProviderException;
use App\AI\PromptVersionResolver;
use App\Enums\AiStage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class AiDebugTest extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-command-line';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'settings/ai-debug';

    protected static ?string $title = 'AI Debug Test';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    /** @var array{code: string, message: string, retryable: bool}|null */
    public ?array $error = null;

    public function mount(): void
    {
        $this->syncStage(AiStage::Planner, fillInput: true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToSettings')
                ->label('Back to Settings')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(Settings::getUrl()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('task_type')
                    ->label('Task Type')
                    ->options($this->taskTypeOptions())
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $stage = AiStage::tryFrom((string) $state);

                        if ($stage === null) {
                            return;
                        }

                        $set('model', app(AiSettingsResolver::class)->modelFor($stage));
                        $set('prompt_version', app(PromptVersionResolver::class)->resolve($stage));
                    })
                    ->required(),
                TextInput::make('model')
                    ->label('Model')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('prompt_version')
                    ->label('Prompt Version')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('input')
                    ->label('Input')
                    ->placeholder('输入一段用于验证 Provider 的简单指令。')
                    ->rows(8)
                    ->maxLength(10_000)
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 2])
                ->schema([
                    Section::make('Test Request')
                        ->description('请求会使用当前全局 Stage Model 和 Prompt Version，并计入真实 Usage。')
                        ->schema([
                            Form::make([EmbeddedSchema::make('form')])
                                ->id('ai-debug-form')
                                ->livewireSubmitHandler('runTest')
                                ->footer([
                                    Actions::make([
                                        Action::make('runTest')
                                            ->label('Run Test')
                                            ->icon('heroicon-o-play')
                                            ->submit('runTest'),
                                    ]),
                                ]),
                        ]),
                    Section::make('Test Result')
                        ->description('显示 Provider 返回内容与本次调用指标。')
                        ->schema([
                            TextEntry::make('debug_response')
                                ->label('Response')
                                ->state(fn (): ?string => $this->result['response'] ?? null)
                                ->placeholder('运行测试后显示响应。')
                                ->fontFamily('mono')
                                ->prose()
                                ->copyable()
                                ->columnSpanFull(),
                            Grid::make(['default' => 2, 'md' => 4])
                                ->schema([
                                    TextEntry::make('debug_input_tokens')
                                        ->label('Input Tokens')
                                        ->state(fn (): ?int => $this->result['input_tokens'] ?? null)
                                        ->placeholder('—')
                                        ->numeric(),
                                    TextEntry::make('debug_output_tokens')
                                        ->label('Output Tokens')
                                        ->state(fn (): ?int => $this->result['output_tokens'] ?? null)
                                        ->placeholder('—')
                                        ->numeric(),
                                    TextEntry::make('debug_cost')
                                        ->label('Cost')
                                        ->state(fn (): ?string => isset($this->result['cost'])
                                            ? config('ai.cost.currency').' '.number_format((float) $this->result['cost'], 4)
                                            : null)
                                        ->placeholder('—'),
                                    TextEntry::make('debug_latency')
                                        ->label('Latency')
                                        ->state(fn (): ?string => isset($this->result['latency_ms'])
                                            ? $this->result['latency_ms'].' ms'
                                            : null)
                                        ->placeholder('—'),
                                ]),
                            TextEntry::make('debug_error')
                                ->label('Error')
                                ->state(fn (): ?string => $this->error === null
                                    ? null
                                    : $this->error['code'].' · '.$this->error['message'])
                                ->badge()
                                ->color('danger')
                                ->visible(fn (): bool => $this->error !== null),
                            TextEntry::make('debug_retryable')
                                ->label('Retryable')
                                ->state(fn (): ?string => $this->error === null
                                    ? null
                                    : ($this->error['retryable'] ? 'Yes' : 'No'))
                                ->badge()
                                ->color(fn (): string => ($this->error['retryable'] ?? false) ? 'warning' : 'gray')
                                ->visible(fn (): bool => $this->error !== null),
                        ]),
                ]),
        ]);
    }

    public function runTest(AiDebugService $debugService): void
    {
        $data = $this->form->getState();
        $stage = AiStage::from($data['task_type']);

        $this->result = null;
        $this->error = null;

        try {
            $debugResult = $debugService->run($stage, $data['input']);
            $response = $debugResult->response;

            $this->result = [
                'response' => $response->content,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost' => $debugResult->estimatedCost,
                'latency_ms' => $response->latencyMs,
            ];

            Notification::make()->title('AI Debug Test completed')->success()->send();
        } catch (AiProviderException $exception) {
            $this->error = [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'retryable' => $exception->retryable,
            ];

            Notification::make()->title('AI Debug Test failed')->body($exception->getMessage())->danger()->send();
        }
    }

    /** @return array<string, string> */
    private function taskTypeOptions(): array
    {
        return collect(app(PromptVersionResolver::class)->all())
            ->mapWithKeys(fn (string $version, string $stage): array => [
                $stage => AiStage::from($stage)->getLabel(),
            ])
            ->all();
    }

    private function syncStage(AiStage $stage, bool $fillInput = false): void
    {
        $this->data = [
            'task_type' => $stage->value,
            'model' => app(AiSettingsResolver::class)->modelFor($stage),
            'prompt_version' => app(PromptVersionResolver::class)->resolve($stage),
            'input' => $fillInput ? 'Reply with OK.' : ($this->data['input'] ?? ''),
        ];
    }
}
