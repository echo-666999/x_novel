<?php

namespace App\Models;

use App\Enums\ArtifactType;
use App\Enums\ArtifactValidationStatus;
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
    'input_hash',
    'source_artifact_id',
    'validation_status',
])]
class GenerationArtifact extends Model
{
    /** @use HasFactory<GenerationArtifactFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (GenerationArtifact $artifact): void {
            $run = $artifact->generationRun()->firstOrFail();
            if (filled($artifact->input_hash) && ! hash_equals((string) $run->input_hash, (string) $artifact->input_hash)) {
                throw new LogicException('Artifact input hash must match its Generation Run.');
            }
            $artifact->input_hash = $run->input_hash;
            $sourceId = data_get($artifact->data, 'source_artifact_id');
            if (is_numeric($sourceId) && $artifact->source_artifact_id !== null
                && (int) $artifact->source_artifact_id !== (int) $sourceId) {
                throw new LogicException('Artifact source column and payload must identify the same upstream artifact.');
            }
            $artifact->source_artifact_id ??= is_numeric($sourceId) ? (int) $sourceId : null;
            $artifact->validation_status ??= ArtifactValidationStatus::Validated;
        });

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

    public function sourceArtifact(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_artifact_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ArtifactType::class,
            'version' => 'integer',
            'data' => 'array',
            'validation_status' => ArtifactValidationStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
