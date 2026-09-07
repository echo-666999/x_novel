<?php

namespace App\Models;

use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use Database\Factories\GenerationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'novel_id',
    'chapter_id',
    'scene_id',
    'scope_type',
    'scope_id',
    'stage',
    'status',
    'attempt',
    'idempotency_key',
    'input_hash',
    'state_version',
    'bible_version',
    'prompt_version',
    'model_policy',
    'context_snapshot',
    'error_code',
    'error_message',
    'started_at',
    'finished_at',
])]
class GenerationRun extends Model
{
    /** @use HasFactory<GenerationRunFactory> */
    use HasFactory;

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /** @return BelongsTo<Scene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    /** @return HasMany<GenerationArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(GenerationArtifact::class)->orderBy('created_at');
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    public function durationMilliseconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->finished_at);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'chapter_id' => 'integer',
            'scene_id' => 'integer',
            'scope_id' => 'integer',
            'stage' => GenerationStage::class,
            'status' => RunStatus::class,
            'attempt' => 'integer',
            'state_version' => 'integer',
            'bible_version' => 'integer',
            'context_snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
