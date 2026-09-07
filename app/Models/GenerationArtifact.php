<?php

namespace App\Models;

use App\Enums\ArtifactType;
use Database\Factories\GenerationArtifactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'generation_run_id',
    'type',
    'version',
    'content',
    'data',
    'checksum',
])]
class GenerationArtifact extends Model
{
    /** @use HasFactory<GenerationArtifactFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Generation artifacts are immutable; create a new version instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('Generation artifacts are immutable and cannot be deleted.');
        });
    }

    /** @return BelongsTo<GenerationRun, $this> */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ArtifactType::class,
            'version' => 'integer',
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
