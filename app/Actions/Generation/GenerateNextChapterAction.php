<?php

namespace App\Actions\Generation;

use App\AI\BudgetService;
use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\VolumeStatus;
use App\Exceptions\GenerationPreflightException;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Volume;
use App\Services\GenerationRunLease;
use App\Services\NarrativeStyleProfile;
use App\Services\StalledRunRecoveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateNextChapterAction
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly NarrativeStyleProfile $narrativeStyleProfile,
        private readonly GenerationRunLease $runLease,
    ) {}

    public function handle(Novel $novel): Chapter
    {
        // 独立提交停滞清理。即使后续发现另一个真正活跃的工作流并拒绝生成，
        // 已确认过期的 Run 也不能随外层事务回滚后继续占用小说。
        $this->markStalledRuns($novel);

        return DB::transaction(function () use ($novel): Chapter {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());

            $this->validateNovel($lockedNovel);

            $volume = $this->currentVolume($lockedNovel);
            $nextSequence = ($lockedNovel->current_chapter_sequence ?? 0) + 1;
            $chapter = Chapter::query()
                ->where('novel_id', $lockedNovel->getKey())
                ->where('sequence', $nextSequence)
                ->lockForUpdate()
                ->first();

            $this->validateWorkflow($lockedNovel, $chapter);
            $this->budgetService->assertWithinNovelLimits($lockedNovel);

            if ($chapter !== null) {
                if ($chapter->status === ChapterStatus::Canonical) {
                    throw GenerationPreflightException::activeWorkflowExists();
                }

                return $chapter;
            }

            return Chapter::query()->create([
                'novel_id' => $lockedNovel->getKey(),
                'volume_id' => $volume->getKey(),
                'sequence' => $nextSequence,
                'title' => "第 {$nextSequence} 章",
                'status' => ChapterStatus::Planned,
                'word_count' => 0,
            ]);
        });
    }

    private function markStalledRuns(Novel $novel): void
    {
        // Scheduler 可能暂时不可用；前置检查必须自行回收当前小说的过期 Run，
        // 否则已经丢失的 Worker 会永久阻止后续章节。只处理 Running，不能误伤仍在排队的任务。
        $novel->generationRuns()
            ->where('status', RunStatus::Running)
            ->whereIn('stage', StalledRunRecoveryService::RECOVERABLE_STAGES)
            ->where('updated_at', '<=', $this->runLease->cutoff())
            ->update([
                'status' => RunStatus::Failed,
                'error_code' => StalledRunRecoveryService::ERROR_CODE,
                'error_message' => 'Worker 心跳超时，Run 已标记为可恢复。',
                'finished_at' => now(),
            ]);
    }

    private function validateNovel(Novel $novel): void
    {
        if ($novel->status === NovelStatus::Paused) {
            throw GenerationPreflightException::paused();
        }

        if (! in_array($novel->status, [NovelStatus::Generating, NovelStatus::Completing], true)) {
            throw GenerationPreflightException::unavailableStatus();
        }

        try {
            $this->narrativeStyleProfile->forNovel($novel);
        } catch (ValidationException $exception) {
            $detail = (string) collect($exception->errors())->flatten()->first();

            throw GenerationPreflightException::bibleIncomplete($detail);
        }

        if ($novel->canonical_state_version_id === null) {
            throw GenerationPreflightException::stateUninitialized();
        }
    }

    private function currentVolume(Novel $novel): Volume
    {
        $volume = $novel->volumes()
            ->where('status', VolumeStatus::Active)
            ->orderBy('sequence')
            ->first();

        if ($volume === null) {
            throw GenerationPreflightException::currentVolumeMissing();
        }

        return $volume;
    }

    private function validateWorkflow(Novel $novel, ?Chapter $targetChapter): void
    {
        if ($novel->chapters()->where('status', ChapterStatus::Blocked)->exists()) {
            throw GenerationPreflightException::blockedReview();
        }

        $activeStatuses = [
            ChapterStatus::Planned,
            ChapterStatus::Generating,
            ChapterStatus::Review,
            ChapterStatus::Rewrite,
        ];

        $hasAnotherActiveChapter = $novel->chapters()
            ->whereIn('status', $activeStatuses)
            ->when($targetChapter !== null, fn ($query) => $query->whereKeyNot($targetChapter->getKey()))
            ->exists();

        $hasAnotherActiveRun = $novel->generationRuns()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->when(
                $targetChapter !== null,
                fn ($query) => $query->where(fn ($query) => $query
                    ->whereNull('chapter_id')
                    ->orWhere('chapter_id', '!=', $targetChapter->getKey())),
            )
            ->exists();

        if ($hasAnotherActiveChapter || $hasAnotherActiveRun) {
            throw GenerationPreflightException::activeWorkflowExists();
        }
    }
}
