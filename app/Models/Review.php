<?php

namespace App\Models;

use App\Enums\ReviewDecision;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['generation_run_id', 'artifact_id', 'decision', 'score', 'continuity_score', 'plan_score', 'character_score', 'progress_score', 'repetition_score', 'pacing_score', 'style_score', 'findings'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(GenerationArtifact::class);
    }

    protected function casts(): array
    {
        return [
            'decision' => ReviewDecision::class,
            'score' => 'decimal:2', 'continuity_score' => 'decimal:2', 'plan_score' => 'decimal:2',
            'character_score' => 'decimal:2', 'progress_score' => 'decimal:2', 'repetition_score' => 'decimal:2',
            'pacing_score' => 'decimal:2', 'style_score' => 'decimal:2', 'findings' => 'array', 'created_at' => 'datetime',
        ];
    }
}
