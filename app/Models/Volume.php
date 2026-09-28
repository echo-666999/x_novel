<?php

namespace App\Models;

use App\Enums\VolumeStatus;
use Database\Factories\VolumeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 表示小说当前运行态 Volume，并追踪其权威 Outline 来源。
 */
#[Fillable([
    'novel_id',
    'source_outline_volume_id',
    'sequence',
    'title',
    'goal',
    'climax',
    'target_words',
    'status',
    'summary',
])]
class Volume extends Model
{
    /** @use HasFactory<VolumeFactory> */
    use HasFactory;

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return BelongsTo<NovelOutlineVolume, $this> 运行态 Volume 采用的权威大纲来源。 */
    public function sourceOutlineVolume(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineVolume::class, 'source_outline_volume_id');
    }

    /** @return HasMany<StoryArc, $this> */
    public function storyArcs(): HasMany
    {
        return $this->hasMany(StoryArc::class);
    }

    /** @return HasMany<Chapter, $this> */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('sequence');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'source_outline_volume_id' => 'integer',
            'target_words' => 'integer',
            'status' => VolumeStatus::class,
        ];
    }
}
