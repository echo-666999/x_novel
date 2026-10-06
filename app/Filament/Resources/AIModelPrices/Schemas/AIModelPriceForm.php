<?php

namespace App\Filament\Resources\AIModelPrices\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AIModelPriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('模型定价')
                ->description('价格按指定计费单位的 Token 数填写；使用记录会保存请求发生时计算出的成本。')
                ->schema([
                    Select::make('provider')
                        ->label('供应商')
                        ->options(['openai' => 'OpenAI', 'deepseek' => 'DeepSeek'])
                        ->required()
                        ->native(false),
                    TextInput::make('model')
                        ->label('模型标识')
                        ->required()
                        ->maxLength(255)
                        ->dehydrateStateUsing(fn (string $state): string => trim($state)),
                    TextInput::make('currency')
                        ->label('币种')
                        ->default('USD')
                        ->length(3)
                        ->required()
                        ->formatStateUsing(fn (?string $state): string => strtoupper($state ?? 'USD'))
                        ->dehydrateStateUsing(fn (string $state): string => strtoupper(trim($state))),
                    TextInput::make('billing_unit')
                        ->label('计费单位 Token 数')
                        ->integer()
                        ->minValue(1)
                        ->default(1_000_000)
                        ->required(),
                    TextInput::make('input_price')
                        ->label('输入价格')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.000001'),
                    TextInput::make('cached_input_price')
                        ->label('缓存输入价格')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.000001'),
                    TextInput::make('output_price')
                        ->label('输出价格')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.000001'),
                    Toggle::make('is_enabled')
                        ->label('启用')
                        ->default(true),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('模型容量与能力')
                ->description('请按实际 Provider 文档和账户可用模型核实。容量未填写或能力未开启的模型不能用于新的 Outline 批次。')
                ->schema([
                    TextInput::make('context_window_tokens')
                        ->label('上下文窗口 Token')
                        ->integer()
                        ->minValue(1)
                        ->helperText('模型一次请求可接受的输入与输出 Token 总上限。'),
                    TextInput::make('max_output_tokens')
                        ->label('最大输出 Token')
                        ->integer()
                        ->minValue(1)
                        ->helperText('模型单次响应允许的最大输出 Token。'),
                    Toggle::make('supports_structured_output')
                        ->label('支持结构化输出')
                        ->helperText('Outline 必须使用响应 Schema 或等价的 JSON 结构化输出能力。'),
                    Toggle::make('supports_reasoning_effort')
                        ->label('支持推理程度')
                        ->helperText('只有模型实际接受 reasoning_effort 时才开启。'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
