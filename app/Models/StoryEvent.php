<?php

namespace App\Models;

use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use Database\Factories\StoryEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 保存 Canonical Story Event 及其可审计的 Outline 完成来源父链。
 */
#[Fillable(['novel_id', 'chapter_id', 'scene_id', 'event_type', 'subject_type', 'subject_id', 'payload', 'evidence', 'story_time', 'state_version', 'status', 'invalidated_at', 'novel_outline_id', 'novel_outline_arc_id', 'novel_outline_beat_id', 'novel_outline_milestone_id'])]
class StoryEvent extends Model
{
    /** @use HasFactory<StoryEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (StoryEvent $event): void {
            $changedColumns = array_keys($event->getDirty());
            $allowedColumns = ['status', 'invalidated_at'];

            if (array_diff($changedColumns, $allowedColumns) !== []) {
                throw new LogicException('Story events are append-only; only invalidation metadata may be changed.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Story events are append-only and cannot be deleted directly.');
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StoryEventStatus::Active);
    }

    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    /** @return BelongsTo<NovelOutline, $this> 完成事件发生时冻结的大纲版本。 */
    public function novelOutline(): BelongsTo
    {
        return $this->belongsTo(NovelOutline::class);
    }

    /** @return BelongsTo<NovelOutlineArc, $this> 完成事件引用的 Arc 定义。 */
    public function novelOutlineArc(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineArc::class);
    }

    /** @return BelongsTo<NovelOutlineBeat, $this> 完成事件引用的 Beat 定义。 */
    public function novelOutlineBeat(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineBeat::class);
    }

    /** @return BelongsTo<NovelOutlineMilestone, $this> Milestone 完成事件引用的定义。 */
    public function novelOutlineMilestone(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineMilestone::class);
    }

    protected function casts(): array
    {
        return [
            'event_type' => EventType::class,
            'payload' => 'array',
            'evidence' => 'array',
            'state_version' => 'integer',
            'novel_outline_id' => 'integer',
            'novel_outline_arc_id' => 'integer',
            'novel_outline_beat_id' => 'integer',
            'novel_outline_milestone_id' => 'integer',
            'status' => StoryEventStatus::class,
            'invalidated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
