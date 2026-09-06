<?php

namespace App\Models;

use App\Enums\VolumeStatus;
use Database\Factories\VolumeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'novel_id',
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
            'target_words' => 'integer',
            'status' => VolumeStatus::class,
        ];
    }
}
