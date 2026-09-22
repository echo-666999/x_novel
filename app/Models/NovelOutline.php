<?php

namespace App\Models;

use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use Database\Factories\NovelOutlineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'novel_id',
    'version',
    'status',
    'source',
    'schema_version',
    'content',
    'checksum',
    'based_on_outline_id',
    'created_by',
    'applied_at',
])]
class NovelOutline extends Model
{
    /** @use HasFactory<NovelOutlineFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (NovelOutline $outline): void {
            $allowed = ['status', 'applied_at', 'updated_at'];
            if (array_diff(array_keys($outline->getDirty()), $allowed) !== []) {
                throw new LogicException('Novel Outline versions are immutable; create a new version instead.');
            }
        });

        static::deleting(function (NovelOutline $outline): void {
            if ($outline->chapterPlans()->exists()) {
                throw new LogicException('Novel Outline versions referenced by Chapter Plans cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return BelongsTo<NovelOutline, $this> */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_outline_id');
    }

    /** @return HasMany<NovelOutline, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'based_on_outline_id')->orderBy('version');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ChapterPlan, $this> */
    public function chapterPlans(): HasMany
    {
        return $this->hasMany(ChapterPlan::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => NovelOutlineStatus::class,
            'source' => NovelOutlineSource::class,
            'schema_version' => 'integer',
            'content' => 'array',
            'based_on_outline_id' => 'integer',
            'created_by' => 'integer',
            'applied_at' => 'datetime',
        ];
    }
}
