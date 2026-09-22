<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider',
    'model',
    'currency',
    'billing_unit',
    'input_price',
    'cached_input_price',
    'output_price',
    'is_enabled',
])]
class AIModelPrice extends Model
{
    protected $table = 'ai_model_prices';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_unit' => 'integer',
            'input_price' => 'decimal:12',
            'cached_input_price' => 'decimal:12',
            'output_price' => 'decimal:12',
            'is_enabled' => 'boolean',
        ];
    }
}
