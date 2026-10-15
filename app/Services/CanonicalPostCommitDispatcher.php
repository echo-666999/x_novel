<?php

namespace App\Services;

use App\Jobs\ContinueAutoGenerationJob;
use App\Jobs\GenerateCanonicalChapterSummaryJob;
use App\Jobs\RefreshNovelProjectionJob;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\StoryStateVersion;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

/**
 * 从 PostgreSQL 的 Canonical 指针重建可重复派发的提交后任务链。
 */
final class CanonicalPostCommitDispatcher
{
    public function dispatch(Chapter $chapter, StoryStateVersion $stateVersion, ?int $restartSourceRunId = null): void
    {
        // 先派发独立副作用。若进程随后在必需链入队前退出，缺少摘要仍是可确定识别的恢复点。
        $this->dispatchSideEffects($chapter, $stateVersion);
        $this->dispatchRequired($chapter, $stateVersion, $restartSourceRunId);
    }

    /** 摘要是下一章的唯一提交后硬前置。 */
    public function dispatchRequired(Chapter $chapter, StoryStateVersion $stateVersion, ?int $restartSourceRunId = null): void
    {
        $this->assertCurrent($chapter, $stateVersion);

        Bus::chain([
            new GenerateCanonicalChapterSummaryJob($chapter->getKey(), (int) $chapter->canonical_artifact_id, $restartSourceRunId),
            new ContinueAutoGenerationJob($chapter->getKey(), (int) $chapter->canonical_artifact_id, $stateVersion->getKey()),
        ])->onQueue('default')->dispatch();
    }

    /** 记忆和投影可以独立失败、重试和恢复，不能截断正式摘要与续章。 */
    public function dispatchSideEffects(Chapter $chapter, StoryStateVersion $stateVersion): void
    {
        $this->assertCurrent($chapter, $stateVersion);

        UpdateMemoryJob::dispatch($chapter->getKey());
        RefreshNovelProjectionJob::dispatch($chapter->novel_id, $stateVersion->getKey());
    }

    private function assertCurrent(Chapter $chapter, StoryStateVersion $stateVersion): void
    {
        if ($chapter->canonical_artifact_id === null
            || $stateVersion->chapter_id !== $chapter->getKey()
            || (int) $chapter->novel()->value('canonical_state_version_id') !== $stateVersion->getKey()) {
            throw ValidationException::withMessages([
                'post_commit' => 'Canonical Chapter、Artifact 与当前 Story State Version 来源不一致。',
            ]);
        }
    }
}
