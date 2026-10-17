<?php

namespace App\Models;

use Database\Factories\UsageRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 实际请求账本；未知金额保留null，不因Run结束删除。 */
#[Fillable([
    'request_key', 'generation_task_id', 'issue_id', 'status', 'cost_status', 'currency', 'price_snapshot',
    'actual_cost', 'reserved_amount', 'sent_at', 'settled_at', 'reconciled_at', 'cap_snapshot', 'reconciliation_history',

    'generation_run_id',
    'novel_id',
    'chapter_id',
    'provider',
    'model',
    'input_tokens',
    'output_tokens',
    'reasoning_tokens',
    'cached_tokens',
    'latency_ms',
    'estimated_cost',
    'request_id',
    'request_metadata',
])]
class UsageRecord extends Model
{
    /** @use HasFactory<UsageRecordFactory> */
    use HasFactory;

    /** PostgreSQL带时区字段必须接收明确偏移。 */
    protected $dateFormat = 'Y-m-d H:i:sP';

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

    /** @return BelongsTo<GenerationRun, $this> */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
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
            'reasoning_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'latency_ms' => 'integer',
            'estimated_cost' => 'decimal:8',
            'actual_cost' => 'decimal:8', 'reserved_amount' => 'decimal:8',
            'price_snapshot' => 'array', 'cap_snapshot' => 'array', 'reconciliation_history' => 'array',
            'sent_at' => 'datetime', 'settled_at' => 'datetime', 'reconciled_at' => 'datetime',
            'request_metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
