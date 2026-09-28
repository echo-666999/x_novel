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
    public function dispatch(Chapter $chapter, StoryStateVersion $stateVersion): void
    {
        if ($chapter->canonical_artifact_id === null
            || $stateVersion->chapter_id !== $chapter->getKey()
            || (int) $chapter->novel()->value('canonical_state_version_id') !== $stateVersion->getKey()) {
            throw ValidationException::withMessages([
                'post_commit' => 'Canonical Chapter、Artifact 与当前 Story State Version 来源不一致。',
            ]);
        }

        Bus::chain([
            new UpdateMemoryJob($chapter->getKey()),
            new GenerateCanonicalChapterSummaryJob($chapter->getKey(), (int) $chapter->canonical_artifact_id),
            new RefreshNovelProjectionJob($chapter->novel_id, $stateVersion->getKey()),
            new ContinueAutoGenerationJob($chapter->getKey(), (int) $chapter->canonical_artifact_id, $stateVersion->getKey()),
        ])->onQueue('default')->dispatch();
    }
}
