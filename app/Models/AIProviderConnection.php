<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider',
    'name',
    'base_url',
    'credential_ref',
    'connect_timeout',
    'timeout',
    'is_enabled',
    'last_verified_at',
])]
/** 只持有连接与凭据引用，密钥由部署环境提供。 */
class AIProviderConnection extends Model
{
    protected $table = 'ai_provider_connections';

    protected $hidden = ['api_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // 旧密文字段不再解密或作为新请求凭据。
            'connect_timeout' => 'integer',
            'timeout' => 'integer',
            'is_enabled' => 'boolean',
            'last_verified_at' => 'immutable_datetime',
        ];
    }

    /** 即使forceFill也不能重新把密钥保存进数据库。 */
    public function setApiKeyAttribute(mixed $value): void
    {
        if ($value !== null) {
            throw new \LogicException('API密钥只能配置在.env，数据库只保存引用。');
        }
        $this->attributes['api_key'] = null;
    }
}
