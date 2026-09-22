<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider',
    'name',
    'base_url',
    'api_key',
    'connect_timeout',
    'timeout',
    'is_enabled',
    'last_verified_at',
])]
class AIProviderConnection extends Model
{
    protected $table = 'ai_provider_connections';

    protected $hidden = ['api_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'connect_timeout' => 'integer',
            'timeout' => 'integer',
            'is_enabled' => 'boolean',
            'last_verified_at' => 'immutable_datetime',
        ];
    }
}
