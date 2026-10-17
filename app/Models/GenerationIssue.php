<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 跨执行尝试的问题身份与复验记录。 */
#[Fillable(['novel_id', 'generation_task_id', 'chapter_id', 'fingerprint', 'source_identity', 'category', 'error_code', 'severity', 'status', 'first_run_id', 'latest_run_id', 'resolved_by_run_id', 'user_message', 'evidence', 'allowed_actions', 'attempt_count', 'repair_elapsed_ms', 'repair_limit_snapshot', 'deadline_at', 'verification', 'resolution_reason', 'first_seen_at', 'last_seen_at', 'resolved_at'])]
class GenerationIssue extends Model
{
    use HasFactory;

    /** 带偏移写入 timestamptz，避免上海应用时区被 PostgreSQL UTC 会话误解八小时。 */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** 结构化策略和时间按明确类型读取，避免字符串状态误判。 */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'generation_task_id' => 'integer', 'chapter_id' => 'integer', 'source_identity' => 'array', 'first_run_id' => 'integer', 'latest_run_id' => 'integer', 'resolved_by_run_id' => 'integer', 'evidence' => 'array', 'allowed_actions' => 'array', 'attempt_count' => 'integer', 'repair_elapsed_ms' => 'integer', 'repair_limit_snapshot' => 'array', 'deadline_at' => 'datetime', 'verification' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** 所有任务/问题必须属于同一本小说。 */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }
}
