<?php

namespace App\Actions\Generation;

use App\AI\Exceptions\BudgetExceededException;
use App\Enums\ChapterStatus;
use App\Exceptions\GenerationPreflightException;
use App\Jobs\PlanChapterJob;
use App\Models\Chapter;
use App\Models\Novel;
use App\Services\AutoStopService;
use App\Services\ReliabilityRunService;
use App\Services\SmokeRunService;

class CheckNextAction
{
    public function __construct(
        private readonly GenerateNextChapterAction $generateNextChapter,
        private readonly AutoStopService $autoStop,
        private readonly ReliabilityRunService $reliabilityRun,
        private readonly SmokeRunService $smokeRun,
    ) {}

    public function handle(Novel $novel, int $committedChapterId): ?Chapter
    {
        $novel->refresh();
        $committedChapter = $novel->chapters()->find($committedChapterId);

        if (! (bool) data_get($novel->settings, 'auto_generate', false)
            || $committedChapter?->status !== ChapterStatus::Canonical
            || $committedChapter->sequence !== $novel->current_chapter_sequence) {
            return null;
        }

        if ($this->reliabilityRun->completeIfTargetReached($novel, $committedChapter->sequence)
            || $this->smokeRun->completeIfTargetReached($novel, $committedChapter->sequence)) {
            return null;
        }

        try {
            $nextChapter = $this->generateNextChapter->handle($novel);
        } catch (BudgetExceededException|GenerationPreflightException $exception) {
            $this->autoStop->stopForPreflight($novel, $exception);

            return null;
        }

        if ($nextChapter->wasRecentlyCreated) {
            PlanChapterJob::dispatch($nextChapter->getKey())->afterCommit();
        }

        return $nextChapter;
    }
}
