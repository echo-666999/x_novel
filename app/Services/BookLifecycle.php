<?php

namespace App\Services;

use App\Data\CanonicalCommitData;
use App\Enums\ChapterStatus;
use App\Jobs\UpdateMemoryJob;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

/** 正式提交后的最小生命周期：摘要是续章前置，模型请求仍由统一 Run 执行。 */
final class BookLifecycle
{
    /** PASS 只提供提交资格；人工模式必须显式提交当前版本。 */
    public function reviewed(NovelGenerationTask $task, Review $review): ?GenerationRun
    {
        if ($task->mode === 'manual_review') {
            $task->update(['status' => 'paused', 'current_phase' => 'commit', 'stop_reason' => 'awaiting_manual_commit', 'control_version' => $task->control_version + 1,
                'pending_transition' => ['stage' => 'book_control', 'review_ready_id' => $review->id], 'stop_detail' => ['message' => '当前稿已通过独立审校，等待你提交。', 'review_id' => $review->id]]);

            return null;
        }
        $this->commit($task, $review);

        // 必须先提交正式事务，再准备摘要；摘要配置故障不能撤销已通过审校的正文。
        return null;
    }

    /** 人工提交与暂停采用相同锁序和控制版本，重复点击不提交另一稿。 */
    public function submit(int $taskId, int $version, int $reviewId): void
    {
        DB::transaction(function () use ($taskId, $version, $reviewId): void {
            $initial = NovelGenerationTask::findOrFail($taskId);
            Novel::whereKey($initial->novel_id)->lockForUpdate()->firstOrFail();
            $task = NovelGenerationTask::whereKey($taskId)->lockForUpdate()->firstOrFail();
            if ($task->control_version !== $version || $task->status !== 'paused' || $task->stop_reason !== 'awaiting_manual_commit' || (int) data_get($task->pending_transition, 'review_ready_id') !== $reviewId) {
                throw new \LogicException('提交预览已过期，请核对当前稿和任务状态。');
            }
            $task->update(['status' => 'running', 'stop_reason' => null, 'stop_detail' => null]);
            $this->commit($task, Review::findOrFail($reviewId));
        });
        app(NovelBookWorkflow::class)->advance($taskId);
    }

    /** 唯一正式写入入口，不在 UI 或后继任务复制事件/状态提交逻辑。 */
    private function commit(NovelGenerationTask $task, Review $review): void
    {
        $r = $review->artifact->data;
        $draft = GenerationArtifact::findOrFail($r['source_artifact_id']);
        app(CanonicalCommitService::class)->commit(new CanonicalCommitData($review->generationRun->chapter_id, $draft->id, $review->id,
            $r['event_artifact_id'], $r['patch_artifact_id'], $task->novel->fresh()->canonicalStateVersion->version, $draft->checksum, $task->id, $task->control_version));
    }

    /** 摘要只读取正式版本；提交成功后的任何中断均可复用此确定性入口。 */
    public function summary(NovelGenerationTask $task, Chapter $chapter): ?GenerationRun
    {
        $chapter->refresh();
        if ($chapter->status !== ChapterStatus::Canonical || ! $chapter->canonical_artifact_id) {
            throw new \LogicException('摘要来源已不是正式章节。');
        }
        if ($chapter->summary_artifact_id) {
            return $this->boundary($task);
        }
        $draft = $chapter->canonicalArtifact;

        return app(NovelStageContract::class)->enqueue($task, 'summary', ['body' => $draft->content, 'paragraph_index' => $draft->data['paragraph_index'],
            'events' => $chapter->storyEvents()->active()->get(['id', 'event_type', 'payload', 'evidence'])->toArray(), 'state' => $task->novel->fresh()->canonicalStateVersion->state],
            [['artifact_id' => $draft->id, 'checksum' => $draft->checksum, 'chapter_id' => $chapter->id, 'pointer' => 'canonical_artifact_id']],
            ['chapter_id' => $chapter->id, 'canonical_artifact_id' => $draft->id]);
    }

    /** 摘要条目逐一引用正式原文；文本是记忆材料，不能覆盖结构化状态。 */
    public function validateSummary(GenerationRun $run, array $data): array
    {
        $draft = GenerationArtifact::findOrFail($run->execution_contract['canonical_artifact_id']);
        foreach ($data['points'] as $point) {
            app(ChapterDraftService::class)->evidence($draft, $point['evidence']);
        }

        return [...$data, 'source_artifact_id' => $draft->id, 'source_checksum' => $draft->checksum];
    }

    /** 摘要采用与章节边界意图同事务；派生记忆失败不倒置已提交状态。 */
    public function summarized(NovelGenerationTask $task, GenerationRun $run): ?GenerationRun
    {
        $artifact = GenerationArtifact::findOrFail($run->result_artifact_id);
        $chapter = Chapter::findOrFail($run->chapter_id);
        if ($chapter->canonical_artifact_id !== $artifact->data['source_artifact_id']) {
            throw new \LogicException('摘要的正式正文来源已经变化。');
        }
        $chapter->update(['summary' => implode("\n", array_column($artifact->data['points'], 'text')), 'summary_artifact_id' => $artifact->id]);
        $task->update(['pending_transition' => ['stage' => 'book_control', 'chapter_boundary' => true]]);
        // 仅确定性写记忆，不在小说锁内调用 Embedding；独立补偿由派生队列负责。
        UpdateMemoryJob::dispatch($chapter->id)->afterCommit();

        return $this->boundary($task->fresh());
    }

    /** 每次只决定一个下一章；达到目标后进入结局核验而非按字数假完结。 */
    public function boundary(NovelGenerationTask $task): ?GenerationRun
    {
        if (app(NovelBookWorkflow::class)->chapterBoundary($task->id) !== 'continue') {
            return null;
        }
        $novel = $task->novel->fresh();
        $words = (int) $novel->chapters()->where('status', 'canonical')->sum('word_count');
        if ($words >= $task->target_words) {
            return app(BookEnding::class)->enqueue($task);
        }
        $novel->update(['status' => $words >= $task->target_words * (float) config('generation.book.closing_ratio', .8) ? 'completing' : 'generating']);

        return app(NovelChapterPipeline::class)->start($task->fresh());
    }
}
