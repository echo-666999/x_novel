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
