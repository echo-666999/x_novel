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
                        ->maxLength(255),
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
        ]);
    }
}
