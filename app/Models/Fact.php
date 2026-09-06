<?php

namespace App\Models;

use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use Database\Factories\FactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'novel_id',
    'subject_type',
    'subject_id',
    'predicate',
    'value',
    'hardness',
    'confidence',
    'status',
    'locked',
    'source_type',
    'source_event_id',
])]
class Fact extends Model
{
    /** @use HasFactory<FactFactory> */
    use HasFactory;

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return BelongsTo<Character, $this> */
    public function characterSubject(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'subject_id');
    }

    /** @return BelongsTo<WorldEntity, $this> */
    public function worldEntitySubject(): BelongsTo
    {
        return $this->belongsTo(WorldEntity::class, 'subject_id');
    }

    public function subjectLabel(): string
    {
        return match ($this->subject_type) {
            'character' => $this->characterSubject?->name ?? '人物 #'.$this->subject_id,
            'world_entity' => $this->worldEntitySubject?->name ?? '世界实体 #'.$this->subject_id,
            'novel' => $this->novel?->title ?? '小说 #'.$this->subject_id,
            default => $this->subject_type.' #'.$this->subject_id,
        };
    }

    public function valueSummary(): string
    {
        return json_encode(
            $this->value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'value' => 'array',
            'hardness' => FactHardness::class,
            'confidence' => 'float',
            'status' => FactStatus::class,
            'locked' => 'boolean',
            'source_type' => FactSourceType::class,
            'source_event_id' => 'integer',
        ];
    }
}
