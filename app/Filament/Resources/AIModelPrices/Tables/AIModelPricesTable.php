<?php

namespace App\Filament\Resources\AIModelPrices\Tables;

use App\Models\AIModelPrice;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AIModelPricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->label('供应商')->badge()->searchable()->sortable(),
                TextColumn::make('model')->label('模型')->searchable()->sortable(),
                TextColumn::make('currency')->label('币种')->badge(),
                TextColumn::make('billing_unit')->label('计费单位')->numeric()->sortable(),
                TextColumn::make('context_window_tokens')->label('上下文窗口')->numeric()->placeholder('未核实')->sortable(),
                TextColumn::make('max_output_tokens')->label('最大输出')->numeric()->placeholder('未核实')->sortable(),
                IconColumn::make('supports_structured_output')->label('结构化输出')->boolean(),
                IconColumn::make('supports_reasoning_effort')->label('推理程度')->boolean(),
                TextColumn::make('input_price')->label('输入价格')->formatStateUsing(self::formatPrice(...)),
                TextColumn::make('cached_input_price')->label('缓存输入价格')->formatStateUsing(self::formatPrice(...)),
                TextColumn::make('output_price')->label('输出价格')->formatStateUsing(self::formatPrice(...)),
                IconColumn::make('is_enabled')->label('启用')->boolean(),
            ])
            ->defaultSort('model')
            ->emptyStateHeading('暂无模型价格')
            ->emptyStateDescription('为实际使用的供应商模型配置 Token 价格。')
            ->emptyStateIcon('heroicon-o-currency-dollar')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    private static function formatPrice(mixed $state, AIModelPrice $record): string
    {
        return $state === null ? '—' : $record->currency.' '.rtrim(rtrim((string) $state, '0'), '.');
    }
}
