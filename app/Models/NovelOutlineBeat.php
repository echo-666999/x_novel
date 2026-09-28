<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableOutlineNode;
use Database\Factories\NovelOutlineBeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 不可变大纲版本中的 Beat、章节预算和出站 Handoff 定义。
 */
#[Fillable([
    'novel_outline_id', 'novel_outline_arc_id', 'beat_key', 'sequence', 'mainline_sequence',
    'title', 'summary', 'chapter_budget_min', 'chapter_budget_max', 'acceptance_criteria',
    'must_include', 'must_not_include', 'character_candidates', 'world_entity_candidates',
    'handoff_next_beat_id', 'handoff_transition_mode', 'handoff_exit_result',
    'handoff_next_trigger', 'handoff_carried_states', 'handoff_open_threads',
    'handoff_required_transition', 'handoff_forbidden_jump',
])]
class NovelOutlineBeat extends Model
{
    use GuardsImmutableOutlineNode;

    /** @use HasFactory<NovelOutlineBeatFactory> */
    use HasFactory;

    /** @return BelongsTo<NovelOutline, $this> 所属的大纲版本头。 */
    public function outline(): BelongsTo
    {
        return $this->belongsTo(NovelOutline::class, 'novel_outline_id');
    }

    /** @return BelongsTo<NovelOutlineArc, $this> 所属的 Arc 定义。 */
    public function arc(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineArc::class, 'novel_outline_arc_id');
    }

    /** @return HasMany<NovelOutlineMilestone, $this> 按顺序读取本 Beat 的 Milestone。 */
    public function milestones(): HasMany
    {
        return $this->hasMany(NovelOutlineMilestone::class)->orderBy('sequence');
    }

    /** @return BelongsTo<NovelOutlineBeat, $this> 读取相邻的下一个 Main Beat。 */
    public function handoffNextBeat(): BelongsTo
    {
        return $this->belongsTo(self::class, 'handoff_next_beat_id');
    }

    /** @return array<string, string> 还原预算、约束、候选和 Handoff 数组。 */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'mainline_sequence' => 'integer',
            'chapter_budget_min' => 'integer',
            'chapter_budget_max' => 'integer',
            'acceptance_criteria' => 'array',
            'must_include' => 'array',
            'must_not_include' => 'array',
            'character_candidates' => 'array',
            'world_entity_candidates' => 'array',
            'handoff_carried_states' => 'array',
            'handoff_open_threads' => 'array',
            'handoff_required_transition' => 'array',
            'handoff_forbidden_jump' => 'array',
        ];
    }
}
