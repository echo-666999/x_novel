<?php

namespace App\Models;

use App\Enums\NovelStatus;
use Database\Factories\NovelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'title',
    'genre',
    'premise',
    'target_words',
    'status',
    'current_chapter_sequence',
    'settings',
])]
class Novel extends Model
{
    /** @use HasFactory<NovelFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_words' => 'integer',
            'status' => NovelStatus::class,
            'current_chapter_sequence' => 'integer',
            'settings' => 'array',
        ];
    }
}
