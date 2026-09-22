<?php

namespace App\Filament\Resources\AIProviderConnections\Schemas;

use App\AI\AiSettingsService;
use App\Models\AIProviderConnection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AIProviderConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('连接设置')
                ->schema([
                    Select::make('provider')
                        ->label('供应商')
                        ->options(['openai' => 'OpenAI', 'deepseek' => 'DeepSeek'])
                        ->required()
                        ->native(false)
                        ->unique(AIProviderConnection::class, 'provider', ignoreRecord: true),
                    TextInput::make('name')
                        ->label('连接名称')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('base_url')
                        ->label('API 地址')
                        ->url()
                        ->required()
                        ->maxLength(AiSettingsService::URL_MAX_LENGTH)
                        ->helperText('填写供应商提供的 HTTP 或 HTTPS API Base URL。'),
                    TextInput::make('api_key')
                        ->label('API 密钥')
                        ->password()
                        ->revealable(false)
                        ->autocomplete(false)
                        ->formatStateUsing(fn (): null => null)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText('密钥使用 Laravel 加密保存；编辑时留空保留现有密钥。'),
                    TextInput::make('connect_timeout')
                        ->label('连接超时（秒）')
                        ->integer()
                        ->minValue(AiSettingsService::MIN_TIMEOUT_SECONDS)
                        ->maxValue(AiSettingsService::MAX_CONNECT_TIMEOUT_SECONDS)
                        ->default(10)
                        ->required(),
                    TextInput::make('timeout')
                        ->label('请求超时（秒）')
                        ->integer()
                        ->minValue(AiSettingsService::MIN_TIMEOUT_SECONDS)
                        ->maxValue(AiSettingsService::MAX_REQUEST_TIMEOUT_SECONDS)
                        ->gte('connect_timeout')
                        ->default(60)
                        ->required(),
                    Toggle::make('is_enabled')
                        ->label('启用')
                        ->default(true),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
