<?php

namespace App\Models;

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use App\Enums\ForeshadowingTimingStatus;
use Database\Factories\ForeshadowingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

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
    'management_history',
])]
class Foreshadowing extends Model
{
    /** @use HasFactory<ForeshadowingFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $foreshadowing): void {
            if ($foreshadowing->status === ForeshadowingStatus::Due
                && (! $foreshadowing->exists || $foreshadowing->isDirty('status'))) {
                throw ValidationException::withMessages([
                    'status' => 'due 是待迁移的旧状态；请选择真实的内容生命周期状态。',
                ]);
            }
        });
    }

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

    public function timingStatus(?int $currentCanonicalChapter): ?ForeshadowingTimingStatus
    {
        return $this->timingStatusForTargetChapter(self::nextChapterSequence($currentCanonicalChapter));
    }

    public function timingStatusForTargetChapter(int $targetChapter): ?ForeshadowingTimingStatus
    {
        return ForeshadowingTimingStatus::forTargetChapter(
            $this->status,
            $this->due_from_chapter,
            $this->due_to_chapter,
            $targetChapter,
        );
    }

    public function isOverdue(?int $currentCanonicalChapter): bool
    {
        return $this->timingStatus($currentCanonicalChapter) === ForeshadowingTimingStatus::Overdue;
    }

    public function isDue(?int $currentCanonicalChapter): bool
    {
        return $this->timingStatus($currentCanonicalChapter) === ForeshadowingTimingStatus::Due;
    }

    public function requiresLegacyStatusMigration(): bool
    {
        return $this->status->isLegacyDue();
    }

    public static function nextChapterSequence(?int $currentCanonicalChapter): int
    {
        return max(0, $currentCanonicalChapter ?? 0) + 1;
    }

    /** @return array<string> */
    public function attentionBadges(?int $currentChapter): array
    {
        $badges = [];

        if ($this->importance === ForeshadowingImportance::Critical) {
            $badges[] = '关键';
        }

        $timing = $this->timingStatus($currentChapter);

        if ($timing === ForeshadowingTimingStatus::Overdue) {
            $badges[] = $timing->getLabel();
        } elseif ($timing === ForeshadowingTimingStatus::Due) {
            $badges[] = $timing->getLabel();
        }

        return $badges;
    }

    public function scopeNonTerminal(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('status'), [
            ForeshadowingStatus::PaidOff->value,
            ForeshadowingStatus::Abandoned->value,
        ]);
    }

    public function scopeWithTimingStatusAt(
        Builder $query,
        ForeshadowingTimingStatus $status,
        ?int $currentCanonicalChapter,
    ): Builder {
        return $query->withTimingStatusForTargetChapter(
            $status,
            self::nextChapterSequence($currentCanonicalChapter),
        );
    }

    public function scopeWithTimingStatusForTargetChapter(
        Builder $query,
        ForeshadowingTimingStatus $status,
        int $targetChapter,
    ): Builder {
        $query->nonTerminal();

        return match ($status) {
            ForeshadowingTimingStatus::Upcoming => $query->where(
                $query->qualifyColumn('due_from_chapter'),
                '>',
                $targetChapter,
            ),
            ForeshadowingTimingStatus::Due => $query
                ->where($query->qualifyColumn('due_from_chapter'), '<=', $targetChapter)
                ->where($query->qualifyColumn('due_to_chapter'), '>=', $targetChapter),
            ForeshadowingTimingStatus::Overdue => $query->where(
                $query->qualifyColumn('due_to_chapter'),
                '<',
                $targetChapter,
            ),
        };
    }

    public function scopeRequiringAttentionForTargetChapter(Builder $query, int $targetChapter): Builder
    {
        return $query->nonTerminal()
            ->where($query->qualifyColumn('due_from_chapter'), '<=', $targetChapter);
    }

    public function scopeRequiringAttentionByNovelProgress(Builder $query): Builder
    {
        return $query->nonTerminal()
            ->whereRaw('due_from_chapter <= COALESCE(current_chapter_sequence, 0) + 1');
    }

    public function scopeOrderByTimingAt(Builder $query, ?int $currentCanonicalChapter): Builder
    {
        $targetChapter = self::nextChapterSequence($currentCanonicalChapter);

        return $query->orderByRaw(
            'CASE
                WHEN status IN (?, ?) THEN 3
                WHEN due_to_chapter < ? THEN 0
                WHEN due_from_chapter <= ? AND due_to_chapter >= ? THEN 1
                ELSE 2
            END',
            [
                ForeshadowingStatus::PaidOff->value,
                ForeshadowingStatus::Abandoned->value,
                $targetChapter,
                $targetChapter,
                $targetChapter,
            ],
        );
    }

    public function scopeOrderByTimingByNovelProgress(Builder $query): Builder
    {
        return $query->orderByRaw(
            'CASE
                WHEN due_to_chapter < COALESCE(current_chapter_sequence, 0) + 1 THEN 0
                WHEN importance = ? THEN 1
                ELSE 2
            END',
            [ForeshadowingImportance::Critical->value],
        );
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
            'management_history' => 'array',
        ];
    }
}
