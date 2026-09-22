<?php

namespace App\Filament\Resources\AIProviderConnections\Tables;

use App\AI\AiProviderConnectionTester;
use App\Models\AIProviderConnection;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class AIProviderConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->label('供应商')->badge()->sortable(),
                TextColumn::make('name')->label('连接名称')->searchable()->sortable(),
                TextColumn::make('base_url')->label('API 地址')->limit(48)->copyable(),
                TextColumn::make('masked_key')->label('API 密钥')->state('••••••••'),
                TextColumn::make('timeouts')
                    ->label('超时')
                    ->state(fn (AIProviderConnection $record): string => "{$record->connect_timeout}s / {$record->timeout}s"),
                IconColumn::make('is_enabled')->label('启用')->boolean(),
                TextColumn::make('last_verified_at')
                    ->label('最近验证')
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('尚未验证')
                    ->sortable(),
            ])
            ->defaultSort('provider')
            ->emptyStateHeading('暂无供应商连接')
            ->emptyStateDescription('创建 OpenAI 或 DeepSeek 连接，并保存加密 API Key。')
            ->emptyStateIcon('heroicon-o-server-stack')
            ->recordActions([
                Action::make('testConnection')
                    ->label('测试连接')
                    ->icon('heroicon-o-signal')
                    ->action(function (AIProviderConnection $record, AiProviderConnectionTester $tester): void {
                        try {
                            $result = $tester->test($record);
                            Notification::make()
                                ->title("连接成功，发现 {$result['model_count']} 个模型")
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('连接测试失败')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make(),
            ]);
    }
}
