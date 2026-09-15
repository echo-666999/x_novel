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
    'foreshadowing_actions',
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

    /** @return array<int, array<string, mixed>> */
    public function foreshadowingActionContracts(): array
    {
        return array_values(array_filter(
            $this->foreshadowing_actions ?? [],
            fn (mixed $action): bool => is_array($action),
        ));
    }

    /** @return array<int, int> */
    public function legacyForeshadowingIds(): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_filter(
                $this->due_foreshadowings ?? [],
                fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)),
            ),
        )));
    }

    /** @return array<int, int> */
    public function referencedForeshadowingIds(): array
    {
        $actionIds = array_map(
            fn (array $action): int => (int) ($action['foreshadowing_id'] ?? 0),
            $this->foreshadowingActionContracts(),
        );

        return array_values(array_unique(array_filter([
            ...$this->legacyForeshadowingIds(),
            ...$actionIds,
        ])));
    }

    public function hasLegacyForeshadowingReferences(): bool
    {
        return $this->legacyForeshadowingIds() !== [];
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
            'foreshadowing_actions' => 'array',
            'scene_plans' => 'array',
            'status' => PlanStatus::class,
        ];
    }
}
