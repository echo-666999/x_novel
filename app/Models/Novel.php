<?php

namespace App\Models;

use App\Enums\NovelStatus;
use Database\Factories\NovelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'title',
    'genre',
    'premise',
    'target_words',
    'status',
    'current_chapter_sequence',
    'canonical_state_version_id',
    'settings',
])]
class Novel extends Model
{
    /** @use HasFactory<NovelFactory> */
    use HasFactory;

    /** @return HasMany<NovelBible, $this> */
    public function bibles(): HasMany
    {
        return $this->hasMany(NovelBible::class)->orderByDesc('version');
    }

    /** @return HasOne<NovelBible, $this> */
    public function currentBible(): HasOne
    {
        return $this->hasOne(NovelBible::class)->ofMany('version', 'max');
    }

    /** @return HasMany<Volume, $this> */
    public function volumes(): HasMany
    {
        return $this->hasMany(Volume::class)->orderBy('sequence');
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

    /** @return HasMany<Character, $this> */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class)->orderBy('name');
    }

    /** @return HasMany<WorldEntity, $this> */
    public function worldEntities(): HasMany
    {
        return $this->hasMany(WorldEntity::class)->orderBy('name');
    }

    /** @return HasMany<Foreshadowing, $this> */
    public function foreshadowings(): HasMany
    {
        return $this->hasMany(Foreshadowing::class);
    }

    /** @return HasMany<Fact, $this> */
    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }

    /** @return HasMany<StoryStateVersion, $this> */
    public function storyStateVersions(): HasMany
    {
        return $this->hasMany(StoryStateVersion::class);
    }

    /** @return BelongsTo<StoryStateVersion, $this> */
    public function canonicalStateVersion(): BelongsTo
    {
        return $this->belongsTo(StoryStateVersion::class, 'canonical_state_version_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_words' => 'integer',
            'status' => NovelStatus::class,
            'current_chapter_sequence' => 'integer',
            'canonical_state_version_id' => 'integer',
            'settings' => 'array',
        ];
    }
}
