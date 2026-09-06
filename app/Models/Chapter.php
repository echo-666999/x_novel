<?php

namespace App\Models;

use App\Enums\ChapterStatus;
use Database\Factories\ChapterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'novel_id',
    'volume_id',
    'sequence',
    'title',
    'status',
    'canonical_artifact_id',
    'word_count',
    'summary',
])]
class Chapter extends Model
{
    /** @use HasFactory<ChapterFactory> */
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

    /** @return HasMany<StoryStateVersion, $this> */
    public function stateVersions(): HasMany
    {
        return $this->hasMany(StoryStateVersion::class);
    }

    /** @return HasOne<StoryStateVersion, $this> */
    public function latestStateVersion(): HasOne
    {
        return $this->hasOne(StoryStateVersion::class)->ofMany('version', 'max');
    }

    /** @return HasMany<ChapterPlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(ChapterPlan::class)->orderBy('version');
    }

    /** @return HasOne<ChapterPlan, $this> */
    public function latestPlan(): HasOne
    {
        return $this->hasOne(ChapterPlan::class)->ofMany('version', 'max');
    }

    /** @return HasMany<Scene, $this> */
    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('sequence');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'volume_id' => 'integer',
            'sequence' => 'integer',
            'status' => ChapterStatus::class,
            'canonical_artifact_id' => 'integer',
            'word_count' => 'integer',
        ];
    }
}
