<?php

namespace App\AI;

use App\AI\Data\AiRequest;
use App\AI\Data\BudgetUsage;
use App\AI\Exceptions\BudgetExceededException;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\UsageRecord;
use InvalidArgumentException;

class BudgetService
{
    public function assertWithinNovelLimits(Novel $novel): void
    {
        $this->assertAvailable($this->dailyUsage());
        $this->assertAvailable($this->novelUsage($novel));
    }

    public function assertWithinChapterLimits(Chapter $chapter): void
    {
        $this->assertWithinNovelLimits($chapter->novel);
        $this->assertAvailable($this->chapterUsage($chapter));
    }

    public function assertCanRequest(AiRequest $request): void
    {
        $this->assertAvailable($this->dailyUsage());

        [$novel, $chapter] = $this->resolveContext($request);

        if ($novel !== null) {
            $this->assertAvailable($this->novelUsage($novel));
        }

        if ($chapter !== null) {
            $this->assertAvailable($this->chapterUsage($chapter, $novel));
        }
    }

    public function dailyUsage(): BudgetUsage
    {
        return new BudgetUsage(
            scope: 'daily',
            used: (float) UsageRecord::query()->whereDate('created_at', today())->sum('estimated_cost'),
            limit: $this->limit(config('ai.budget.daily_hard_limit')),
        );
    }

    public function novelUsage(Novel $novel): BudgetUsage
    {
        return new BudgetUsage(
            scope: 'novel',
            used: (float) UsageRecord::query()->where('novel_id', $novel->getKey())->sum('estimated_cost'),
            limit: $this->novelLimit($novel, 'novel_total_limit'),
        );
    }

    public function chapterUsage(Chapter $chapter, ?Novel $novel = null): BudgetUsage
    {
        $novel ??= $chapter->novel;

        return new BudgetUsage(
            scope: 'chapter',
            used: (float) UsageRecord::query()->where('chapter_id', $chapter->getKey())->sum('estimated_cost'),
            limit: $this->novelLimit($novel, 'chapter_max_cost'),
        );
    }

    private function assertAvailable(BudgetUsage $usage): void
    {
        if ($usage->reached()) {
            throw new BudgetExceededException($usage->scope, $usage->used, $usage->limit);
        }
    }

    /** @return array{0: Novel|null, 1: Chapter|null} */
    private function resolveContext(AiRequest $request): array
    {
        $novelId = $request->metadata['novel_id'] ?? null;
        $chapterId = $request->metadata['chapter_id'] ?? null;
        $novel = is_numeric($novelId) ? Novel::query()->find((int) $novelId) : null;
        $chapter = is_numeric($chapterId) ? Chapter::query()->find((int) $chapterId) : null;

        if (is_numeric($novelId) && $novel === null) {
            throw new InvalidArgumentException('AI request Novel context does not exist.');
        }

        if (is_numeric($chapterId) && $chapter === null) {
            throw new InvalidArgumentException('AI request Chapter context does not exist.');
        }

        if ($chapter !== null) {
            if ($novel !== null && $chapter->novel_id !== $novel->getKey()) {
                throw new InvalidArgumentException('AI request Chapter does not belong to the Novel context.');
            }

            $novel ??= $chapter->novel;
        }

        return [$novel, $chapter];
    }

    private function novelLimit(Novel $novel, string $key): ?float
    {
        $override = data_get($novel->settings, "budget.{$key}");

        return $this->limit(filled($override) ? $override : config("ai.budget.{$key}"));
    }

    private function limit(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }
}
