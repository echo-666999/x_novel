<?php

namespace App\Models;

use Database\Factories\StoryStateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'novel_id',
    'version',
    'chapter_id',
    'state',
    'checksum',
])]
class StoryStateVersion extends Model
{
    /** @use HasFactory<StoryStateVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Story State Version 不可原地修改。');
        });

        static::deleting(function (): void {
            throw new LogicException('Story State Version 不可删除。');
        });
    }

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

    /** @return HasMany<StoryEvent, $this> */
    public function storyEvents(): HasMany
    {
        return $this->hasMany(StoryEvent::class, 'state_version', 'version')
            ->where('novel_id', $this->novel_id);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'chapter_id' => 'integer',
            'state' => 'array',
        ];
    }
}
