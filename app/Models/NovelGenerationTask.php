<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 整书目标与控制状态，不持有 Worker 租约。 */
#[Fillable(['novel_id', 'submission_key', 'status', 'mode', 'intent_artifact_id', 'target_words', 'opening_chapter_count', 'policy_snapshot', 'policy_revision', 'control_version', 'current_phase', 'current_chapter_id', 'pending_transition', 'progress_snapshot', 'last_progress_at', 'stop_reason', 'stop_detail', 'replaces_task_id', 'created_by', 'export_manifest_id', 'started_at', 'finished_at'])]
class NovelGenerationTask extends Model
{
    use HasFactory;

    /** 带偏移写入 timestamptz，避免上海应用时区被 PostgreSQL UTC 会话误解八小时。 */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** 结构化策略和时间按明确类型读取，避免字符串状态误判。 */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'intent_artifact_id' => 'integer', 'target_words' => 'integer', 'opening_chapter_count' => 'integer', 'policy_snapshot' => 'array', 'policy_revision' => 'integer', 'control_version' => 'integer', 'current_chapter_id' => 'integer', 'pending_transition' => 'array', 'progress_snapshot' => 'array', 'last_progress_at' => 'datetime', 'stop_detail' => 'array', 'replaces_task_id' => 'integer', 'created_by' => 'integer', 'export_manifest_id' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** 所有任务/问题必须属于同一本小说。 */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }
}
