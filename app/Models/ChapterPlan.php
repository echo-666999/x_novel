<?php

namespace App\Models;

use App\Enums\PlanStatus;
use Database\Factories\ChapterPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'chapter_id',
    'version',
    'chapter_function',
    'arc_contribution',
    'reader_promise',
    'target_words',
    'pov_character_id',
    'tone',
    'time_anchor',
    'hook_type',
    'must_reveal',
    'may_hint',
    'must_not_reveal',
    'required_facts',
    'forbidden_conflicts',
    'due_foreshadowings',
    'scene_plans',
    'status',
])]
class ChapterPlan extends Model
{
    /** @use HasFactory<ChapterPlanFactory> */
    use HasFactory;

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /** @return BelongsTo<Character, $this> */
    public function povCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'pov_character_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'target_words' => 'integer',
            'pov_character_id' => 'integer',
            'must_reveal' => 'array',
            'may_hint' => 'array',
            'must_not_reveal' => 'array',
            'required_facts' => 'array',
            'forbidden_conflicts' => 'array',
            'due_foreshadowings' => 'array',
            'scene_plans' => 'array',
            'status' => PlanStatus::class,
        ];
    }
}
