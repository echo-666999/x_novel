<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableOutlineNode;
use Database\Factories\NovelOutlineVolumeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 不可变大纲版本中的卷级定义。
 */
#[Fillable(['novel_outline_id', 'volume_key', 'sequence', 'title', 'goal', 'climax', 'target_words'])]
class NovelOutlineVolume extends Model
{
    use GuardsImmutableOutlineNode;

    /** @use HasFactory<NovelOutlineVolumeFactory> */
    use HasFactory;

    /** @return BelongsTo<NovelOutline, $this> 所属的大纲版本头。 */
    public function outline(): BelongsTo
    {
        return $this->belongsTo(NovelOutline::class, 'novel_outline_id');
    }

    /** @return HasMany<NovelOutlineArc, $this> 按顺序读取本卷的 Arc 定义。 */
    public function arcs(): HasMany
    {
        return $this->hasMany(NovelOutlineArc::class)->orderBy('sequence');
    }

    /** @return array<string, string> 将顺序和目标字数还原为整数。 */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'target_words' => 'integer'];
    }
}
