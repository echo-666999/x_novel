<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableOutlineNode;
use Database\Factories\NovelOutlineMilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 不可变 Main Beat 中可逐章验收的 Milestone 定义。
 */
#[Fillable(['novel_outline_id', 'novel_outline_beat_id', 'milestone_key', 'sequence', 'title', 'objective', 'acceptance_criteria', 'must_include', 'must_not_include'])]
class NovelOutlineMilestone extends Model
{
    use GuardsImmutableOutlineNode;

    /** @use HasFactory<NovelOutlineMilestoneFactory> */
    use HasFactory;

    /** @return BelongsTo<NovelOutline, $this> 所属的大纲版本头。 */
    public function outline(): BelongsTo
    {
        return $this->belongsTo(NovelOutline::class, 'novel_outline_id');
    }

    /** @return BelongsTo<NovelOutlineBeat, $this> 所属的 Main Beat。 */
    public function beat(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineBeat::class, 'novel_outline_beat_id');
    }

    /** @return array<string, string> 还原顺序、验收条件和约束数组。 */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'acceptance_criteria' => 'array',
            'must_include' => 'array',
            'must_not_include' => 'array',
        ];
    }
}
