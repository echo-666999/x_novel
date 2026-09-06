<?php

namespace App\Models;

use App\Enums\CharacterStatus;
use Database\Factories\CharacterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'novel_id',
    'name',
    'aliases',
    'role',
    'profile',
    'motivation',
    'personality',
    'abilities',
    'knowledge',
    'current_state',
    'locked_fields',
    'status',
])]
class Character extends Model
{
    /** @use HasFactory<CharacterFactory> */
    use HasFactory;

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'profile' => 'array',
            'personality' => 'array',
            'abilities' => 'array',
            'knowledge' => 'array',
            'current_state' => 'array',
            'locked_fields' => 'array',
            'status' => CharacterStatus::class,
        ];
    }
}
