<?php

namespace App\Actions\Generation;

use App\AI\Exceptions\BudgetExceededException;
use App\Enums\ChapterStatus;
use App\Exceptions\GenerationPreflightException;
use App\Models\Chapter;
use App\Models\Novel;
use App\Services\AutoStopService;
use App\Services\MvpSoakRunService;
use App\Services\NovelLifecycleAdvancer;
use App\Services\ReliabilityRunService;
use App\Services\SmokeRunService;

class CheckNextAction
{
    public function __construct(
        private readonly GenerateNextChapterAction $generateNextChapter,
        private readonly AdvanceChapterPipelineAction $advanceChapterPipeline,
        private readonly AutoStopService $autoStop,
        private readonly NovelLifecycleAdvancer $lifecycle,
        private readonly MvpSoakRunService $mvpSoakRun,
        private readonly ReliabilityRunService $reliabilityRun,
        private readonly SmokeRunService $smokeRun,
    ) {}

    public function handle(Novel $novel, int $committedChapterId): ?Chapter
    {
        $novel->refresh();
        $committedChapter = $novel->chapters()->find($committedChapterId);

        if (! (bool) data_get($novel->settings, 'auto_generate', false)) {
            return null;
        }

        if ($committedChapter?->status !== ChapterStatus::Canonical
            || $committedChapter->sequence !== $novel->current_chapter_sequence) {
            return null;
        }

        if (blank($committedChapter->summary)) {
            $this->autoStop->stop(
                $novel,
                'previous_chapter_summary_missing',
                "第 {$committedChapter->sequence} 章的正式摘要缺失，不能创建下一章。",
                '等待自动恢复服务重新生成正式摘要。',
            );

            return null;
        }

        if ($this->mvpSoakRun->completeIfTargetReached($novel, $committedChapter->sequence)
            || $this->reliabilityRun->completeIfTargetReached($novel, $committedChapter->sequence)
            || $this->smokeRun->completeIfTargetReached($novel, $committedChapter->sequence)) {
            return null;
        }

        try {
            $lifecycle = $this->lifecycle->advance($novel);
            if (! $lifecycle->canContinue) {
                return null;
            }

            $nextChapter = $this->generateNextChapter->handle($novel);
        } catch (BudgetExceededException|GenerationPreflightException $exception) {
            $this->autoStop->stopForPreflight($novel, $exception);

            return null;
        }

        $this->advanceChapterPipeline->handle($nextChapter->getKey());

        return $nextChapter;
    }
}
