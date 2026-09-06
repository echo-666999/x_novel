<?php

namespace App\Models;

use Database\Factories\UsageRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'generation_run_id',
    'novel_id',
    'chapter_id',
    'provider',
    'model',
    'input_tokens',
    'output_tokens',
    'cached_tokens',
    'latency_ms',
    'estimated_cost',
    'request_id',
])]
class UsageRecord extends Model
{
    /** @use HasFactory<UsageRecordFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generation_run_id' => 'integer',
            'novel_id' => 'integer',
            'chapter_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'latency_ms' => 'integer',
            'estimated_cost' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }
}
