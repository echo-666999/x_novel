<?php

namespace App\Filament\Resources\AIProviderConnections\Tables;

use App\AI\AiProviderConnectionTester;
use App\AI\AiSettingsService;
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
                TextColumn::make('provider')->label('服务商')->badge()->sortable(),
                TextColumn::make('name')->label('连接名称')->searchable()->sortable(),
                TextColumn::make('base_url')->label('API 地址')->limit(48)->copyable(),
                // 引用可见，配置状态可确认；不显示或读取任何秘密值。
                TextColumn::make('credential_ref')->label('凭据引用')->placeholder('尚未选择'),
                IconColumn::make('credential_configured')->label('环境凭据已配置')->state(fn (AIProviderConnection $record): bool => app(AiSettingsService::class)->isCredentialConfigured($record->provider))->boolean(),
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
            ->emptyStateHeading('暂无服务商连接')
            ->emptyStateDescription('创建服务商连接并选择凭据引用；API Key 仅在部署环境中配置。')
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
