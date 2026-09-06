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

#[Fillable([
    'novel_id',
    'volume_id',
    'type',
    'title',
    'goal',
    'stakes',
    'beats',
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
            'beats' => 'array',
            'completion_conditions' => 'array',
            'progress' => 'float',
            'status' => StoryArcStatus::class,
        ];
    }
}
