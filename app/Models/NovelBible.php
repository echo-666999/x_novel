<?php

namespace App\Models;

use App\Enums\BibleStatus;
use Database\Factories\NovelBibleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'novel_id',
    'version',
    'logline',
    'themes',
    'tone',
    'pov',
    'tense',
    'taboos',
    'hard_constraints',
    'ending_contract',
    'style_profile',
    'status',
])]
class NovelBible extends Model
{
    /** @use HasFactory<NovelBibleFactory> */
    use HasFactory;

    private const IMMUTABLE_ATTRIBUTES = [
        'novel_id',
        'version',
        'logline',
        'themes',
        'tone',
        'pov',
        'tense',
        'taboos',
        'hard_constraints',
        'ending_contract',
        'style_profile',
    ];

    protected static function booted(): void
    {
        static::updating(function (NovelBible $bible): void {
            if ($bible->isDirty(self::IMMUTABLE_ATTRIBUTES)) {
                throw new LogicException('小说圣经版本内容不可原地修改，请创建新版本。');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('小说圣经版本不可删除。');
        });
    }

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'themes' => 'array',
            'taboos' => 'array',
            'hard_constraints' => 'array',
            'ending_contract' => 'array',
            'style_profile' => 'array',
            'status' => BibleStatus::class,
        ];
    }
}
