<?php

namespace App\Models;

use App\Enums\StoryArcType;
use App\Models\Concerns\GuardsImmutableOutlineNode;
use Database\Factories\NovelOutlineArcFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 不可变大纲版本中的故事线定义。
 */
#[Fillable(['novel_outline_id', 'novel_outline_volume_id', 'arc_key', 'sequence', 'mainline_sequence', 'type', 'title', 'goal', 'stakes', 'completion_conditions'])]
class NovelOutlineArc extends Model
{
    use GuardsImmutableOutlineNode;

    /** @use HasFactory<NovelOutlineArcFactory> */
    use HasFactory;

    /** @return BelongsTo<NovelOutline, $this> 所属的大纲版本头。 */
    public function outline(): BelongsTo
    {
        return $this->belongsTo(NovelOutline::class, 'novel_outline_id');
    }

    /** @return BelongsTo<NovelOutlineVolume, $this> 所属的卷级定义。 */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineVolume::class, 'novel_outline_volume_id');
    }

    /** @return HasMany<NovelOutlineBeat, $this> 按顺序读取本 Arc 的 Beat 定义。 */
    public function beats(): HasMany
    {
        return $this->hasMany(NovelOutlineBeat::class)->orderBy('sequence');
    }

    /** @return array<string, string> 还原枚举、顺序和完成条件类型。 */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'mainline_sequence' => 'integer',
            'type' => StoryArcType::class,
            'completion_conditions' => 'array',
        ];
    }
}
