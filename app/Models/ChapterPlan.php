<?php

namespace App\Models;

use App\Enums\PlanStatus;
use Database\Factories\ChapterPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 冻结章节生成目标及其 Outline Version、Arc、Beat、Milestone 完整父链。
 */
#[Fillable([
    'chapter_id',
    'novel_outline_id',
    'primary_outline_arc_id',
    'primary_outline_beat_id',
    'primary_outline_milestone_id',
    'version',
    'chapter_function',
    'arc_contribution',
    'arc_contributions',
    'character_candidates',
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
    'world_entity_candidates',
    'scene_plans',
    'checksum',
    'input_hash',
    'admission_snapshot',
    'admitted_at',
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

    /** @return BelongsTo<NovelOutline, $this> */
    public function novelOutline(): BelongsTo
    {
        return $this->belongsTo(NovelOutline::class);
    }

    /** @return BelongsTo<NovelOutlineArc, $this> 计划冻结的 Primary Arc 定义。 */
    public function primaryOutlineArc(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineArc::class, 'primary_outline_arc_id');
    }

    /** @return BelongsTo<NovelOutlineBeat, $this> 计划冻结的 Primary Beat 定义。 */
    public function primaryOutlineBeat(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineBeat::class, 'primary_outline_beat_id');
    }

    /** @return BelongsTo<NovelOutlineMilestone, $this> 计划冻结的 Primary Milestone 定义。 */
    public function primaryOutlineMilestone(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineMilestone::class, 'primary_outline_milestone_id');
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

    /** @return array<string, mixed> 只包含会改变章节执行结果的规范化计划输入。 */
    public function semanticPayload(): array
    {
        return $this->only([
            'novel_outline_id',
            'primary_outline_arc_id',
            'primary_outline_beat_id',
            'primary_outline_milestone_id',
            'chapter_function',
            'arc_contribution',
            'arc_contributions',
            'character_candidates',
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
            'foreshadowing_actions',
            'world_entity_candidates',
            'scene_plans',
        ]);
    }

    public function semanticChecksum(): string
    {
        return hash('sha256', json_encode(
            $this->semanticPayload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'novel_outline_id' => 'integer',
            'primary_outline_arc_id' => 'integer',
            'primary_outline_beat_id' => 'integer',
            'primary_outline_milestone_id' => 'integer',
            'target_words' => 'integer',
            'pov_character_id' => 'integer',
            'must_reveal' => 'array',
            'may_hint' => 'array',
            'must_not_reveal' => 'array',
            'required_facts' => 'array',
            'forbidden_conflicts' => 'array',
            'due_foreshadowings' => 'array',
            'foreshadowing_actions' => 'array',
            'arc_contributions' => 'array',
            'character_candidates' => 'array',
            'world_entity_candidates' => 'array',
            'scene_plans' => 'array',
            'admission_snapshot' => 'array',
            'admitted_at' => 'datetime',
            'status' => PlanStatus::class,
        ];
    }
}
