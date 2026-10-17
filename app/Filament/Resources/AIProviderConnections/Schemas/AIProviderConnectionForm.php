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
                        ->label('服务商')
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
                        ->helperText('填写服务商提供的 HTTP 或 HTTPS API Base URL。'),
                    // 表单只传递引用，绝不接收或回显部署密钥。
                    Select::make('credential_ref')
                        ->label('环境凭据引用')
                        ->options(['openai' => 'OpenAI 环境密钥', 'deepseek' => 'DeepSeek 环境密钥'])
                        ->required()
                        ->helperText('密钥在 .env 中配置；此处只保存引用，部署重载后生效。'),
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
                        ->default(150)
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
