<?php

namespace App\Services;

use App\Data\CurrentOutlineTarget;
use App\Enums\ChapterStatus;
use App\Models\Chapter;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A human-authorized, one-chapter exception to an exhausted Outline Beat budget. */
class OutlineBeatBudgetExtension
{
    private const SETTINGS_KEY = 'outline_beat_budget_extensions';

    public function __construct(private readonly OutlineProgressResolver $progress) {}

    public function exhausted(Novel $novel, CurrentOutlineTarget $target, Chapter $chapter): bool
    {
        $maximum = data_get($target->beat, 'chapter_budget.max');

        return is_int($maximum)
            && $target->chaptersUsedForCurrentBeat >= $maximum
            && $this->authorizationFor($novel, $target, $chapter) === null;
    }

    /** @return array<string, mixed>|null */
    public function authorizationFor(Novel $novel, CurrentOutlineTarget $target, Chapter $chapter): ?array
    {
        $maximum = data_get($target->beat, 'chapter_budget.max');
        if (! is_int($maximum) || $target->chaptersUsedForCurrentBeat !== $maximum) {
            return null;
        }

        foreach ((array) data_get($novel->settings, self::SETTINGS_KEY, []) as $authorization) {
            if (! is_array($authorization)) {
                continue;
            }
            if ((int) ($authorization['novel_outline_id'] ?? 0) === $target->outlineId
                && is_string($authorization['outline_checksum'] ?? null)
                && hash_equals($target->outlineChecksum, $authorization['outline_checksum'])
                && (int) ($authorization['outline_beat_id'] ?? 0) === $target->outlineBeatId
                && (int) ($authorization['state_version_id'] ?? 0) === (int) $novel->canonical_state_version_id
                && (int) ($authorization['chapter_id'] ?? 0) === (int) $chapter->getKey()
                && (int) ($authorization['chapter_sequence'] ?? 0) === (int) $chapter->sequence
                && (int) ($authorization['chapters_used_before'] ?? -1) === $maximum
                && (int) ($authorization['budget_max'] ?? -1) === $maximum
                && (int) ($authorization['operator_id'] ?? 0) > 0
                && is_string($authorization['reason'] ?? null)
                && is_string($authorization['source'] ?? null)
                && is_string($authorization['authorized_at'] ?? null)) {
                return [
                    'novel_outline_id' => (int) $authorization['novel_outline_id'],
                    'outline_checksum' => $authorization['outline_checksum'],
                    'outline_beat_id' => (int) $authorization['outline_beat_id'],
                    'state_version_id' => (int) $authorization['state_version_id'],
                    'chapter_id' => (int) $authorization['chapter_id'],
                    'chapter_sequence' => (int) $authorization['chapter_sequence'],
                    'chapters_used_before' => (int) $authorization['chapters_used_before'],
                    'budget_max' => (int) $authorization['budget_max'],
                    'operator_id' => (int) $authorization['operator_id'],
                    'reason' => (string) $authorization['reason'],
                    'source' => (string) $authorization['source'],
                    'authorized_at' => (string) $authorization['authorized_at'],
                ];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function grant(Chapter $chapter, int $operatorId, string $reason, string $source = 'ui'): array
    {
        $reason = trim($reason);
        if ($operatorId <= 0 || ! in_array($source, ['ui', 'codex_user_request'], true)) {
            throw ValidationException::withMessages(['operator' => '预算延期缺少有效操作人或来源。']);
        }
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => '请填写至少 10 字的预算延期原因。']);
        }

        return DB::transaction(function () use ($chapter, $operatorId, $reason, $source): array {
            $novel = Novel::query()->lockForUpdate()->findOrFail($chapter->novel_id);
            $lockedChapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $target = $this->progress->resolve($novel);
            $maximum = $target === null ? null : data_get($target->beat, 'chapter_budget.max');

            $existing = $target === null ? null : $this->authorizationFor($novel, $target, $lockedChapter);
            if ($existing !== null) {
                return $existing;
            }

            if ($target === null || ! is_int($maximum)
                || $target->chaptersUsedForCurrentBeat !== $maximum
                || $lockedChapter->status !== ChapterStatus::Planned
                || $lockedChapter->sequence !== (int) $novel->current_chapter_sequence + 1
                || $lockedChapter->plans()->exists()
                || $novel->canonical_state_version_id === null) {
                throw ValidationException::withMessages(['chapter' => '当前章节、正式状态或 Beat 预算已变化，不能授权延期。']);
            }

            $extensions = (array) data_get($novel->settings, self::SETTINGS_KEY, []);
            foreach ($extensions as $extension) {
                if (is_array($extension)
                    && (int) ($extension['novel_outline_id'] ?? 0) === $target->outlineId
                    && (int) ($extension['outline_beat_id'] ?? 0) === $target->outlineBeatId) {
                    throw ValidationException::withMessages(['chapter' => '当前 Beat 已有一次预算延期，不能再次扩大到其他章节或状态。']);
                }
            }

            $authorization = [
                'novel_outline_id' => $target->outlineId,
                'outline_checksum' => $target->outlineChecksum,
                'outline_beat_id' => $target->outlineBeatId,
                'state_version_id' => (int) $novel->canonical_state_version_id,
                'chapter_id' => (int) $lockedChapter->getKey(),
                'chapter_sequence' => (int) $lockedChapter->sequence,
                'chapters_used_before' => $target->chaptersUsedForCurrentBeat,
                'budget_max' => $maximum,
                'operator_id' => $operatorId,
                'reason' => $reason,
                'source' => $source,
                'authorized_at' => now()->toIso8601String(),
            ];
            $settings = $novel->settings ?? [];
            $settings[self::SETTINGS_KEY] = [...$extensions, $authorization];
            $novel->update(['settings' => $settings]);

            return $authorization;
        }, 3);
    }
}
