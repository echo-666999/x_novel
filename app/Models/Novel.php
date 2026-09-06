<?php

namespace App\Models;

use App\Enums\NovelStatus;
use Database\Factories\NovelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'title',
    'genre',
    'premise',
    'target_words',
    'status',
    'current_chapter_sequence',
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_words' => 'integer',
            'status' => NovelStatus::class,
            'current_chapter_sequence' => 'integer',
            'settings' => 'array',
        ];
    }
}
