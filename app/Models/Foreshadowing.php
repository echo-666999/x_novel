<?php

namespace App\Models;

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use Database\Factories\ForeshadowingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'novel_id',
    'title',
    'description',
    'setup_chapter_id',
    'promised_payoff',
    'due_from_chapter',
    'due_to_chapter',
    'importance',
    'status',
    'owner_arc_id',
    'reinforce_count',
    'payoff_chapter_id',
    'notes',
])]
class Foreshadowing extends Model
{
    /** @use HasFactory<ForeshadowingFactory> */
    use HasFactory;

    /** @return BelongsTo<Novel, $this> */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** @return BelongsTo<StoryArc, $this> */
    public function ownerArc(): BelongsTo
    {
        return $this->belongsTo(StoryArc::class, 'owner_arc_id');
    }

    public function isOverdue(?int $currentChapter): bool
    {
        return ! $this->status->isTerminal()
            && $currentChapter !== null
            && $currentChapter > $this->due_to_chapter;
    }

    public function isDue(?int $currentChapter): bool
    {
        if ($this->status->isTerminal() || $this->isOverdue($currentChapter)) {
            return false;
        }

        return $this->status === ForeshadowingStatus::Due
            || ($currentChapter !== null
                && $currentChapter >= $this->due_from_chapter
                && $currentChapter <= $this->due_to_chapter);
    }

    /** @return array<string> */
    public function attentionBadges(?int $currentChapter): array
    {
        $badges = [];

        if ($this->importance === ForeshadowingImportance::Critical) {
            $badges[] = 'Critical';
        }

        if ($this->isOverdue($currentChapter)) {
            $badges[] = 'Overdue';
        } elseif ($this->isDue($currentChapter)) {
            $badges[] = 'Due';
        }

        return $badges;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'setup_chapter_id' => 'integer',
            'due_from_chapter' => 'integer',
            'due_to_chapter' => 'integer',
            'importance' => ForeshadowingImportance::class,
            'status' => ForeshadowingStatus::class,
            'reinforce_count' => 'integer',
            'payoff_chapter_id' => 'integer',
        ];
    }
}
