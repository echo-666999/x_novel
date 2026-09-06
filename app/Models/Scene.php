<?php

namespace App\Models;

use App\Enums\SceneStatus;
use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'chapter_id',
    'sequence',
    'pov_character_id',
    'location',
    'time_anchor',
    'goal',
    'conflict',
    'turn',
    'outcome',
    'status',
    'current_artifact_id',
])]
class Scene extends Model
{
    /** @use HasFactory<SceneFactory> */
    use HasFactory;

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /** @return BelongsTo<Character, $this> */
    public function povCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'pov_character_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'pov_character_id' => 'integer',
            'status' => SceneStatus::class,
            'current_artifact_id' => 'integer',
        ];
    }
}
