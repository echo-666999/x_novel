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

#[Fillable(['novel_id', 'chapter_id', 'scene_id', 'event_type', 'subject_type', 'subject_id', 'payload', 'evidence', 'story_time', 'state_version', 'status', 'invalidated_at'])]
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

    protected function casts(): array
    {
        return [
            'event_type' => EventType::class,
            'payload' => 'array',
            'evidence' => 'array',
            'state_version' => 'integer',
            'status' => StoryEventStatus::class,
            'invalidated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
