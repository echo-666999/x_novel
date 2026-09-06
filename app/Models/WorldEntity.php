<?php

namespace App\Models;

use App\Enums\WorldEntityStatus;
use App\Enums\WorldEntityType;
use Database\Factories\WorldEntityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'novel_id',
    'type',
    'name',
    'description',
    'attributes',
    'rules',
    'current_state',
    'locked_fields',
    'status',
])]
class WorldEntity extends Model
{
    /** @use HasFactory<WorldEntityFactory> */
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
            'type' => WorldEntityType::class,
            'attributes' => 'array',
            'rules' => 'array',
            'current_state' => 'array',
            'locked_fields' => 'array',
            'status' => WorldEntityStatus::class,
        ];
    }
}
