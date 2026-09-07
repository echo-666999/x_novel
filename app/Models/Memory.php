<?php

namespace App\Models;

use App\Enums\GenerationStage;
use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use Database\Factories\MemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'novel_id',
    'type',
    'source_type',
    'source_id',
    'summary',
    'entities',
    'salience',
    'status',
    'embedding',
    'embedding_model',
    'valid_from_chapter',
    'valid_to_chapter',
])]
class Memory extends Model
{
    /** @use HasFactory<MemoryFactory> */
    use HasFactory;

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MemoryStatus::Active);
    }

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return HasOne<GenerationRun, $this> */
    public function embeddingRun(): HasOne
    {
        return $this->hasOne(GenerationRun::class, 'scope_id')
            ->where('scope_type', 'memory')
            ->where('stage', GenerationStage::Embedding)
            ->latestOfMany();
    }

    public function sourceLabel(): string
    {
        return match ($this->source_type) {
            'chapter' => '章节 #'.$this->source_id,
            'story_event' => '故事事件 #'.$this->source_id,
            'story_state_version' => '状态版本 #'.$this->source_id,
            default => $this->source_type.' #'.$this->source_id,
        };
    }

    public function hasEmbedding(): bool
    {
        return $this->embedding !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MemoryType::class,
            'source_id' => 'integer',
            'entities' => 'array',
            'salience' => 'decimal:3',
            'status' => MemoryStatus::class,
            'valid_from_chapter' => 'integer',
            'valid_to_chapter' => 'integer',
        ];
    }
}
