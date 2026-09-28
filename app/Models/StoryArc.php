<?php

namespace App\Models;

use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use Database\Factories\StoryArcFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 表示小说当前运行态 Story Arc，并追踪其权威 Outline 来源。
 */
#[Fillable([
    'novel_id',
    'volume_id',
    'source_outline_arc_id',
    'sequence',
    'type',
    'title',
    'goal',
    'stakes',
    'completion_conditions',
    'progress',
    'status',
])]
class StoryArc extends Model
{
    /** @use HasFactory<StoryArcFactory> */
    use HasFactory;

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return BelongsTo<Volume, $this> */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /** @return BelongsTo<NovelOutlineArc, $this> 运行态 Arc 采用的权威大纲来源。 */
    public function sourceOutlineArc(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineArc::class, 'source_outline_arc_id');
    }

    /** @return HasMany<Foreshadowing, $this> */
    public function foreshadowings(): HasMany
    {
        return $this->hasMany(Foreshadowing::class, 'owner_arc_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => StoryArcType::class,
            'source_outline_arc_id' => 'integer',
            'sequence' => 'integer',
            'completion_conditions' => 'array',
            'progress' => 'float',
            'status' => StoryArcStatus::class,
        ];
    }
}
